<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Scheduling\Entity\Creneau;
use App\Scheduling\Entity\Seance;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Repository\RegroupementClasseRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Scheduling\Service\Dto\PermutationResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique un lot de permutations manuelles (déplacer une séance vers un autre créneau,
 * même classe) proposées depuis la vue globale de l'emploi du temps — le glisser-déposer
 * côté client n'est qu'une aide visuelle ; cette classe est la seule source de vérité
 * pour valider qu'un lot de changements ne crée aucun conflit avant de l'enregistrer.
 *
 * Les changements sont validés sur l'état FINAL obtenu après application de TOUT le lot
 * d'un coup, jamais changement par changement : un échange A↔B doit être vu comme valide
 * même si, pris isolément, "poser A sur le créneau de B" semble en conflit avec B qui n'a
 * pas encore bougé au moment d'une évaluation naïve séquentielle.
 */
final class EmploiDuTempsPermutationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeanceRepository $seanceRepo,
        private readonly CreneauRepository $creneauRepo,
        private readonly RegroupementClasseRepository $regroupementRepo,
    ) {
    }

    /**
     * @param array<int, int> $creneauParSeanceId nouveau créneau id demandé, par id de séance
     */
    public function appliquer(AnneeScolaire $annee, array $creneauParSeanceId): PermutationResult
    {
        if ($creneauParSeanceId === []) {
            return new PermutationResult(false, ['Aucune modification à enregistrer.']);
        }

        $seances = $this->seanceRepo->findByAnneeScolaire((int) $annee->getId());

        $seancesParId = [];
        foreach ($seances as $seance) {
            $seancesParId[$seance->getId()] = $seance;
        }

        $creneauxParId = [];
        foreach ($this->creneauRepo->findOrdonnes() as $creneau) {
            $creneauxParId[$creneau->getId()] = $creneau;
        }

        $regroupementParClasseEtMatiere = $this->regroupementRepo->indexerParClasseEtMatiere();

        $erreurs = $this->validerReferences($creneauParSeanceId, $seancesParId, $creneauxParId, $regroupementParClasseEtMatiere);
        if ($erreurs !== []) {
            return new PermutationResult(false, $erreurs);
        }

        $erreurs = $this->validerEtatFinal($seances, $seancesParId, $creneauParSeanceId, $creneauxParId, $regroupementParClasseEtMatiere);
        if ($erreurs !== []) {
            return new PermutationResult(false, $erreurs);
        }

        foreach ($creneauParSeanceId as $seanceId => $creneauId) {
            $seancesParId[$seanceId]->setCreneau($creneauxParId[$creneauId]);
        }
        $this->em->flush();

        return new PermutationResult(true);
    }

    /**
     * Erreurs de forme : identifiants inconnus, ou séance faisant partie d'une fusion de
     * classes (déplacer une seule des classes fusionnées casserait leur appariement —
     * refusé plutôt que de silencieusement désynchroniser les 2 classes).
     *
     * @param array<int, int> $creneauParSeanceId
     * @param array<int, Seance> $seancesParId
     * @param array<int, Creneau> $creneauxParId
     * @param array<int, array<int, int>> $regroupementParClasseEtMatiere
     * @return string[]
     */
    private function validerReferences(array $creneauParSeanceId, array $seancesParId, array $creneauxParId, array $regroupementParClasseEtMatiere): array
    {
        $erreurs = [];

        foreach ($creneauParSeanceId as $seanceId => $creneauId) {
            if (!isset($seancesParId[$seanceId])) {
                $erreurs[] = "Séance #{$seanceId} introuvable.";
                continue;
            }
            if (!isset($creneauxParId[$creneauId])) {
                $erreurs[] = "Créneau #{$creneauId} introuvable.";
                continue;
            }

            $attribution = $seancesParId[$seanceId]->getAttribution();
            $classeId    = $attribution->getClasse()->getId();
            $matiereId   = $attribution->getMatiere()->getId();

            if (isset($regroupementParClasseEtMatiere[$classeId][$matiereId])) {
                $erreurs[] = sprintf(
                    '« %s » (%s) concerne des classes fusionnées : elle ne peut pas être déplacée seule.',
                    $attribution->getMatiere()->getNom(),
                    $attribution->getClasse()->getNom(),
                );
            }
        }

        return $erreurs;
    }

    /**
     * Valide l'état obtenu une fois TOUT le lot appliqué : aucun enseignant ni aucune
     * salle ne doit se retrouver à deux endroits en même temps, aucune classe ne doit
     * recevoir 2 séances non-parallèles au même créneau, et les règles de placement
     * (EPS/FHR/8ème heure, cf. ReglesPlacementCreneau) doivent rester respectées pour les
     * séances effectivement déplacées.
     *
     * @param Seance[] $seances
     * @param array<int, Seance> $seancesParId
     * @param array<int, int> $creneauParSeanceId
     * @param array<int, Creneau> $creneauxParId
     * @param array<int, array<int, int>> $regroupementParClasseEtMatiere
     * @return string[]
     */
    private function validerEtatFinal(array $seances, array $seancesParId, array $creneauParSeanceId, array $creneauxParId, array $regroupementParClasseEtMatiere): array
    {
        $erreurs = [];

        $creneauFinalParSeanceId = [];
        foreach ($seances as $seance) {
            $creneauFinalParSeanceId[$seance->getId()] = $seance->getCreneau()->getId();
        }
        foreach ($creneauParSeanceId as $seanceId => $creneauId) {
            $creneauFinalParSeanceId[$seanceId] = $creneauId;
        }

        $regroupementIdParSeance = static function (Seance $seance) use ($regroupementParClasseEtMatiere): ?int {
            $attribution = $seance->getAttribution();
            return $regroupementParClasseEtMatiere[$attribution->getClasse()->getId()][$attribution->getMatiere()->getId()] ?? null;
        };

        $parCreneauEnseignant = [];
        $parCreneauSalle      = [];
        $parCreneauClasse     = [];

        foreach ($seances as $seance) {
            $creneauId = $creneauFinalParSeanceId[$seance->getId()];
            $attribution = $seance->getAttribution();

            $parCreneauEnseignant["{$creneauId}:{$attribution->getEnseignant()->getId()}"][] = $seance;
            $parCreneauSalle["{$creneauId}:{$seance->getSalle()->getId()}"][] = $seance;
            $parCreneauClasse["{$creneauId}:{$attribution->getClasse()->getId()}"][] = $seance;
        }

        // Enseignant/salle partagés entre 2 classes fusionnées pour la même matière : pas
        // un conflit, c'est la même séance pédagogique vécue par les 2 classes ensemble.
        $estFusionCoherente = static function (array $groupe) use ($regroupementIdParSeance): bool {
            $ids = array_map($regroupementIdParSeance, $groupe);
            return count($ids) === count($groupe) && !in_array(null, $ids, true) && count(array_unique($ids)) === 1;
        };

        foreach ($parCreneauEnseignant as $groupe) {
            if (count($groupe) > 1 && !$estFusionCoherente($groupe)) {
                $enseignant = $groupe[0]->getAttribution()->getEnseignant();
                $erreurs[]  = sprintf('%s se retrouverait dans deux classes différentes au même créneau.', $enseignant->getNomComplet());
            }
        }

        foreach ($parCreneauSalle as $groupe) {
            if (count($groupe) > 1 && !$estFusionCoherente($groupe)) {
                $salle     = $groupe[0]->getSalle();
                $erreurs[] = sprintf('La salle %s serait utilisée par deux classes au même créneau.', $salle->getNom());
            }
        }

        // Classe avec 2 séances au même créneau : uniquement légitime pour des matières
        // parallèles (ex. Allemand/Espagnol), reconnues par un même groupeOptionnel non nul.
        foreach ($parCreneauClasse as $groupe) {
            if (count($groupe) <= 1) {
                continue;
            }
            $groupesOptionnels = array_map(
                static fn (Seance $s) => $s->getAttribution()->getMatiere()->getGroupeOptionnel()?->value,
                $groupe,
            );
            $parallele = !in_array(null, $groupesOptionnels, true) && count(array_unique($groupesOptionnels)) === 1;
            if (!$parallele) {
                $classe    = $groupe[0]->getAttribution()->getClasse();
                $erreurs[] = sprintf('%s se retrouverait avec deux séances non liées au même créneau.', $classe->getNom());
            }
        }

        foreach ($creneauParSeanceId as $seanceId => $creneauId) {
            $seance      = $seancesParId[$seanceId];
            $attribution = $seance->getAttribution();
            $creneau     = $creneauxParId[$creneauId];
            $matiereCode = $attribution->getMatiere()->getCode();
            $cycle       = $attribution->getClasse()->getNiveau()->getCycle()->getType();

            if ($creneau->isReserve()) {
                $erreurs[] = sprintf('Le créneau %s est réservé (%s).', $creneau->getLabel(), $creneau->getLibelleReserve());
            } elseif ($creneau->getOrdre() >= 8 && !ReglesPlacementCreneau::ordre8Eligible($cycle, $creneau->getJourSemaine())) {
                $erreurs[] = sprintf('%s : la 8ème heure est réservée au lycée, uniquement lundi et jeudi.', $attribution->getClasse()->getNom());
            } elseif ($matiereCode === 'EPS' && ReglesPlacementCreneau::epsInterdit($creneau->getOrdre())) {
                $erreurs[] = "L'EPS ne peut pas être placée à la 4ème ni à la 5ème heure.";
            } elseif ($matiereCode === 'FHR' && $creneau->getHeureDebut() !== null && ReglesPlacementCreneau::fhrInterdit($creneau->getJourSemaine(), $creneau->getHeureDebut())) {
                $erreurs[] = 'Le FHR ne peut pas être placé le vendredi après-midi.';
            }
        }

        return $erreurs;
    }
}
