<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Academic\Enum\DomaineMatiere;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Grading\Entity\Evaluation;
use App\Grading\Entity\Trimestre;
use App\Grading\Enum\TypeEvaluation;
use App\Grading\Repository\EvaluationRepository;
use App\Grading\Repository\MoyenneManuelleRepository;
use App\Grading\Repository\NoteRepository;
use App\Grading\Service\Dto\BilanClasse;
use App\Grading\Service\Dto\BilanDomaineEleve;
use App\Grading\Service\Dto\ClassementClasse;
use App\Grading\Service\Dto\ClassementEleve;
use App\Grading\Service\Dto\MoyenneEleve;
use App\Grading\Service\Dto\MoyenneMatiereEleve;
use App\Scheduling\Entity\Attribution;
use App\Student\Repository\InscriptionRepository;

/**
 * Calcule, pour une classe et un trimestre, la moyenne de chaque élève dans chaque
 * matière puis sa moyenne générale, et en déduit le classement de la classe.
 *
 * Une évaluation qui existe compte pour tous les élèves de la classe : l'élève est
 * censé y avoir une note. Une note non saisie (case laissée vide, sans marquer
 * l'élève absent) compte donc 0 dans le calcul — elle n'est PAS ignorée. Seule une
 * note explicitement marquée absente (Note::absent) est exclue du calcul et son
 * poids renormalisé sur le reste, comme une absence justifiée. À chaque étage
 * (interrogation/devoir/composition puis matières), les moyennes sont pondérées
 * puis renormalisées sur les seules composantes présentes : une matière sans
 * composition n'est pas pénalisée par le poids de la composition.
 *
 * Une matière sans aucune Evaluation ce trimestre, ou dont aucune évaluation n'a
 * jamais été réellement renseignée (fiche créée mais jamais enregistrée avec au
 * moins une note ou une absence — voir NoteRepository::existeNoteRenseignee),
 * n'apparaît pas du tout dans le résultat — ni dans les colonnes, ni dans le total
 * des coefficients. Objectif : ne pas afficher de faux zéros sur une matière que
 * personne n'a encore commencé à noter (ex. Formation Humaine et Religieuse,
 * Dessin, Bibliothèque, ou une évaluation tout juste créée).
 *
 * Pure lecture, aucune écriture en base — réutilisé tel quel par BulletinGenerator
 * (snapshot des bulletins).
 */
final class MoyenneCalculator
{
    public function __construct(
        private readonly InscriptionRepository $inscriptionRepo,
        private readonly EvaluationRepository $evaluationRepo,
        private readonly NoteRepository $noteRepo,
        private readonly MatiereNiveauRepository $matiereNiveauRepo,
        private readonly MoyenneManuelleRepository $moyenneManuelleRepo,
    ) {
    }

    public function calculer(Classe $classe, Trimestre $trimestre): ClassementClasse
    {
        $inscriptions = $this->inscriptionRepo->findActivesByClasse($classe);

        $matieresParId          = [];
        $moyennesMatiereParEleve = [];
        foreach ($inscriptions as $inscription) {
            $moyennesMatiereParEleve[$inscription->getEleve()->getId()] = [];
        }

        foreach ($classe->getAttributions() as $attribution) {
            $matiere     = $attribution->getMatiere();
            $evaluations = $this->evaluationRepo->findByAttributionEtTrimestre($attribution, $trimestre);

            if ($evaluations === [] || !$this->noteRepo->existeNoteRenseignee(array_map(
                static fn (Evaluation $e): int => $e->getId(),
                $evaluations,
            ))) {
                continue; // matière enseignée mais jamais réellement notée ce trimestre : absente du bulletin
            }

            $matieresParId[$matiere->getId()] = $matiere;

            foreach ($this->calculerPourAttribution($attribution, $trimestre) as $eleveId => $moyenneMatiereEleve) {
                $moyennesMatiereParEleve[$eleveId][$matiere->getId()] = $moyenneMatiereEleve;
            }
        }

        $matieres = array_values($matieresParId);
        usort($matieres, static fn (Matiere $a, Matiere $b): int => $a->getNom() <=> $b->getNom());

        $moyennesEleve = [];
        foreach ($inscriptions as $inscription) {
            $eleve              = $inscription->getEleve();
            $moyennesParMatiere = $moyennesMatiereParEleve[$eleve->getId()];
            $moyenneGenerale    = $this->moyennePonderee(array_map(
                static fn (MoyenneMatiereEleve $m): array => [$m->moyenne, $m->coefficient],
                array_values($moyennesParMatiere),
            ));

            $moyennesEleve[] = new MoyenneEleve(
                $eleve,
                $moyennesParMatiere,
                $moyenneGenerale,
                $this->bilansDomaine($moyennesParMatiere),
            );
        }

        return new ClassementClasse(
            $classe,
            $trimestre,
            $matieres,
            $this->classer($moyennesEleve),
            $this->bilanClasse($moyennesEleve),
        );
    }

    /**
     * Même calcul que dans {@see calculer()}, mais pour une seule matière (une Attribution),
     * rang inclus (rang de chaque élève dans cette matière, parmi sa classe). Réutilisé par
     * `calculer()` pour chaque attribution, et directement par la fiche de notes en ligne
     * qui affiche Moy Interro/Moy Devoir/Compos/Moy /20/Rang pendant la saisie.
     *
     * @return array<int, MoyenneMatiereEleve> indexé par Eleve::getId()
     */
    public function calculerPourAttribution(Attribution $attribution, Trimestre $trimestre): array
    {
        $classe       = $attribution->getClasse();
        $matiere      = $attribution->getMatiere();
        $inscriptions = $this->inscriptionRepo->findActivesByClasse($classe);
        $annee        = $classe->getAnneeScolaire();
        $evaluations  = $this->evaluationRepo->findByAttributionEtTrimestre($attribution, $trimestre);

        $interrogations = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::INTERROGATION));
        $devoirs        = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::DEVOIR));
        $compositions   = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::COMPOSITION));

        $notesParEvaluationId = [];
        foreach ($evaluations as $evaluation) {
            $notesParEvaluationId[$evaluation->getId()] = $this->noteRepo->findByEvaluationIndexeesParEleve($evaluation);
        }

        $surchargesParEleve = $this->moyenneManuelleRepo->findByAttributionEtTrimestreIndexeesParEleve($attribution, $trimestre);

        $coefficientMatiere = $this->matiereNiveauRepo
            ->findOneByMatiereEtNiveau($matiere, $classe->getNiveau())
            ?->getCoefficient() ?? '1.00';

        $enseignantNom = $attribution->getEnseignant()->getNomComplet();

        $donneesParEleve = [];
        foreach ($inscriptions as $inscription) {
            $eleveId  = $inscription->getEleve()->getId();
            $surcharge = $surchargesParEleve[$eleveId] ?? null;

            $moyenneInterrogation = $surcharge?->getMoyenneInterrogation() ?? $this->sousMoyenne($interrogations, $notesParEvaluationId, $eleveId);
            $moyenneDevoirs       = $surcharge?->getMoyenneDevoirs() ?? $this->sousMoyenne($devoirs, $notesParEvaluationId, $eleveId);
            $moyenneComposition   = $this->sousMoyenne($compositions, $notesParEvaluationId, $eleveId);
            $moyenne              = $this->moyennePonderee([
                [$moyenneInterrogation, $annee->getPoidsInterrogation()],
                [$moyenneDevoirs, $annee->getPoidsDevoirs()],
                [$moyenneComposition, $annee->getPoidsComposition()],
            ]);

            $donneesParEleve[$eleveId] = [$moyenneInterrogation, $moyenneDevoirs, $moyenneComposition, $moyenne];
        }

        $rangsParEleveId = $this->rangParValeur(array_map(static fn (array $d): ?string => $d[3], $donneesParEleve));

        $resultat = [];
        foreach ($donneesParEleve as $eleveId => [$interrogation, $devoirsMoy, $composition, $moyenne]) {
            $resultat[$eleveId] = new MoyenneMatiereEleve(
                $matiere,
                $interrogation,
                $devoirsMoy,
                $composition,
                $moyenne,
                $coefficientMatiere,
                $rangsParEleveId[$eleveId] ?? null,
                $enseignantNom,
            );
        }

        return $resultat;
    }

    /**
     * Moyenne pondérée des évaluations d'un même type (interrogation, devoir ou
     * composition) pour un élève. Une évaluation dont la fiche a déjà été enregistrée au
     * moins une fois compte pour tous les élèves : l'élève est censé y avoir une note, donc
     * une note non saisie compte 0 — seule une note marquée absente est exclue du calcul,
     * poids renormalisé sur le reste. Une évaluation dont la fiche n'a jamais été
     * enregistrée (aucune ligne Note du tout — colonne fraîchement créée, personne notée)
     * est en revanche ignorée, pas comptée à 0 : elle n'est simplement pas encore évaluée.
     * Null si aucune évaluation exploitable. Public : réutilisé par la fiche de notes en
     * ligne pour afficher, à titre indicatif (placeholder), le calcul automatique à côté de
     * la surcharge manuelle de Moy Interro/Moy Devoir (voir MoyenneManuelle) sans que
     * celle-ci le masque.
     *
     * @param Evaluation[] $evaluations
     * @param array<int, array<int, \App\Grading\Entity\Note>> $notesParEvaluationId indexé par Evaluation::getId() puis Eleve::getId()
     */
    public function sousMoyenne(array $evaluations, array $notesParEvaluationId, int $eleveId): ?string
    {
        $paires = [];
        foreach ($evaluations as $evaluation) {
            $notesDeLEvaluation = $notesParEvaluationId[$evaluation->getId()] ?? [];
            if (!$this->auMoinsUneNoteReelle($notesDeLEvaluation)) {
                continue; // personne noté sur cette évaluation : pas encore évaluée, pas de faux zéro
            }

            $note = $notesDeLEvaluation[$eleveId] ?? null;
            if ($note !== null && $note->isAbsent()) {
                continue;
            }
            $valeur   = $note?->getValeur() ?? '0.00';
            $paires[] = [$valeur, $evaluation->getCoefficient()];
        }

        return $this->moyennePonderee($paires);
    }

    /** @param array<int, \App\Grading\Entity\Note> $notes */
    private function auMoinsUneNoteReelle(array $notes): bool
    {
        foreach ($notes as $note) {
            if ($note->getValeur() !== null || $note->isAbsent()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Moyenne pondérée générique, renormalisée sur les seules paires dont la valeur
     * n'est pas null (leur poids ne compte alors pas dans le dénominateur non plus).
     *
     * @param array<int, array{0: ?string, 1: string}> $paires [valeur, poids][]
     */
    private function moyennePonderee(array $paires): ?string
    {
        $sommePonderee = 0.0;
        $sommePoids    = 0.0;
        foreach ($paires as [$valeur, $poids]) {
            if ($valeur === null) {
                continue;
            }
            $p = (float) $poids;
            $sommePonderee += (float) $valeur * $p;
            $sommePoids    += $p;
        }

        return $sommePoids > 0.0 ? number_format($sommePonderee / $sommePoids, 2, '.', '') : null;
    }

    /**
     * Bilan par domaine (Scientifique/Littéraire/Autre) d'un élève : moyenne pondérée par
     * coefficient des matières évaluées de ce domaine. Toujours une entrée par domaine
     * (même à moyenne null si aucune matière évaluée dans ce domaine) — le bulletin affiche
     * systématiquement les 3 lignes "Bilan lettres/Sciences/Autres".
     *
     * @param array<int, MoyenneMatiereEleve> $moyennesParMatiere
     * @return BilanDomaineEleve[]
     */
    private function bilansDomaine(array $moyennesParMatiere): array
    {
        $parDomaine = [];
        foreach ($moyennesParMatiere as $moyenneMatiere) {
            $domaine = $moyenneMatiere->matiere->getDomaine();
            if ($domaine === null) {
                continue;
            }
            $parDomaine[$domaine->value][] = $moyenneMatiere;
        }

        $bilans = [];
        foreach (DomaineMatiere::cases() as $domaine) {
            $matieresDuDomaine = $parDomaine[$domaine->value] ?? [];
            $moyenne           = $this->moyennePonderee(array_map(
                static fn (MoyenneMatiereEleve $m): array => [$m->moyenne, $m->coefficient],
                $matieresDuDomaine,
            ));
            $bilans[] = new BilanDomaineEleve($domaine, $moyenne);
        }

        return $bilans;
    }

    /** @param MoyenneEleve[] $moyennesEleve */
    private function bilanClasse(array $moyennesEleve): BilanClasse
    {
        $moyennesNotees = array_values(array_filter(array_map(
            static fn (MoyenneEleve $m): ?string => $m->moyenneGenerale,
            $moyennesEleve,
        )));

        if ($moyennesNotees === []) {
            return new BilanClasse(null, null, null);
        }

        $valeurs = array_map('floatval', $moyennesNotees);

        return new BilanClasse(
            number_format(min($valeurs), 2, '.', ''),
            number_format(max($valeurs), 2, '.', ''),
            number_format(array_sum($valeurs) / count($valeurs), 2, '.', ''),
        );
    }

    /**
     * Classement compétition standard (1,2,2,4 — pas 1,2,2,3) sur des valeurs génériques
     * (moyenne générale ou moyenne de matière) : les valeurs null sont non classées.
     *
     * @param array<int, ?string> $valeursParEleveId
     * @return array<int, ?int> rang par eleveId
     */
    private function rangParValeur(array $valeursParEleveId): array
    {
        $notes = array_filter($valeursParEleveId, static fn (?string $v): bool => $v !== null);
        arsort($notes, SORT_NUMERIC);

        $rangs             = [];
        $rangPrecedent     = null;
        $valeurPrecedente  = null;
        $index             = 0;
        foreach ($notes as $eleveId => $valeur) {
            $rang = ($valeurPrecedente !== null && $valeur === $valeurPrecedente) ? $rangPrecedent : $index + 1;
            $rangs[$eleveId]  = $rang;
            $rangPrecedent    = $rang;
            $valeurPrecedente = $valeur;
            $index++;
        }

        foreach ($valeursParEleveId as $eleveId => $valeur) {
            if ($valeur === null) {
                $rangs[$eleveId] = null;
            }
        }

        return $rangs;
    }

    /**
     * Classement compétition standard (1,2,2,4 — pas 1,2,2,3) : les élèves non notés
     * (moyenneGenerale null) sont non classés, placés à la suite dans leur ordre
     * d'origine (nom/prénom, hérité de InscriptionRepository::findActivesByClasse).
     *
     * @param MoyenneEleve[] $moyennesEleve
     * @return ClassementEleve[]
     */
    private function classer(array $moyennesEleve): array
    {
        $notes    = array_values(array_filter($moyennesEleve, static fn (MoyenneEleve $m): bool => $m->estNotee()));
        $nonNotes = array_values(array_filter($moyennesEleve, static fn (MoyenneEleve $m): bool => !$m->estNotee()));

        usort($notes, static fn (MoyenneEleve $a, MoyenneEleve $b): int => (float) $b->moyenneGenerale <=> (float) $a->moyenneGenerale);

        $classement          = [];
        $rangPrecedent        = null;
        $moyennePrecedente    = null;
        foreach ($notes as $index => $moyenneEleve) {
            $rang = ($moyennePrecedente !== null && $moyenneEleve->moyenneGenerale === $moyennePrecedente)
                ? $rangPrecedent
                : $index + 1;

            $classement[]      = new ClassementEleve($moyenneEleve, $rang);
            $rangPrecedent     = $rang;
            $moyennePrecedente = $moyenneEleve->moyenneGenerale;
        }

        foreach ($nonNotes as $moyenneEleve) {
            $classement[] = new ClassementEleve($moyenneEleve, null);
        }

        return $classement;
    }
}
