<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\Academic\Enum\DomaineMatiere;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\MatiereNiveauRepository;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Entity\ExamenBlancNote;
use App\ExamenBlanc\Repository\ExamenBlancNoteRepository;
use App\ExamenBlanc\Service\Dto\BilanNiveau;
use App\ExamenBlanc\Service\Dto\ClassementEleveBlanc;
use App\ExamenBlanc\Service\Dto\ClassementNiveau;
use App\ExamenBlanc\Service\Dto\MoyenneEleveBlanc;
use App\ExamenBlanc\Service\Dto\MoyenneMatiereEleveBlanc;
use App\Scheduling\Entity\Attribution;
use App\Student\Repository\InscriptionRepository;

/**
 * Calcule, pour un examen blanc et un NIVEAU ENTIER (toutes ses classes actives réunies en un
 * seul groupe, trié alphabétiquement — pas classe par classe), la note de chaque élève dans
 * chaque matière évaluée (voir ExamenBlancMatieresResolver), sa moyenne générale, ses bilans
 * Littéraire et Scientifique, et le classement du niveau.
 *
 * Une fiche niveau/matière est unique : la saisie fusionne déjà toutes les classes (voir
 * ExamenBlanc\Service\ExamenBlancNoteSaisieService), donc la convention "pas de faux zéro" de
 * Grading\Service\MoyenneCalculator s'applique désormais à l'échelle du NIVEAU pour chaque
 * matière : tant que personne n'a de note réelle dans une matière, elle n'apparaît pas ; dès
 * qu'une note réelle existe quelque part dans le niveau, une case vide compte 0 pour tout
 * élève du niveau (plus fine granularité par classe/attribution, cf. version précédente).
 *
 * Pure lecture, aucune écriture en base — réutilisé tel quel par ExamenBlancReleveGenerator.
 */
final class ExamenBlancMoyenneCalculator
{
    public function __construct(
        private readonly ClasseRepository $classeRepo,
        private readonly InscriptionRepository $inscriptionRepo,
        private readonly ExamenBlancNoteRepository $examenBlancNoteRepo,
        private readonly MatiereNiveauRepository $matiereNiveauRepo,
        private readonly ExamenBlancMatieresResolver $matieresResolver,
    ) {
    }

    public function calculer(ExamenBlanc $examenBlanc, Niveau $niveau): ClassementNiveau
    {
        $annee = $examenBlanc->getAnneeScolaire();

        $inscriptions = $this->inscriptionRepo->findActivesByNiveauEtAnnee($niveau, $annee);
        $matieresEvaluees = $this->matieresResolver->resoudre($examenBlanc, $niveau);

        /** @var array<int, array<int, Attribution>> $attributionsParClasseEtMatiere [classeId][matiereId] */
        $attributionsParClasseEtMatiere = [];
        foreach ($this->classeRepo->findActivesByNiveauEtAnnee($niveau, $annee) as $classe) {
            foreach ($classe->getAttributions() as $attribution) {
                $matiereId = $attribution->getMatiere()->getId();
                if (isset($matieresEvaluees[$matiereId])) {
                    $attributionsParClasseEtMatiere[$classe->getId()][$matiereId] = $attribution;
                }
            }
        }

        $notes = $this->examenBlancNoteRepo->findByExamenBlancEtNiveau($examenBlanc, $niveau);

        /** @var array<int, true> $matiereRenseignee matiereId => une note réelle existe quelque part dans le niveau */
        $matiereRenseignee = [];
        /** @var array<int, array<int, ExamenBlancNote>> $notesParInscriptionEtMatiere [inscriptionId][matiereId] */
        $notesParInscriptionEtMatiere = [];
        foreach ($notes as $note) {
            $matiereId = $note->getMatiere()->getId();
            $notesParInscriptionEtMatiere[$note->getInscription()->getId()][$matiereId] = $note;
            if ($note->getValeur() !== null || $note->isAbsent()) {
                $matiereRenseignee[$matiereId] = true;
            }
        }

        $matieres = array_values(array_filter(
            $matieresEvaluees,
            static fn (Matiere $m): bool => isset($matiereRenseignee[$m->getId()]),
        ));

        $moyennesEleve = [];
        foreach ($inscriptions as $inscription) {
            $classe = $inscription->getClasse();
            if ($classe === null) {
                continue; // inscription sans classe affectée : pas de fiche à remplir, exclue du classement
            }

            $moyennesParMatiere = [];
            foreach ($matieres as $matiere) {
                $matiereId = $matiere->getId();

                $note = $notesParInscriptionEtMatiere[$inscription->getId()][$matiereId] ?? null;
                if ($note !== null && $note->isAbsent()) {
                    continue;
                }

                $attribution = $attributionsParClasseEtMatiere[$classe->getId()][$matiereId] ?? null;
                $coefficient = $this->matiereNiveauRepo->findOneByMatiereEtNiveau($matiere, $niveau)?->getCoefficient() ?? '1.00';

                $moyennesParMatiere[$matiereId] = new MoyenneMatiereEleveBlanc(
                    $matiere,
                    $note?->getValeur() ?? '0.00',
                    $coefficient,
                    $attribution?->getEnseignant()->getNomComplet() ?? '—',
                );
            }

            $moyenneGenerale = $this->moyennePonderee(array_map(
                static fn (MoyenneMatiereEleveBlanc $m): array => [$m->note, $m->coefficient],
                array_values($moyennesParMatiere),
            ));

            [$moyenneLitteraire, $moyenneScientifique] = $this->bilansDomaine($moyennesParMatiere);

            $moyennesEleve[] = new MoyenneEleveBlanc(
                $inscription->getEleve(),
                $classe,
                $moyennesParMatiere,
                $moyenneGenerale,
                $moyenneLitteraire,
                $moyenneScientifique,
            );
        }

        return new ClassementNiveau(
            $niveau,
            $examenBlanc,
            $matieres,
            $this->classer($moyennesEleve),
            $this->bilanNiveau($moyennesEleve),
        );
    }

    /**
     * Bilan Littéraire et Scientifique de l'élève : moyenne pondérée par coefficient des
     * matières de chaque domaine (App\Academic\Enum\DomaineMatiere), parmi celles évaluées —
     * même principe que Grading\Service\MoyenneCalculator::bilansDomaine, limité aux deux
     * domaines demandés (le domaine AUTRE n'a pas de bilan sur le relevé).
     *
     * @param array<int, MoyenneMatiereEleveBlanc> $moyennesParMatiere
     * @return array{0: ?string, 1: ?string} [moyenneLitteraire, moyenneScientifique]
     */
    private function bilansDomaine(array $moyennesParMatiere): array
    {
        $litteraires   = [];
        $scientifiques = [];
        foreach ($moyennesParMatiere as $moyenneMatiere) {
            $domaine = $moyenneMatiere->matiere->getDomaine();
            if ($domaine === DomaineMatiere::LITTERAIRE) {
                $litteraires[] = $moyenneMatiere;
            } elseif ($domaine === DomaineMatiere::SCIENTIFIQUE) {
                $scientifiques[] = $moyenneMatiere;
            }
        }

        $moyenneLitteraire   = $this->moyennePonderee(array_map(
            static fn (MoyenneMatiereEleveBlanc $m): array => [$m->note, $m->coefficient],
            $litteraires,
        ));
        $moyenneScientifique = $this->moyennePonderee(array_map(
            static fn (MoyenneMatiereEleveBlanc $m): array => [$m->note, $m->coefficient],
            $scientifiques,
        ));

        return [$moyenneLitteraire, $moyenneScientifique];
    }

    /** @param array<int, array{0: ?string, 1: string}> $paires [valeur, poids][] */
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

    /** @param MoyenneEleveBlanc[] $moyennesEleve */
    private function bilanNiveau(array $moyennesEleve): BilanNiveau
    {
        $moyennesNotees = array_values(array_filter(array_map(
            static fn (MoyenneEleveBlanc $m): ?string => $m->moyenneGenerale,
            $moyennesEleve,
        )));

        if ($moyennesNotees === []) {
            return new BilanNiveau(null, null, null);
        }

        $valeurs = array_map('floatval', $moyennesNotees);

        return new BilanNiveau(
            number_format(min($valeurs), 2, '.', ''),
            number_format(max($valeurs), 2, '.', ''),
            number_format(array_sum($valeurs) / count($valeurs), 2, '.', ''),
        );
    }

    /**
     * Classement compétition standard (1,2,2,4 — pas 1,2,2,3) sur l'ensemble du niveau : les
     * élèves non notés (moyenneGenerale null) sont non classés, placés à la suite.
     *
     * @param MoyenneEleveBlanc[] $moyennesEleve
     * @return ClassementEleveBlanc[]
     */
    private function classer(array $moyennesEleve): array
    {
        $notes    = array_values(array_filter($moyennesEleve, static fn (MoyenneEleveBlanc $m): bool => $m->estNotee()));
        $nonNotes = array_values(array_filter($moyennesEleve, static fn (MoyenneEleveBlanc $m): bool => !$m->estNotee()));

        usort($notes, static fn (MoyenneEleveBlanc $a, MoyenneEleveBlanc $b): int => (float) $b->moyenneGenerale <=> (float) $a->moyenneGenerale);

        $classement        = [];
        $rangPrecedent      = null;
        $moyennePrecedente  = null;
        foreach ($notes as $index => $moyenneEleve) {
            $rang = ($moyennePrecedente !== null && $moyenneEleve->moyenneGenerale === $moyennePrecedente)
                ? $rangPrecedent
                : $index + 1;

            $classement[]      = new ClassementEleveBlanc($moyenneEleve, $rang);
            $rangPrecedent     = $rang;
            $moyennePrecedente = $moyenneEleve->moyenneGenerale;
        }

        foreach ($nonNotes as $moyenneEleve) {
            $classement[] = new ClassementEleveBlanc($moyenneEleve, null);
        }

        return $classement;
    }
}
