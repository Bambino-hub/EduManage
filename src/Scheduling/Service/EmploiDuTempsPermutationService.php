<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Enum\TypeCycle;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Scheduling\Entity\Attribution;
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
        private readonly MatiereNiveauRepository $matiereNiveauRepo,
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

        $erreurs = $this->validerReferences($creneauParSeanceId, $seancesParId, $creneauxParId);
        if ($erreurs !== []) {
            return new PermutationResult(false, $erreurs);
        }

        $erreurs = $this->validerCoherenceFusion($seances, $seancesParId, $creneauParSeanceId, $regroupementParClasseEtMatiere);
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
     * Erreurs de forme : identifiants inconnus, ou séance verrouillée (personnalisée).
     *
     * @param array<int, int> $creneauParSeanceId
     * @param array<int, Seance> $seancesParId
     * @param array<int, Creneau> $creneauxParId
     * @return string[]
     */
    private function validerReferences(array $creneauParSeanceId, array $seancesParId, array $creneauxParId): array
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

            $seance      = $seancesParId[$seanceId];
            $attribution = $seance->getAttribution();

            if ($seance->isVerrouille()) {
                $erreurs[] = sprintf(
                    '« %s » (%s) est verrouillée (personnalisée) : déverrouillez-la d\'abord pour la déplacer.',
                    $attribution->getMatiere()->getNom(),
                    $attribution->getClasse()->getNom(),
                );
            }
        }

        return $erreurs;
    }

    /**
     * Une classe fusionnée (`RegroupementClasse`) partage TOUJOURS le même créneau pour
     * cette matière avec l'autre (les) classe(s) fusionnée(s) — c'est une seule séance
     * pédagogique vécue à plusieurs. Un déplacement doit donc porter sur TOUT le groupe
     * à la fois, vers le même nouveau créneau, jamais sur une seule des classes (ce qui
     * désynchroniserait durablement leur appariement, sans qu'aucun garde-fou du
     * générateur ne puisse le corriger après coup).
     *
     * @param Seance[] $seances
     * @param array<int, Seance> $seancesParId
     * @param array<int, int> $creneauParSeanceId
     * @param array<int, array<int, int>> $regroupementParClasseEtMatiere
     * @return string[]
     */
    private function validerCoherenceFusion(array $seances, array $seancesParId, array $creneauParSeanceId, array $regroupementParClasseEtMatiere): array
    {
        $erreurs = [];

        $regroupementIdParSeance = static function (Seance $s) use ($regroupementParClasseEtMatiere): ?int {
            $a = $s->getAttribution();
            return $regroupementParClasseEtMatiere[$a->getClasse()->getId()][$a->getMatiere()->getId()] ?? null;
        };

        $groupesSourceDejaVerifies = [];
        foreach ($creneauParSeanceId as $seanceId => $nouveauCreneauId) {
            $seance         = $seancesParId[$seanceId];
            $regroupementId = $regroupementIdParSeance($seance);
            if ($regroupementId === null) {
                continue;
            }

            $creneauActuelId = $seance->getCreneau()->getId();
            $cle             = $regroupementId . ':' . $creneauActuelId;
            if (isset($groupesSourceDejaVerifies[$cle])) {
                continue;
            }
            $groupesSourceDejaVerifies[$cle] = true;

            $membres = array_values(array_filter(
                $seances,
                static fn (Seance $s) => $s->getCreneau()->getId() === $creneauActuelId && $regroupementIdParSeance($s) === $regroupementId,
            ));

            $nomMatiere    = $seance->getAttribution()->getMatiere()->getNom();
            $creneauxCibles = [];
            foreach ($membres as $membre) {
                if (!isset($creneauParSeanceId[$membre->getId()])) {
                    $erreurs[] = sprintf(
                        '« %s » concerne des classes fusionnées : %s doit être déplacée avec les autres, pas toute seule.',
                        $nomMatiere,
                        $membre->getAttribution()->getClasse()->getNom(),
                    );
                    continue;
                }
                $creneauxCibles[] = $creneauParSeanceId[$membre->getId()];
            }

            if ($creneauxCibles !== [] && count(array_unique($creneauxCibles)) > 1) {
                $erreurs[] = sprintf(
                    '« %s » concerne des classes fusionnées : elles doivent toutes être déplacées vers le même créneau.',
                    $nomMatiere,
                );
            }
        }

        return array_values(array_unique($erreurs));
    }

    /**
     * Valide l'état obtenu une fois TOUT le lot appliqué : aucun enseignant ni aucune
     * salle ne doit se retrouver à deux endroits en même temps, aucune classe ne doit
     * recevoir 2 séances non-parallèles au même créneau, et les règles de placement
     * (EPS/FHR/8ème heure/indisponibilité enseignant, cf. ReglesPlacementCreneau) doivent rester respectées pour les
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

        // Salle partagée entre 2 matières parallèles (ALL/ESP, TM/EM) de la MÊME classe :
        // pas un conflit non plus depuis que chaque classe n'a plus qu'UNE seule salle
        // standard, partagée par toutes ses séances simultanées (décision utilisateur du
        // 2026-09-16 — cf. EmploiDuTempsGenerator::resoudreSalles()). Ne s'applique qu'à
        // la salle : l'enseignant, lui, reste bien différent entre ALL et ESP, donc le
        // contrôle enseignant n'a pas besoin de cette exemption.
        $estParalleleMemeClasseCoherente = static function (array $groupe): bool {
            $classeIds = array_map(static fn (Seance $s) => $s->getAttribution()->getClasse()->getId(), $groupe);
            if (count(array_unique($classeIds)) !== 1) {
                return false;
            }
            $groupesOptionnels = array_map(
                static fn (Seance $s) => $s->getAttribution()->getMatiere()->getGroupeOptionnel()?->value,
                $groupe,
            );
            return !in_array(null, $groupesOptionnels, true) && count(array_unique($groupesOptionnels)) === 1;
        };

        foreach ($parCreneauEnseignant as $groupe) {
            if (count($groupe) > 1 && !$estFusionCoherente($groupe)) {
                $enseignant = $groupe[0]->getAttribution()->getEnseignant();
                $erreurs[]  = sprintf('%s se retrouverait dans deux classes différentes au même créneau.', $enseignant->getNomComplet());
            }
        }

        foreach ($parCreneauSalle as $groupe) {
            if (count($groupe) > 1 && !$estFusionCoherente($groupe) && !$estParalleleMemeClasseCoherente($groupe)) {
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
            } elseif (ReglesPlacementCreneau::premieresHeuresInterdites($creneau->getOrdre(), $attribution->getEnseignant()->getNbPremieresHeuresAEviter())) {
                $erreurs[] = sprintf('%s est indisponible aux %d première(s) heure(s) de la journée.', $attribution->getEnseignant()->getNomComplet(), $attribution->getEnseignant()->getNbPremieresHeuresAEviter());
            } elseif (ReglesPlacementCreneau::apresMidiInterdit($creneau->getOrdre(), $attribution->getEnseignant()->getHeuresApresMidiInterdites())) {
                $erreurs[] = sprintf('%s est indisponible à la %dème heure (après-midi).', $attribution->getEnseignant()->getNomComplet(), $creneau->getOrdre());
            }
        }

        // EPS : deux séances d'EPS d'une même classe séparées d'au moins 2 jours pleins.
        // On ne contrôle que les classes dont une séance d'EPS est effectivement déplacée
        // (une violation préexistante sur une autre classe ne doit pas bloquer un
        // déplacement sans rapport).
        $classesEpsModifiees = [];
        foreach ($creneauParSeanceId as $seanceId => $creneauId) {
            $seance = $seancesParId[$seanceId];
            if ($seance->getAttribution()->getMatiere()->getCode() === 'EPS') {
                $classesEpsModifiees[$seance->getAttribution()->getClasse()->getId()] = true;
            }
        }

        if ($classesEpsModifiees !== []) {
            $epsParClasse    = [];
            $nomParClasseId  = [];
            foreach ($seances as $seance) {
                $attribution = $seance->getAttribution();
                $classeId    = $attribution->getClasse()->getId();
                if ($attribution->getMatiere()->getCode() !== 'EPS' || !isset($classesEpsModifiees[$classeId])) {
                    continue;
                }
                $nomParClasseId[$classeId] = $attribution->getClasse()->getNom();
                $epsParClasse[$classeId][] = $creneauxParId[$creneauFinalParSeanceId[$seance->getId()]];
            }

            foreach ($epsParClasse as $classeId => $creneauxEps) {
                $nbCreneaux = count($creneauxEps);
                for ($i = 0; $i < $nbCreneaux; $i++) {
                    for ($j = $i + 1; $j < $nbCreneaux; $j++) {
                        if (ReglesPlacementCreneau::epsJoursTropProches($creneauxEps[$i]->getJourSemaine(), $creneauxEps[$j]->getJourSemaine())) {
                            $erreurs[] = sprintf(
                                'EPS (%s) : les séances de %s et %s sont trop rapprochées — il faut au moins 2 jours pleins d\'écart.',
                                $nomParClasseId[$classeId],
                                $creneauxEps[$i]->getJourSemaine()->label(),
                                $creneauxEps[$j]->getJourSemaine()->label(),
                            );
                        }
                    }
                }
            }
        }

        // Volume horaire maximal par jour pour une même matière d'une même classe — même
        // règle que le générateur automatique (EmploiDuTempsGenerator::decomposerHeures()/
        // placerUniteCollegeSixHeures()) : lycée jamais plus de 2h/jour (jamais 3h), EPS
        // jamais plus d'1h/jour quel que soit le cycle, collège 1h/jour sauf le cas
        // spécial 6h/semaine (ex. Français) qui autorise une "journée double" de 2h. On ne
        // contrôle que les paires (classe, matière) effectivement touchées par ce lot — une
        // violation préexistante sans rapport avec le changement demandé ne doit pas le bloquer.
        $pairesTouchees = [];
        foreach ($creneauParSeanceId as $seanceId => $creneauId) {
            $a = $seancesParId[$seanceId]->getAttribution();
            $pairesTouchees[$a->getClasse()->getId() . ':' . $a->getMatiere()->getId()] = true;
        }

        if ($pairesTouchees !== []) {
            $parPaireEtJour = [];
            foreach ($seances as $seance) {
                $a   = $seance->getAttribution();
                $cle = $a->getClasse()->getId() . ':' . $a->getMatiere()->getId();
                if (!isset($pairesTouchees[$cle])) {
                    continue;
                }
                $jour = $creneauxParId[$creneauFinalParSeanceId[$seance->getId()]]->getJourSemaine()->value;
                $parPaireEtJour[$cle][$jour][] = $seance;
            }

            foreach ($parPaireEtJour as $parJour) {
                foreach ($parJour as $seancesDuJour) {
                    $attribution = $seancesDuJour[0]->getAttribution();
                    $max         = $this->capaciteMaxHeuresParJour($attribution);
                    if (count($seancesDuJour) > $max) {
                        $erreurs[] = sprintf(
                            '%s (%s) : %d séances le même jour dépasse le maximum autorisé (%dh/jour).',
                            $attribution->getMatiere()->getNom(),
                            $attribution->getClasse()->getNom(),
                            count($seancesDuJour),
                            $max,
                        );
                    }
                }
            }
        }

        return array_values(array_unique($erreurs));
    }

    /**
     * Volume horaire maximal, pour une même matière d'une même classe, autorisé en UNE
     * seule journée — même règle que `EmploiDuTempsGenerator::decomposerHeures()` :
     * EPS jamais plus d'1h/jour (quel que soit le cycle) ; lycée jamais plus de 2h/jour
     * (un bloc de 2h maximum, jamais 3h) ; collège 1h/jour, sauf le cas spécial 6h/semaine
     * (ex. Français) qui autorise une "journée double" de 2h pour caser 6h sur 5 jours.
     */
    private function capaciteMaxHeuresParJour(Attribution $attribution): int
    {
        $matiere = $attribution->getMatiere();

        if ($matiere->getCode() === 'EPS') {
            return 1;
        }

        $cycle = $attribution->getClasse()->getNiveau()->getCycle()->getType();
        if ($cycle === TypeCycle::LYCEE) {
            return 2;
        }

        $matiereNiveau = $this->matiereNiveauRepo->findOneByMatiereEtNiveau($matiere, $attribution->getClasse()->getNiveau());
        if ($matiereNiveau !== null && (int) round((float) $matiereNiveau->getHeuresParSemaine()) === 6) {
            return 2;
        }

        return 1;
    }
}
