<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Academic\Enum\TypeCycle;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Scheduling\Entity\Attribution;
use App\Scheduling\Entity\Seance;
use App\Scheduling\Enum\OrigineVersionEdt;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\RegroupementClasseRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Scheduling\Service\Dto\EchangeAttributionResult;
use App\Staff\Entity\Enseignant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Corrige 2 attributions déjà planifiées SANS relancer generer()/reorganiser().
 *
 * Point clé : la classe d'une attribution n'est JAMAIS échangée. Le créneau d'une
 * séance a été choisi à l'origine parce qu'il était libre POUR SA CLASSE ; échanger
 * la classe laisserait les séances aux mêmes créneaux mais désignant une AUTRE
 * classe, qui a quasiment toujours déjà cours à ce moment-là (conflit garanti dans
 * la quasi-totalité des cas). Seuls l'enseignant et/ou la matière sont échangés,
 * classe et créneau/salle restant fixes sur chaque ligne — ce qui rend l'opération
 * intrinsèquement sûre côté salle/classe, le seul vrai risque de conflit résiduel
 * étant un enseignant désormais occupé ailleurs au même créneau.
 *
 * Tout-ou-rien (aucune écriture si une seule erreur est trouvée), avec sauvegarde
 * automatique (EmploiDuTempsHistorique) juste avant d'écrire — même principe que
 * EmploiDuTempsPermutationService::appliquer() pour la vue globale.
 */
final class AttributionEchangeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeanceRepository $seanceRepo,
        private readonly RegroupementClasseRepository $regroupementRepo,
        private readonly AttributionRepository $attributionRepo,
        private readonly MatiereNiveauRepository $matiereNiveauRepo,
        private readonly EmploiDuTempsHistorique $historique,
    ) {
    }

    /**
     * Échange seulement l'enseignant entre 2 attributions — matière et classe
     * inchangées sur chaque ligne (cas LEMOU/WATOU : deux profs qui échangent leurs
     * classes pour la même matière).
     */
    public function echangerEnseignants(Attribution $a, Attribution $b): EchangeAttributionResult
    {
        // Ni la classe ni la matière ne changent ici : contrairement à
        // echangerMatiereEtEnseignant(), une fusion de classes ou une matière
        // parallèle dans la classe ne pose aucun problème (le créneau partagé,
        // clé sur (classe, matière), reste inchangé) — seul le garde-fou de base
        // s'applique. Le conflit "même enseignant partagé par une fusion légitime"
        // reste couvert par validerConflits() via son exemption dédiée.
        $erreurs = $this->validerGardeFouBase($a, $b);
        if ($erreurs !== []) {
            return new EchangeAttributionResult(false, $erreurs);
        }

        $enseignantA = $a->getEnseignant();
        $enseignantB = $b->getEnseignant();

        $erreurs = $this->validerConflits($a, $b, $enseignantB, $enseignantA, $a->getMatiere(), $b->getMatiere());
        if ($erreurs !== []) {
            return new EchangeAttributionResult(false, $erreurs);
        }

        $this->sauvegarder($a, $b, sprintf('Avant échange enseignants : %s ↔ %s', $a, $b));

        $a->setEnseignant($enseignantB);
        $b->setEnseignant($enseignantA);
        $this->em->flush();

        return new EchangeAttributionResult(true);
    }

    /**
     * Échange la matière ET l'enseignant entre 2 attributions — classe inchangée
     * sur chaque ligne (cas Allemand/Espagnol : deux classes qui ont chacune la
     * mauvaise langue).
     */
    public function echangerMatiereEtEnseignant(Attribution $a, Attribution $b): EchangeAttributionResult
    {
        $erreurs = $this->validerGardeFouBase($a, $b);
        if ($erreurs !== []) {
            return new EchangeAttributionResult(false, $erreurs);
        }

        // Ici, la matière change réellement : une fusion ou une matière parallèle
        // sont indexées par (classe, matière), donc les casser nécessite une
        // coordination que ce simple échange ne fait pas (cf. vue globale/personnaliser).
        $erreurs = $this->validerGardeFouMatiere($a, $b);
        if ($erreurs !== []) {
            return new EchangeAttributionResult(false, $erreurs);
        }

        $matiereA    = $a->getMatiere();
        $matiereB    = $b->getMatiere();
        $enseignantA = $a->getEnseignant();
        $enseignantB = $b->getEnseignant();

        $conflitA = $this->attributionRepo->findConflitMatiereClasse($matiereB, $a->getClasse(), $a->getId());
        if ($conflitA !== null && $conflitA->getId() !== $b->getId()) {
            $erreurs[] = sprintf(
                '%s a déjà %s pour %s.',
                $a->getClasse()->getNom(), $conflitA->getEnseignant()->getNomComplet(), $matiereB->getNom(),
            );
        }
        $conflitB = $this->attributionRepo->findConflitMatiereClasse($matiereA, $b->getClasse(), $b->getId());
        if ($conflitB !== null && $conflitB->getId() !== $a->getId()) {
            $erreurs[] = sprintf(
                '%s a déjà %s pour %s.',
                $b->getClasse()->getNom(), $conflitB->getEnseignant()->getNomComplet(), $matiereA->getNom(),
            );
        }

        $volumeA    = $this->resoudreVolumeHoraire($matiereB, $a->getClasse());
        $nbSeancesA = $a->getSeances()->count();
        if ($volumeA === null) {
            $erreurs[] = sprintf('Aucun volume horaire défini pour %s au niveau de %s.', $matiereB->getNom(), $a->getClasse()->getNom());
        } elseif ($volumeA !== $nbSeancesA) {
            $erreurs[] = sprintf(
                '%s a %d séance(s) déjà posée(s) mais %s prévoit %dh/semaine à ce niveau — échange impossible sans réorganiser.',
                $a->getClasse()->getNom(), $nbSeancesA, $matiereB->getNom(), $volumeA,
            );
        }
        $volumeB    = $this->resoudreVolumeHoraire($matiereA, $b->getClasse());
        $nbSeancesB = $b->getSeances()->count();
        if ($volumeB === null) {
            $erreurs[] = sprintf('Aucun volume horaire défini pour %s au niveau de %s.', $matiereA->getNom(), $b->getClasse()->getNom());
        } elseif ($volumeB !== $nbSeancesB) {
            $erreurs[] = sprintf(
                '%s a %d séance(s) déjà posée(s) mais %s prévoit %dh/semaine à ce niveau — échange impossible sans réorganiser.',
                $b->getClasse()->getNom(), $nbSeancesB, $matiereA->getNom(), $volumeB,
            );
        }

        if ($erreurs !== []) {
            return new EchangeAttributionResult(false, array_values(array_unique($erreurs)));
        }

        $erreurs = $this->validerConflits($a, $b, $enseignantB, $enseignantA, $matiereB, $matiereA);
        if ($erreurs !== []) {
            return new EchangeAttributionResult(false, $erreurs);
        }

        $this->sauvegarder($a, $b, sprintf('Avant échange matière/enseignant : %s ↔ %s', $a, $b));

        $a->setMatiere($matiereB)->setEnseignant($enseignantB)->setVolumeHoraireHebdo($volumeA);
        $b->setMatiere($matiereA)->setEnseignant($enseignantA)->setVolumeHoraireHebdo($volumeB);

        $this->synchroniserMatiereOptionnelle($a->getClasse(), $matiereA, $matiereB);
        $this->synchroniserMatiereOptionnelle($b->getClasse(), $matiereB, $matiereA);

        $this->em->flush();

        return new EchangeAttributionResult(true);
    }

    private function sauvegarder(Attribution $a, Attribution $b, string $libelle): void
    {
        $annee = $a->getClasse()->getAnneeScolaire();
        if ($annee !== null) {
            $this->historique->capturer($annee, OrigineVersionEdt::AvantEchangeAttribution, $libelle);
        }
    }

    /** @return string[] */
    private function validerGardeFouBase(Attribution $a, Attribution $b): array
    {
        if ($a->getId() === $b->getId()) {
            return ['Impossible d\'échanger une attribution avec elle-même.'];
        }

        $anneeA = $a->getClasse()->getAnneeScolaire();
        $anneeB = $b->getClasse()->getAnneeScolaire();
        if ($anneeA === null || $anneeB === null || $anneeA->getId() !== $anneeB->getId()) {
            return ['Les deux attributions doivent appartenir à la même année scolaire.'];
        }

        return [];
    }

    /**
     * Spécifique à echangerMatiereEtEnseignant() : une fusion de classes ou une
     * matière parallèle sont indexées par (classe, matière) — changer la matière
     * d'une des deux lignes casserait cet appariement sans coordination adaptée.
     *
     * @return string[]
     */
    private function validerGardeFouMatiere(Attribution $a, Attribution $b): array
    {
        $erreurs                        = [];
        $regroupementParClasseEtMatiere = $this->regroupementRepo->indexerParClasseEtMatiere();

        foreach ([$a, $b] as $attribution) {
            $classeId  = $attribution->getClasse()->getId();
            $matiereId = $attribution->getMatiere()->getId();

            if (($regroupementParClasseEtMatiere[$classeId][$matiereId] ?? null) !== null) {
                $erreurs[] = sprintf(
                    '%s (%s) fait partie d\'une fusion de classes : utilisez la vue globale, pas cet échange.',
                    $attribution->getMatiere()->getNom(), $attribution->getClasse()->getNom(),
                );
                continue;
            }

            $groupeOptionnel = $attribution->getMatiere()->getGroupeOptionnel();
            if ($groupeOptionnel === null) {
                continue;
            }

            foreach ($this->attributionRepo->findByClasse($classeId) as $soeur) {
                if ($soeur->getId() !== $attribution->getId() && $soeur->getMatiere()->getGroupeOptionnel() === $groupeOptionnel) {
                    $erreurs[] = sprintf(
                        '%s a une matière parallèle (%s) dans la même classe : utilisez la vue globale/personnaliser, pas cet échange.',
                        $attribution->getClasse()->getNom(), $soeur->getMatiere()->getNom(),
                    );
                    break;
                }
            }
        }

        return array_values(array_unique($erreurs));
    }

    /**
     * Vérifie qu'aucune séance de $a/$b, une fois affectée au nouvel enseignant (et à
     * la nouvelle matière, pertinent seulement pour echangerMatiereEtEnseignant()),
     * n'entre en conflit avec une autre séance de l'école au même créneau, ni avec
     * les règles de placement (ReglesPlacementCreneau — mêmes règles que le
     * générateur et la vue globale).
     *
     * @return string[]
     */
    private function validerConflits(
        Attribution $a,
        Attribution $b,
        ?Enseignant $nouvelEnseignantA,
        ?Enseignant $nouvelEnseignantB,
        Matiere $nouvelleMatiereA,
        Matiere $nouvelleMatiereB,
    ): array {
        $erreurs = [];
        $annee   = $a->getClasse()->getAnneeScolaire();
        if ($annee === null) {
            return [];
        }
        $anneeId                         = (int) $annee->getId();
        $regroupementParClasseEtMatiere = $this->regroupementRepo->indexerParClasseEtMatiere();

        $overrides = [
            $a->getId() => ['enseignant' => $nouvelEnseignantA, 'matiere' => $nouvelleMatiereA],
            $b->getId() => ['enseignant' => $nouvelEnseignantB, 'matiere' => $nouvelleMatiereB],
        ];

        $resoudreEnseignant = static fn (Seance $s): Enseignant => $overrides[$s->getAttribution()->getId()]['enseignant']
            ?? $s->getAttribution()->getEnseignant();
        $resoudreMatiere = static fn (Seance $s): Matiere => $overrides[$s->getAttribution()->getId()]['matiere']
            ?? $s->getAttribution()->getMatiere();

        $estFusionCoherente = static function (Seance $s1, Seance $s2) use ($regroupementParClasseEtMatiere): bool {
            $a1 = $s1->getAttribution();
            $a2 = $s2->getAttribution();
            $r1 = $regroupementParClasseEtMatiere[$a1->getClasse()->getId()][$a1->getMatiere()->getId()] ?? null;
            $r2 = $regroupementParClasseEtMatiere[$a2->getClasse()->getId()][$a2->getMatiere()->getId()] ?? null;

            return $r1 !== null && $r1 === $r2;
        };

        foreach ([$a, $b] as $attribution) {
            $classe = $attribution->getClasse();
            $cycle  = $classe->getNiveau()->getCycle()->getType();

            foreach ($attribution->getSeances() as $seance) {
                $creneau    = $seance->getCreneau();
                $enseignant = $resoudreEnseignant($seance);
                $matiere    = $resoudreMatiere($seance);

                foreach ($this->seanceRepo->findByCreneauEtAnnee((int) $creneau->getId(), $anneeId) as $autre) {
                    if ($autre->getId() === $seance->getId()) {
                        continue;
                    }
                    if ($resoudreEnseignant($autre)->getId() !== $enseignant->getId()) {
                        continue;
                    }
                    if ($estFusionCoherente($seance, $autre)) {
                        continue;
                    }
                    $erreurs[] = sprintf(
                        '%s se retrouverait dans deux classes différentes (%s et %s) au créneau %s.',
                        $enseignant->getNomComplet(), $classe->getNom(), $autre->getAttribution()->getClasse()->getNom(), $creneau->getLabel(),
                    );
                }

                if (ReglesPlacementCreneau::premieresHeuresInterdites($creneau->getOrdre(), $enseignant->getNbPremieresHeuresAEviter())) {
                    $erreurs[] = sprintf(
                        '%s est indisponible aux %d première(s) heure(s) de la journée (créneau %s).',
                        $enseignant->getNomComplet(), $enseignant->getNbPremieresHeuresAEviter(), $creneau->getLabel(),
                    );
                }
                if (ReglesPlacementCreneau::apresMidiInterdit($creneau->getOrdre(), $enseignant->getHeuresApresMidiInterdites())) {
                    $erreurs[] = sprintf('%s est indisponible au créneau %s (après-midi).', $enseignant->getNomComplet(), $creneau->getLabel());
                }

                if ($matiere->getCode() === 'EPS' && ReglesPlacementCreneau::epsInterdit($creneau->getOrdre())) {
                    $erreurs[] = "L'EPS ne peut pas être placée à la 4ème ni à la 5ème heure (créneau {$creneau->getLabel()}).";
                }
                if ($matiere->getCode() === 'FHR' && $creneau->getHeureDebut() !== null
                    && ReglesPlacementCreneau::fhrInterdit($creneau->getJourSemaine(), $creneau->getHeureDebut())) {
                    $erreurs[] = 'Le FHR ne peut pas être placé le vendredi après-midi.';
                }
                if ($creneau->getOrdre() >= 8 && !ReglesPlacementCreneau::ordre8Eligible($cycle, $creneau->getJourSemaine())) {
                    $erreurs[] = sprintf('%s : la 8ème heure est réservée au lycée, uniquement lundi et jeudi.', $classe->getNom());
                }
            }
        }

        foreach ([$a, $b] as $attribution) {
            $matiereCible = $overrides[$attribution->getId()]['matiere'];
            if ($matiereCible->getCode() !== 'EPS') {
                continue;
            }
            $classe       = $attribution->getClasse();
            $creneauxEps = [];
            foreach ($this->seanceRepo->findByClasse((int) $classe->getId()) as $seance) {
                if ($resoudreMatiere($seance)->getCode() === 'EPS') {
                    $creneauxEps[] = $seance->getCreneau();
                }
            }
            $n = count($creneauxEps);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (ReglesPlacementCreneau::epsJoursTropProches($creneauxEps[$i]->getJourSemaine(), $creneauxEps[$j]->getJourSemaine())) {
                        $erreurs[] = sprintf(
                            'EPS (%s) : séances trop rapprochées — au moins 2 jours pleins d\'écart nécessaires.',
                            $classe->getNom(),
                        );
                    }
                }
            }
        }

        foreach ([$a, $b] as $attribution) {
            $classe       = $attribution->getClasse();
            $matiereCible = $overrides[$attribution->getId()]['matiere'];

            $parJour = [];
            foreach ($this->seanceRepo->findByClasse((int) $classe->getId()) as $seance) {
                if ($resoudreMatiere($seance)->getId() !== $matiereCible->getId()) {
                    continue;
                }
                $parJour[$seance->getCreneau()->getJourSemaine()->value][] = $seance;
            }

            $max = $this->capaciteMaxHeuresParJour($matiereCible, $classe);
            foreach ($parJour as $seancesDuJour) {
                if (count($seancesDuJour) > $max) {
                    $erreurs[] = sprintf(
                        '%s (%s) : %d séances le même jour dépasse le maximum autorisé (%dh/jour).',
                        $matiereCible->getNom(), $classe->getNom(), count($seancesDuJour), $max,
                    );
                }
            }
        }

        return array_values(array_unique($erreurs));
    }

    private function capaciteMaxHeuresParJour(Matiere $matiere, Classe $classe): int
    {
        if ($matiere->getCode() === 'EPS') {
            return 1;
        }

        $cycle = $classe->getNiveau()->getCycle()->getType();
        if ($cycle === TypeCycle::LYCEE) {
            return 2;
        }

        $matiereNiveau = $this->matiereNiveauRepo->findOneByMatiereEtNiveau($matiere, $classe->getNiveau());
        if ($matiereNiveau !== null && (int) round((float) $matiereNiveau->getHeuresParSemaine()) === 6) {
            return 2;
        }

        return 1;
    }

    private function resoudreVolumeHoraire(Matiere $matiere, Classe $classe): ?int
    {
        $mn = $this->matiereNiveauRepo->findOneByMatiereEtNiveau($matiere, $classe->getNiveau());
        if ($mn === null || (float) $mn->getHeuresParSemaine() <= 0) {
            return null;
        }

        return (int) round((float) $mn->getHeuresParSemaine());
    }

    /**
     * Retire l'ancienne matière et ajoute la nouvelle dans Classe::matieresOptionnelles,
     * pour que ce champ déclaratif reste synchronisé avec la réalité de l'attribution —
     * uniquement pour les matières à choix (ex. Allemand/Espagnol), jamais pour un
     * échange entre matières sans rapport.
     */
    private function synchroniserMatiereOptionnelle(Classe $classe, Matiere $ancienne, Matiere $nouvelle): void
    {
        if ($ancienne->getGroupeOptionnel() === null || $ancienne->getGroupeOptionnel() !== $nouvelle->getGroupeOptionnel()) {
            return;
        }

        $optionnelles = $classe->getMatieresOptionnelles();
        if ($optionnelles->contains($ancienne)) {
            $optionnelles->removeElement($ancienne);
        }
        if (!$optionnelles->contains($nouvelle)) {
            $optionnelles->add($nouvelle);
        }
    }
}
