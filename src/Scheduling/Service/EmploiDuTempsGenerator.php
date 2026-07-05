<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Academic\Entity\Salle;
use App\Academic\Enum\TypeCycle;
use App\Academic\Enum\TypeSalle;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Academic\Repository\SalleRepository;
use App\Scheduling\Entity\Attribution;
use App\Scheduling\Entity\Creneau;
use App\Scheduling\Entity\Seance;
use App\Scheduling\Enum\JourSemaine;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Repository\RegroupementClasseRepository;
use App\Scheduling\Service\Dto\ClasseBilan;
use App\Scheduling\Service\Dto\GenerationResult;
use App\Scheduling\Service\Dto\UnitResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère l'emploi du temps hebdomadaire d'une année scolaire : pour chaque Attribution
 * (ou groupe d'Attributions parallèles / fusionnées), place son volume horaire dans des
 * créneaux libres, sans conflit enseignant/classe/salle.
 *
 * Algorithme "meilleur effort" avec réparation locale : plusieurs tentatives avec un
 * ordre aléatoire (pondéré par difficulté) des unités et des créneaux candidats ; quand
 * un bloc ne trouve aucun créneau totalement libre, on tente de déplacer les séances
 * déjà posées qui le bloquent (jamais pour un conflit de salle) pour leur trouver une
 * autre place, en cascade sur une profondeur bornée — plutôt que d'abandonner
 * immédiatement. On conserve la tentative qui place le plus d'heures. Pas de garantie
 * de solution complète — les unités non placées sont remontées dans le rapport pour
 * correction manuelle des données (salles/attributions/répartition des enseignants).
 */
class EmploiDuTempsGenerator
{
    /** Jours sur lesquels la préférence "1ère heure" des Maths de 3ème peut s'appliquer. */
    private const JOURS_PREFERENCE_MATHS_3EME = [
        JourSemaine::MARDI,
        JourSemaine::MERCREDI,
        JourSemaine::JEUDI,
        JourSemaine::VENDREDI,
    ];

    /**
     * Profondeur maximale d'une chaîne de réparation (déplacer A pour faire de la place
     * à B peut nécessiter de déplacer C pour faire de la place à A, etc.) — bornée pour
     * garder un temps de calcul raisonnable et éviter les cascades sans fin.
     */
    private const PROFONDEUR_MAX_REPARATION = 3;

    /**
     * Nombre maximal de tentatives de réparation (éviction + re-placement) par
     * génération complète d'une tentative — filet de sécurité pour borner le travail
     * total même dans un scénario pathologique, indépendamment de la profondeur.
     */
    private const BUDGET_REPARATION_PAR_TENTATIVE = 4000;

    /**
     * Budget de réparation dédié, offert individuellement à chaque unité encore
     * incomplète après la passe normale (voir la "deuxième chance" dans generer()) —
     * un budget frais par unité, pas partagé, puisqu'il n'en reste qu'une poignée à ce
     * stade.
     */
    private const BUDGET_REPARATION_SECONDE_CHANCE = 3000;

    /**
     * Nombre de passages de la "deuxième chance" — sortie anticipée dès que tout est
     * complet. Volontairement bas (1) : chaque passage supplémentaire coûte cher en
     * temps (rebalaie toutes les unités encore incomplètes avec un budget dédié) pour
     * un gain marginal décroissant — le budget de temps global (BUDGET_TEMPS_SECONDES)
     * reste le vrai filet de sécurité si jamais ça ne suffit pas.
     */
    private const NB_ESSAIS_SECONDE_CHANCE = 1;

    /**
     * Budget de temps total (secondes) pour l'ensemble des tentatives + réparations.
     * Marge volontairement large sous la limite d'exécution PHP côté web (30s observée
     * en local, potentiellement différente en production) : mieux vaut rendre un
     * résultat "meilleur effort" incomplet que de se faire tuer par le serveur en plein
     * calcul, APRÈS la purge des séances existantes et AVANT le flush() final — un
     * timeout à ce moment-là laisserait l'emploi du temps entièrement vide en base.
     */
    private const BUDGET_TEMPS_SECONDES = 18.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttributionRepository $attributionRepo,
        private readonly CreneauRepository $creneauRepo,
        private readonly SalleRepository $salleRepo,
        private readonly MatiereNiveauRepository $matiereNiveauRepo,
        private readonly RegroupementClasseRepository $regroupementRepo,
    ) {
    }

    public function generer(AnneeScolaire $annee, int $maxRestarts = 20): GenerationResult
    {
        $debut        = microtime(true);
        $attributions = $this->attributionRepo->findByAnneeScolaire((int) $annee->getId());
        $this->purgerSeances($attributions);

        if ($attributions === []) {
            return new GenerationResult(0, 0, 0, [], []);
        }

        $regroupementParClasseEtMatiere = $this->indexerRegroupements();
        $unites               = $this->construireUnites($attributions, $regroupementParClasseEtMatiere);
        $heuresTotalDemandees = array_sum(array_map(fn (GenerationUnit $u) => $u->heures, $unites));

        $enseignantNbClasses = $this->compterClassesParEnseignant($attributions);
        $scoreParUnite       = [];
        foreach ($unites as $unite) {
            $scoreParUnite[spl_object_id($unite)] = $this->scoreContrainte($unite, $enseignantNbClasses);
        }

        $tousCreneaux              = $this->creneauRepo->findOrdonnes();
        $creneauxEligiblesParCycle = [
            TypeCycle::COLLEGE->value => $this->filtrerEligibles($tousCreneaux, TypeCycle::COLLEGE),
            TypeCycle::LYCEE->value   => $this->filtrerEligibles($tousCreneaux, TypeCycle::LYCEE),
        ];

        $classes = [];
        foreach ($unites as $unite) {
            foreach ($unite->classes as $classe) {
                $classes[$classe->getId()] = $classe;
            }
        }

        $sallesParType   = [];
        foreach (TypeSalle::cases() as $type) {
            $sallesParType[$type->value] = $this->salleRepo->findByType($type);
        }
        $classeSalleMap = $this->assignerSallesAttitrees(array_values($classes), $sallesParType[TypeSalle::STANDARD->value]);

        $meilleur             = null;
        $tentativesEffectuees = 0;

        for ($tentative = 1; $tentative <= $maxRestarts; $tentative++) {
            if ($meilleur !== null && microtime(true) - $debut > self::BUDGET_TEMPS_SECONDES) {
                break;
            }

            $tentativesEffectuees = $tentative;

            $classeBusy                      = [];
            $enseignantBusy                  = [];
            $salleBusy                       = [];
            $blocs                           = [];
            $prochainBlocId                  = 0;
            $joursUtilisesParUnite           = [];
            $nbPremiereHeurePrefereeParUnite = [];
            $budgetReparation                = self::BUDGET_REPARATION_PAR_TENTATIVE;
            $resultatsUnites                 = [];
            $heuresPlaceesTotal              = 0;

            // La marge entre heures demandées et créneaux disponibles est quasi nulle
            // (collège 31h/31, lycée 32-33h/33) : un ordre purement aléatoire laisse trop
            // souvent les unités les plus contraintes (fusions, matières parallèles,
            // enseignants partagés sur beaucoup de classes) en fin de tentative, quand il
            // ne reste plus de créneau compatible. On les place donc en priorité — le
            // shuffle préalable garde une part d'exploration aléatoire entre unités de
            // difficulté égale, d'une tentative à l'autre.
            $ordreUnites = $unites;
            shuffle($ordreUnites);
            usort(
                $ordreUnites,
                static fn (GenerationUnit $a, GenerationUnit $b) => $scoreParUnite[spl_object_id($b)] <=> $scoreParUnite[spl_object_id($a)],
            );

            foreach ($ordreUnites as $unite) {
                $resultat = $this->placerUnite(
                    $unite,
                    $creneauxEligiblesParCycle,
                    $classeSalleMap,
                    $sallesParType,
                    $classeBusy,
                    $enseignantBusy,
                    $salleBusy,
                    $blocs,
                    $prochainBlocId,
                    $joursUtilisesParUnite,
                    $nbPremiereHeurePrefereeParUnite,
                    $budgetReparation,
                );

                $heuresPlaceesTotal += $resultat['heures'];

                $raisons = $resultat['heures'] < $unite->heures
                    ? [$this->raisonEchec($unite, $classeSalleMap, $sallesParType)]
                    : [];
                $resultatsUnites[] = new UnitResult($unite->libelle, $unite->heures, $resultat['heures'], $raisons);
            }

            // Deuxième chance, ciblée sur les seules unités encore incomplètes : le
            // budget de réparation de la passe normale est partagé entre TOUTES les
            // unités d'une tentative, donc une unité traitée tard peut échouer simplement
            // parce que le budget a déjà été consommé par des réparations antérieures —
            // pas parce qu'aucune réparation n'était possible. Une fois qu'il ne reste
            // qu'une poignée d'unités en échec, on peut se permettre de leur donner
            // chacune un budget frais dédié, sans toucher à l'ordre ni au budget qui a
            // fait ses preuves pour les 90%+ déjà placés. Répété plusieurs fois (avec
            // sortie anticipée dès que tout est complet) : le nouvel ordre aléatoire des
            // candidats à chaque appel de placerUnite() peut réussir un coup qui avait
            // échoué au tour précédent, pour un coût quasi nul une fois qu'il ne reste
            // que quelques unités.
            for ($essai = 0; $essai < self::NB_ESSAIS_SECONDE_CHANCE; $essai++) {
                if (microtime(true) - $debut > self::BUDGET_TEMPS_SECONDES) {
                    break;
                }

                $resteDesIncompletes = false;

                foreach ($ordreUnites as $index => $unite) {
                    if ($resultatsUnites[$index]->estComplet()) {
                        continue;
                    }

                    $budgetSecondeChance = self::BUDGET_REPARATION_SECONDE_CHANCE;
                    $resultat = $this->placerUnite(
                        $unite,
                        $creneauxEligiblesParCycle,
                        $classeSalleMap,
                        $sallesParType,
                        $classeBusy,
                        $enseignantBusy,
                        $salleBusy,
                        $blocs,
                        $prochainBlocId,
                        $joursUtilisesParUnite,
                        $nbPremiereHeurePrefereeParUnite,
                        $budgetSecondeChance,
                    );

                    if ($resultat['heures'] > 0) {
                        $heuresPlaceesTotal += $resultat['heures'];
                        $resultatsUnites[$index] = new UnitResult($unite->libelle, $unite->heures, $resultat['heures'], []);
                    } else {
                        $resteDesIncompletes = true;
                    }
                }

                if (!$resteDesIncompletes) {
                    break;
                }
            }

            // Le registre de blocs est la seule source de vérité fiable pour les
            // placements finaux : la réparation locale peut avoir déplacé le placement
            // d'une unité déjà traitée plus tôt dans cette même tentative.
            $placementsTotal = [];
            foreach ($blocs as $blocData) {
                array_push($placementsTotal, ...$blocData['placements']);
            }

            if ($meilleur === null || $heuresPlaceesTotal > $meilleur['heuresPlacees']) {
                $meilleur = [
                    'heuresPlacees' => $heuresPlaceesTotal,
                    'placements'    => $placementsTotal,
                    'unites'        => $resultatsUnites,
                ];
            }

            if ($heuresPlaceesTotal === $heuresTotalDemandees) {
                break;
            }
        }

        foreach ($meilleur['placements'] as $p) {
            $seance = new Seance();
            $seance->setAttribution($p['attribution']);
            $seance->setCreneau($p['creneau']);
            $seance->setSalle($p['salle']);
            $this->em->persist($seance);
        }
        $this->em->flush();

        return new GenerationResult(
            tentatives: $tentativesEffectuees,
            heuresPlacees: $meilleur['heuresPlacees'],
            heuresNonPlacees: $heuresTotalDemandees - $meilleur['heuresPlacees'],
            unites: $meilleur['unites'],
            bilanClasses: $this->construireBilanClasses($unites, $classes, $creneauxEligiblesParCycle, $meilleur['placements']),
        );
    }

    /**
     * @param Attribution[] $attributions
     * @return array<int, int> enseignantId => nombre de classes distinctes couvertes
     */
    private function compterClassesParEnseignant(array $attributions): array
    {
        $classesParEnseignant = [];
        foreach ($attributions as $a) {
            $classesParEnseignant[$a->getEnseignant()->getId()][$a->getClasse()->getId()] = true;
        }

        return array_map('count', $classesParEnseignant);
    }

    /**
     * Score de "difficulté" d'une unité : plus il est élevé, plus tôt elle doit être
     * placée dans une tentative, avant que les créneaux libres ne se raréfient.
     * Priorise les classes fusionnées (créneau unique imposé sur plusieurs classes), les
     * matières parallèles (deux ressources simultanées à caser), et les unités dont
     * l'enseignant est partagé sur beaucoup de classes (forte contention sur son planning).
     *
     * @param array<int, int> $enseignantNbClasses
     */
    private function scoreContrainte(GenerationUnit $unite, array $enseignantNbClasses): int
    {
        $score = $unite->heures;

        if (count($unite->classes) > 1) {
            $score += 100;
        }
        if (count($unite->attributions) > 1 && count($unite->classes) === 1) {
            $score += 50;
        }

        foreach ($unite->attributions as $attribution) {
            $score += $enseignantNbClasses[$attribution->getEnseignant()->getId()] ?? 1;
        }

        // Petit bonus pour les unités qui portent une préférence de placement (cf.
        // scorePreference()) : les traiter un peu plus tôt augmente les chances que leur
        // créneau préféré soit encore libre, sans jamais garantir qu'il le sera — la
        // préférence reste un choix d'ordre parmi des candidats valides, pas un filtre.
        if ($this->estMatiereCode($unite, 'MATHS') && ($this->estNiveau($unite, '3ème') || $this->estNiveau($unite, '1ere', 'D'))) {
            $score += 15;
        }

        return $score;
    }

    /** @param Attribution[] $attributions */
    private function purgerSeances(array $attributions): void
    {
        $ids = array_map(fn (Attribution $a) => $a->getId(), $attributions);
        if ($ids === []) {
            return;
        }

        $this->em->createQueryBuilder()
            ->delete(Seance::class, 's')
            ->where('s.attribution IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Index [classeId][matiereId] => regroupementId, pour retrouver rapidement si une
     * Attribution (classe × matière) fait partie d'un regroupement de classes fusionnées.
     *
     * @return array<int, array<int, int>>
     */
    private function indexerRegroupements(): array
    {
        $index = [];
        foreach ($this->regroupementRepo->findAllAvecRelations() as $regroupement) {
            foreach ($regroupement->getClasses() as $classe) {
                foreach ($regroupement->getMatieres() as $matiere) {
                    $index[$classe->getId()][$matiere->getId()] = $regroupement->getId();
                }
            }
        }

        return $index;
    }

    /**
     * @param Attribution[] $attributions
     * @param array<int, array<int, int>> $regroupementParClasseEtMatiere
     * @return GenerationUnit[]
     */
    private function construireUnites(array $attributions, array $regroupementParClasseEtMatiere): array
    {
        $groupesOptionnels = [];
        $groupesFusion     = [];
        $unites            = [];

        foreach ($attributions as $attribution) {
            $classeId        = $attribution->getClasse()->getId();
            $matiereId       = $attribution->getMatiere()->getId();
            $regroupementId  = $regroupementParClasseEtMatiere[$classeId][$matiereId] ?? null;
            $groupeOptionnel = $attribution->getMatiere()->getGroupeOptionnel();

            if ($regroupementId !== null) {
                // Classes fusionnées (ex. 1ère C / 1ère D1) : mêmes créneaux imposés pour
                // cette matière, quelles que soient les classes concernées.
                $groupesFusion["{$regroupementId}:{$matiereId}"][] = $attribution;
            } elseif ($groupeOptionnel !== null) {
                // Matières parallèles au sein d'une même classe (ex. Allemand/Espagnol).
                $groupesOptionnels["{$classeId}:{$groupeOptionnel->value}"][] = $attribution;
            } else {
                $unites[] = $this->uniteDepuisAttributions([$attribution]);
            }
        }

        foreach ($groupesOptionnels as $attrs) {
            $unites[] = $this->uniteDepuisAttributions($attrs);
        }
        foreach ($groupesFusion as $attrs) {
            $unites[] = $this->uniteDepuisAttributions($attrs);
        }

        return $unites;
    }

    /** @param Attribution[] $attributions */
    private function uniteDepuisAttributions(array $attributions): GenerationUnit
    {
        $classes = [];
        foreach ($attributions as $a) {
            $classes[$a->getClasse()->getId()] = $a->getClasse();
        }
        $classes = array_values($classes);

        $heures = min(array_map(fn (Attribution $a) => $this->resoudreHeures($a), $attributions));

        $libelleAttributions = implode(' + ', array_map(
            fn (Attribution $a) => $a->getMatiere()->getNom().' ('.$a->getEnseignant()->getNomComplet().')',
            $attributions,
        ));
        $libelleClasses = implode('/', array_map(fn (Classe $c) => $c->getNom(), $classes));

        return new GenerationUnit($classes, $attributions, $heures, "{$libelleAttributions} — {$libelleClasses}");
    }

    private function resoudreHeures(Attribution $attribution): int
    {
        $mn = $this->matiereNiveauRepo->findOneBy([
            'matiere' => $attribution->getMatiere(),
            'niveau'  => $attribution->getClasse()->getNiveau(),
        ]);

        if ($mn === null) {
            return 0;
        }

        return (int) round((float) $mn->getHeuresParSemaine());
    }

    /**
     * Lycée : blocs de 2h, un reliquat de 1h si le volume est impair — jamais de bloc de
     * 3h (règle déterministe confirmée par l'établissement). Collège : séances isolées
     * d'1h ; le seul volume horaire collège qui dépasse les 5 jours disponibles (6h,
     * ex. Français) est traité à part par placerUniteCollegeSixHeures().
     *
     * EPS échappe à la règle lycée : toujours des séances isolées d'1h, jamais 2h
     * consécutives dans la même journée, quel que soit le cycle (règle explicite de
     * l'établissement — chaque bloc atterrit de toute façon sur un jour distinct des
     * autres via placerUnite(), donc l'isolement en 1h garantit 1 seule séance d'EPS par
     * jour, jamais 2h d'affilée).
     *
     * @return int[]
     */
    private function decomposerHeures(GenerationUnit $unite, int $heures, TypeCycle $cycle): array
    {
        if ($heures <= 0) {
            return [];
        }

        if ($cycle === TypeCycle::COLLEGE || $this->estMatiereCode($unite, 'EPS')) {
            return array_fill(0, $heures, 1);
        }

        $blocs = array_fill(0, intdiv($heures, 2), 2);
        if ($heures % 2 === 1) {
            $blocs[] = 1;
        }
        shuffle($blocs);

        return $blocs;
    }

    /** @param Creneau[] $creneaux @return Creneau[] */
    private function filtrerEligibles(array $creneaux, TypeCycle $cycle): array
    {
        return array_values(array_filter($creneaux, function (Creneau $c) use ($cycle) {
            if ($c->isReserve()) {
                return false;
            }
            if ($c->getOrdre() < 8) {
                return true;
            }
            // 8ème heure : réservée au lycée, uniquement lundi et jeudi
            return $cycle === TypeCycle::LYCEE
                && in_array($c->getJourSemaine(), [JourSemaine::LUNDI, JourSemaine::JEUDI], true);
        }));
    }

    /**
     * FHR (Formation Humaine et Religieuse) ne se place jamais le vendredi après-midi.
     *
     * @param Creneau[] $eligibles @return Creneau[]
     */
    private function filtrerFHR(GenerationUnit $unite, array $eligibles): array
    {
        if (!$this->estMatiereCode($unite, 'FHR')) {
            return $eligibles;
        }

        return array_values(array_filter($eligibles, static function (Creneau $c) {
            $vendrediApresMidi = $c->getJourSemaine() === JourSemaine::VENDREDI
                && (int) $c->getHeureDebut()->format('H') >= 13;
            return !$vendrediApresMidi;
        }));
    }

    /**
     * EPS ne se place jamais à la 4ème ni à la 5ème heure, quel que soit le cycle
     * (contrainte de l'établissement — ces créneaux précèdent immédiatement la pause
     * déjeuner, jugés inadaptés à une séance de sport).
     *
     * @param Creneau[] $eligibles @return Creneau[]
     */
    private function filtrerEPS(GenerationUnit $unite, array $eligibles): array
    {
        if (!$this->estMatiereCode($unite, 'EPS')) {
            return $eligibles;
        }

        return array_values(array_filter($eligibles, static fn (Creneau $c) => !in_array($c->getOrdre(), [4, 5], true)));
    }

    /**
     * Créneaux éligibles pour une unité donnée : filtre cycle + FHR + EPS déjà
     * appliqués. Recalculé à chaque appel (pas cher — les filtres opèrent sur ~35
     * créneaux) plutôt que mis en cache, car la réparation locale a besoin de
     * recalculer les éligibles d'une unité "victime" différente de l'unité en cours.
     *
     * @param array<string, Creneau[]> $creneauxEligiblesParCycle
     * @return Creneau[]
     */
    private function eligiblesPourUnite(GenerationUnit $unite, array $creneauxEligiblesParCycle): array
    {
        $cycle     = $unite->classes[0]->getNiveau()->getCycle()->getType();
        $eligibles = $this->filtrerFHR($unite, $creneauxEligiblesParCycle[$cycle->value]);

        return $this->filtrerEPS($unite, $eligibles);
    }

    /** L'unité contient-elle une Attribution de la matière au code donné ? */
    private function estMatiereCode(GenerationUnit $unite, string $code): bool
    {
        foreach ($unite->attributions as $attribution) {
            if ($attribution->getMatiere()->getCode() === $code) {
                return true;
            }
        }

        return false;
    }

    /** Une des classes de l'unité est-elle de ce niveau (et, si précisée, cette série) ? */
    private function estNiveau(GenerationUnit $unite, string $nom, ?string $serie = null): bool
    {
        foreach ($unite->classes as $classe) {
            $niveau = $classe->getNiveau();
            if ($niveau->getNom() === $nom && ($serie === null || $niveau->getSerie() === $serie)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Score de préférence "esthétique" d'un créneau candidat pour une unité donnée —
     * n'exclut jamais un candidat (contrairement à filtrerEPS/filtrerFHR) : sert
     * uniquement à choisir en premier le créneau préféré parmi ceux déjà valides, tout
     * en gardant les autres disponibles si le préféré est pris ou si l'utilisateur
     * déplace la séance manuellement ensuite.
     *
     * - Maths 3ème : jusqu'à 2 des séances d'1h en 1ère heure un jour entre mardi et
     *   vendredi (compteur $nbPremiereHeurePreferee) ; à défaut, préférence pour le matin.
     * - Maths 1ère D : le bloc de 2h en début d'après-midi (1ère heure après la pause).
     *
     * @param Creneau[] $groupeCreneaux
     */
    private function scorePreference(GenerationUnit $unite, array $groupeCreneaux, int $nbPremiereHeurePreferee): int
    {
        $premier = $groupeCreneaux[0];
        $score   = 0;

        if (count($groupeCreneaux) === 1 && $this->estMatiereCode($unite, 'MATHS') && $this->estNiveau($unite, '3ème')) {
            if (
                $nbPremiereHeurePreferee < 2
                && $premier->getOrdre() === 1
                && in_array($premier->getJourSemaine(), self::JOURS_PREFERENCE_MATHS_3EME, true)
            ) {
                $score += 1000;
            } elseif ((int) $premier->getHeureDebut()->format('H') < 13) {
                $score += 100;
            }
        }

        if (count($groupeCreneaux) === 2 && $premier->getOrdre() === 6 && $this->estMatiereCode($unite, 'MATHS') && $this->estNiveau($unite, '1ere', 'D')) {
            $score += 1000;
        }

        return $score;
    }

    /** @param Creneau[] $groupeCreneaux */
    private function matchPreferencePremiereHeureMaths3eme(GenerationUnit $unite, array $groupeCreneaux): bool
    {
        if (count($groupeCreneaux) !== 1 || !$this->estMatiereCode($unite, 'MATHS') || !$this->estNiveau($unite, '3ème')) {
            return false;
        }

        $c = $groupeCreneaux[0];

        return $c->getOrdre() === 1 && in_array($c->getJourSemaine(), self::JOURS_PREFERENCE_MATHS_3EME, true);
    }

    /**
     * Trie les groupes de créneaux candidats par préférence décroissante (le shuffle en
     * amont garantit un ordre aléatoire entre candidats de même score — usort est stable
     * depuis PHP8).
     *
     * @param list<Creneau[]> $candidats @return list<Creneau[]>
     */
    private function trierParPreference(GenerationUnit $unite, array $candidats, int $nbPremiereHeurePreferee): array
    {
        $avecScore = array_map(
            fn (array $groupe) => ['groupe' => $groupe, 'score' => $this->scorePreference($unite, $groupe, $nbPremiereHeurePreferee)],
            $candidats,
        );
        usort($avecScore, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_map(static fn (array $e) => $e['groupe'], $avecScore);
    }

    /** @param Creneau[] $creneaux @return array<string, Creneau[]> */
    private function creneauxParJour(array $creneaux): array
    {
        $parJour = [];
        foreach ($creneaux as $c) {
            $parJour[$c->getJourSemaine()->value][] = $c;
        }
        foreach ($parJour as &$liste) {
            usort($liste, fn (Creneau $a, Creneau $b) => $a->getOrdre() <=> $b->getOrdre());
        }

        return $parJour;
    }

    /**
     * Groupes de $taille créneaux consécutifs (même jour, ordre qui se suit sans trou).
     *
     * @param array<string, Creneau[]> $creneauxParJour
     * @return list<Creneau[]>
     */
    private function trouverBlocsCandidats(array $creneauxParJour, int $taille): array
    {
        $candidats = [];
        foreach ($creneauxParJour as $liste) {
            $n = count($liste);
            for ($i = 0; $i + $taille <= $n; $i++) {
                $groupe = array_slice($liste, $i, $taille);
                $ok     = true;
                for ($j = 1; $j < $taille; $j++) {
                    if ($groupe[$j]->getOrdre() !== $groupe[$j - 1]->getOrdre() + 1) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $candidats[] = $groupe;
                }
            }
        }

        return $candidats;
    }

    /** @template T @param T[] $tableau @return T[] */
    private function shuffleArray(array $tableau): array
    {
        shuffle($tableau);

        return $tableau;
    }

    /** @param Creneau[] $groupeCreneaux */
    private function candidatValide(GenerationUnit $unite, array $groupeCreneaux, array $classeBusy, array $enseignantBusy): bool
    {
        foreach ($groupeCreneaux as $creneau) {
            $cId = $creneau->getId();

            foreach ($unite->classes as $classe) {
                if (isset($classeBusy["{$classe->getId()}:{$cId}"])) {
                    return false;
                }
            }
            foreach ($unite->attributions as $attribution) {
                if (isset($enseignantBusy["{$attribution->getEnseignant()->getId()}:{$cId}"])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Identifiants de blocs (dédupliqués) qui occupent, pour cette unité, une ressource
     * classe ou enseignant sur ce groupe de créneaux — jamais un bloc de l'unité
     * elle-même (impossible par construction : son jour est déjà exclu avant l'appel).
     * Les conflits de salle ne sont jamais recherchés ici : ils ne se réparent pas.
     *
     * @param Creneau[] $groupeCreneaux
     * @param array<string, int> $classeBusy
     * @param array<string, int> $enseignantBusy
     * @return int[]
     */
    private function blocsEnConflit(GenerationUnit $unite, array $groupeCreneaux, array $classeBusy, array $enseignantBusy): array
    {
        $ids = [];
        foreach ($groupeCreneaux as $creneau) {
            $cId = $creneau->getId();

            foreach ($unite->classes as $classe) {
                $cle = "{$classe->getId()}:{$cId}";
                if (isset($classeBusy[$cle])) {
                    $ids[$classeBusy[$cle]] = true;
                }
            }
            foreach ($unite->attributions as $attribution) {
                $cle = "{$attribution->getEnseignant()->getId()}:{$cId}";
                if (isset($enseignantBusy[$cle])) {
                    $ids[$enseignantBusy[$cle]] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Résout une salle par Attribution du groupe (attitrée pour les matières standards,
     * cherchée dans le pool spécialisé sinon), libre sur TOUS les créneaux du bloc. Si
     * deux attributions du groupe partagent le même enseignant (ex. classes fusionnées
     * pour une matière : même professeur pour les deux classes), elles reçoivent
     * obligatoirement la même salle — un enseignant ne peut pas être à deux endroits en
     * même temps, indépendamment de ce que dit chaque classe attitrée.
     *
     * @param Creneau[] $groupeCreneaux
     * @param array<int, Salle> $classeSalleMap
     * @param array<string, Salle[]> $sallesParType
     * @return array<int, Salle>|null clé = id de l'Attribution
     */
    private function resoudreSalles(GenerationUnit $unite, array $groupeCreneaux, array $classeSalleMap, array $sallesParType, array $salleBusy): ?array
    {
        $resultat           = [];
        $sallesRetenues      = []; // ids déjà pris DANS cette résolution — un groupe parallèle a besoin
                                    // de salles distinctes pour ses membres simultanés, jamais la même deux fois
        $salleParEnseignant = []; // enseignantId => Salle déjà retenue dans cette résolution

        foreach ($unite->attributions as $attribution) {
            $enseignantId = $attribution->getEnseignant()->getId();

            if (isset($salleParEnseignant[$enseignantId])) {
                $resultat[$attribution->getId()] = $salleParEnseignant[$enseignantId];
                continue;
            }

            $typeRequis = $attribution->getMatiere()->getSalleRequise();

            if ($typeRequis === null) {
                // La salle attitrée de la classe est essayée en priorité ; si elle est déjà prise
                // par un autre membre du même groupe parallèle (ex. ALL a pris la salle de la classe,
                // ESP a besoin d'une autre salle standard au même moment), on pioche dans le pool.
                $salleAttitree = $classeSalleMap[$attribution->getClasse()->getId()] ?? null;
                $candidats     = array_values(array_filter(
                    [$salleAttitree, ...($sallesParType[TypeSalle::STANDARD->value] ?? [])],
                    static fn (?Salle $s) => $s !== null,
                ));
            } else {
                $candidats = $this->shuffleArray($sallesParType[$typeRequis->value] ?? []);
            }

            $trouve = null;
            foreach ($candidats as $salle) {
                if (in_array($salle->getId(), $sallesRetenues, true)) {
                    continue;
                }
                if ($this->salleLibreSurGroupe($salle, $groupeCreneaux, $salleBusy)) {
                    $trouve = $salle;
                    break;
                }
            }
            if ($trouve === null) {
                return null;
            }
            $resultat[$attribution->getId()]   = $trouve;
            $sallesRetenues[]                  = $trouve->getId();
            $salleParEnseignant[$enseignantId] = $trouve;
        }

        return $resultat;
    }

    /** @param Creneau[] $groupeCreneaux */
    private function salleLibreSurGroupe(Salle $salle, array $groupeCreneaux, array $salleBusy): bool
    {
        foreach ($groupeCreneaux as $creneau) {
            if (isset($salleBusy["{$salle->getId()}:{$creneau->getId()}"])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Place une unité (tous ses blocs) ou annule tout ce qu'elle a commis — y compris
     * d'éventuelles réparations locales déclenchées en cours de route — si un seul bloc
     * reste impossible à placer même après réparation. Chaque bloc de l'unité tombe sur
     * un jour distinct des autres (sauf le cas spécial collège 6h/semaine, seul cas où
     * un même jour porte 2 séances — voir placerUniteCollegeSixHeures).
     *
     * @param array<string, Creneau[]> $creneauxEligiblesParCycle
     * @param array<int, Salle> $classeSalleMap
     * @param array<string, Salle[]> $sallesParType
     * @param array<int, array{unite: GenerationUnit, uniteId: int, groupeCreneaux: Creneau[], jour: string, placements: list<array{attribution: Attribution, creneau: Creneau, salle: Salle}>, clesClasse: string[], clesEnseignant: string[], clesSalle: string[]}> $blocs
     * @param array<int, array<string, true>> $joursUtilisesParUnite
     * @param array<int, int> $nbPremiereHeurePrefereeParUnite
     * @return array{heures: int}
     */
    private function placerUnite(
        GenerationUnit $unite,
        array $creneauxEligiblesParCycle,
        array $classeSalleMap,
        array $sallesParType,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
        array &$blocs,
        int &$prochainBlocId,
        array &$joursUtilisesParUnite,
        array &$nbPremiereHeurePrefereeParUnite,
        int &$budgetReparation,
    ): array {
        $cycle = $unite->classes[0]->getNiveau()->getCycle()->getType();

        if ($cycle === TypeCycle::COLLEGE && $unite->heures === 6) {
            return $this->placerUniteCollegeSixHeures(
                $unite,
                $this->eligiblesPourUnite($unite, $creneauxEligiblesParCycle),
                $classeSalleMap,
                $sallesParType,
                $classeBusy,
                $enseignantBusy,
                $salleBusy,
                $blocs,
                $prochainBlocId,
                $joursUtilisesParUnite,
                $nbPremiereHeurePrefereeParUnite,
            );
        }

        $blocsTailles = $this->decomposerHeures($unite, $unite->heures, $cycle);
        $journal      = [];

        foreach ($blocsTailles as $taille) {
            $id = $this->placerBlocAvecReparation(
                $unite,
                $taille,
                $creneauxEligiblesParCycle,
                $classeSalleMap,
                $sallesParType,
                $classeBusy,
                $enseignantBusy,
                $salleBusy,
                $blocs,
                $prochainBlocId,
                $joursUtilisesParUnite,
                $nbPremiereHeurePrefereeParUnite,
                $journal,
                $budgetReparation,
                0,
            );

            if ($id === null) {
                $this->annulerDepuis($journal, 0, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);

                return ['heures' => 0];
            }
        }

        return ['heures' => $unite->heures];
    }

    /**
     * Tente de placer un bloc de $taille créneaux pour $unite. Essaie d'abord un
     * candidat totalement libre (chemin normal, rapide) ; si aucun n'existe, tente une
     * réparation locale : pour un candidat bloqué uniquement par des blocs d'AUTRES
     * unités (jamais un conflit de salle, jamais un bloc de l'unité elle-même), évince
     * temporairement ces blocs et essaie de les replacer ailleurs — récursivement,
     * jusqu'à self::PROFONDEUR_MAX_REPARATION niveaux, avec un budget total
     * d'opérations pour borner le travail par tentative. Toute action (pose ou
     * éviction) est enregistrée dans $journal pour permettre une annulation complète et
     * cohérente si la chaîne de réparation échoue en cours de route.
     *
     * @param array<string, Creneau[]> $creneauxEligiblesParCycle
     * @param array<int, Salle> $classeSalleMap
     * @param array<string, Salle[]> $sallesParType
     * @param array<int, array{unite: GenerationUnit, uniteId: int, groupeCreneaux: Creneau[], jour: string, placements: list<array{attribution: Attribution, creneau: Creneau, salle: Salle}>, clesClasse: string[], clesEnseignant: string[], clesSalle: string[]}> $blocs
     * @param array<int, array<string, true>> $joursUtilisesParUnite
     * @param array<int, int> $nbPremiereHeurePrefereeParUnite
     * @param list<array{action: string, id: int, data?: array}> $journal
     * @return int|null l'id du bloc posé, ou null si impossible (le journal n'a alors
     *                   subi aucun effet net résiduel)
     */
    private function placerBlocAvecReparation(
        GenerationUnit $unite,
        int $taille,
        array $creneauxEligiblesParCycle,
        array $classeSalleMap,
        array $sallesParType,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
        array &$blocs,
        int &$prochainBlocId,
        array &$joursUtilisesParUnite,
        array &$nbPremiereHeurePrefereeParUnite,
        array &$journal,
        int &$budgetReparation,
        int $profondeur,
    ): ?int {
        $uniteId       = spl_object_id($unite);
        $eligibles     = $this->eligiblesPourUnite($unite, $creneauxEligiblesParCycle);
        $joursUtilises = $joursUtilisesParUnite[$uniteId] ?? [];

        $candidats = $taille === 1
            ? array_map(static fn (Creneau $c) => [$c], $this->shuffleArray($eligibles))
            : $this->shuffleArray($this->trouverBlocsCandidats($this->creneauxParJour($eligibles), $taille));

        $candidats = $this->trierParPreference($unite, $candidats, $nbPremiereHeurePrefereeParUnite[$uniteId] ?? 0);

        // Passe 1 : un candidat totalement libre — résout la grande majorité des blocs
        // sans jamais toucher à la réparation.
        foreach ($candidats as $groupeCreneaux) {
            $jour = $groupeCreneaux[0]->getJourSemaine()->value;
            if (isset($joursUtilises[$jour]) || !$this->candidatValide($unite, $groupeCreneaux, $classeBusy, $enseignantBusy)) {
                continue;
            }
            $salles = $this->resoudreSalles($unite, $groupeCreneaux, $classeSalleMap, $sallesParType, $salleBusy);
            if ($salles === null) {
                continue;
            }

            return $this->commitBloc($unite, $groupeCreneaux, $salles, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $prochainBlocId, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite, $journal);
        }

        if ($profondeur >= self::PROFONDEUR_MAX_REPARATION) {
            return null;
        }

        // Passe 2 : réparation locale. Un candidat n'est éligible que si tous ses
        // conflits sont des blocs d'AUTRES unités (la salle ne se répare jamais ici).
        foreach ($candidats as $groupeCreneaux) {
            if ($budgetReparation <= 0) {
                return null;
            }

            $jour = $groupeCreneaux[0]->getJourSemaine()->value;
            if (isset($joursUtilises[$jour])) {
                continue;
            }

            $blocIdsConflit = $this->blocsEnConflit($unite, $groupeCreneaux, $classeBusy, $enseignantBusy);
            if ($blocIdsConflit === []) {
                continue; // déjà tenté en passe 1 (aucun conflit classe/enseignant, seule la salle bloquait)
            }
            $budgetReparation--;

            $pointDeReprise = count($journal);
            $victimes       = [];
            foreach ($blocIdsConflit as $blocId) {
                $victimes[] = $this->evincerBloc($blocId, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite, $journal);
            }

            // La salle se résout APRÈS éviction : une salle libérée par une victime doit
            // compter comme disponible pour le nouveau bloc.
            $salles = $this->resoudreSalles($unite, $groupeCreneaux, $classeSalleMap, $sallesParType, $salleBusy);
            if ($salles === null) {
                $this->annulerDepuis($journal, $pointDeReprise, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);
                continue; // conflit de salle en plus du conflit classe/enseignant : pas réparable ici
            }

            $nouvelId = $this->commitBloc($unite, $groupeCreneaux, $salles, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $prochainBlocId, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite, $journal);

            $reparationReussie = true;
            foreach ($victimes as $victime) {
                $idReplace = $this->placerBlocAvecReparation(
                    $victime['unite'],
                    count($victime['groupeCreneaux']),
                    $creneauxEligiblesParCycle,
                    $classeSalleMap,
                    $sallesParType,
                    $classeBusy,
                    $enseignantBusy,
                    $salleBusy,
                    $blocs,
                    $prochainBlocId,
                    $joursUtilisesParUnite,
                    $nbPremiereHeurePrefereeParUnite,
                    $journal,
                    $budgetReparation,
                    $profondeur + 1,
                );
                if ($idReplace === null) {
                    $reparationReussie = false;
                    break;
                }
            }

            if ($reparationReussie) {
                return $nouvelId;
            }

            // La chaîne de réparation a échoué quelque part : tout annuler (la pose, les
            // évictions, et toute réparation partielle déjà réussie pour les victimes)
            // et essayer le candidat suivant.
            $this->annulerDepuis($journal, $pointDeReprise, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);
        }

        return null;
    }

    /**
     * Cas spécial collège = 6h/semaine (seul volume collège qui dépasse les 5 jours
     * disponibles) : un jour porte 2 séances (1h le matin + 1h l'après-midi — jamais
     * adjacentes vu la coupure repas), les 4 autres jours portent chacun 1 séance. Pas
     * de réparation locale ici (cas marginal, une seule matière concernée) : les blocs
     * posés restent néanmoins dans le même registre et peuvent être évincés comme
     * victimes par la réparation d'une AUTRE unité.
     *
     * @param Creneau[] $eligibles
     * @param array<int, Salle> $classeSalleMap
     * @param array<string, Salle[]> $sallesParType
     * @param array<int, array{unite: GenerationUnit, uniteId: int, groupeCreneaux: Creneau[], jour: string, placements: list<array{attribution: Attribution, creneau: Creneau, salle: Salle}>, clesClasse: string[], clesEnseignant: string[], clesSalle: string[]}> $blocs
     * @param array<int, array<string, true>> $joursUtilisesParUnite
     * @param array<int, int> $nbPremiereHeurePrefereeParUnite
     * @return array{heures: int}
     */
    private function placerUniteCollegeSixHeures(
        GenerationUnit $unite,
        array $eligibles,
        array $classeSalleMap,
        array $sallesParType,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
        array &$blocs,
        int &$prochainBlocId,
        array &$joursUtilisesParUnite,
        array &$nbPremiereHeurePrefereeParUnite,
    ): array {
        $parJour = $this->creneauxParJour($eligibles);
        $jours   = $this->shuffleArray(array_keys($parJour));

        foreach ($jours as $jourDouble) {
            $matin     = array_values(array_filter($parJour[$jourDouble], static fn (Creneau $c) => (int) $c->getHeureDebut()->format('H') < 13));
            $apresMidi = array_values(array_filter($parJour[$jourDouble], static fn (Creneau $c) => (int) $c->getHeureDebut()->format('H') >= 13));

            if ($matin === [] || $apresMidi === []) {
                continue;
            }

            $journal = [];

            $idMatin = null;
            foreach ($this->shuffleArray($matin) as $creneauMatin) {
                if (!$this->candidatValide($unite, [$creneauMatin], $classeBusy, $enseignantBusy)) {
                    continue;
                }
                $salles = $this->resoudreSalles($unite, [$creneauMatin], $classeSalleMap, $sallesParType, $salleBusy);
                if ($salles === null) {
                    continue;
                }
                $idMatin = $this->commitBloc($unite, [$creneauMatin], $salles, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $prochainBlocId, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite, $journal);
                break;
            }
            if ($idMatin === null) {
                continue;
            }

            $idPm = null;
            foreach ($this->shuffleArray($apresMidi) as $creneauPm) {
                if (!$this->candidatValide($unite, [$creneauPm], $classeBusy, $enseignantBusy)) {
                    continue;
                }
                $salles = $this->resoudreSalles($unite, [$creneauPm], $classeSalleMap, $sallesParType, $salleBusy);
                if ($salles === null) {
                    continue;
                }
                $idPm = $this->commitBloc($unite, [$creneauPm], $salles, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $prochainBlocId, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite, $journal);
                break;
            }
            if ($idPm === null) {
                $this->annulerDepuis($journal, 0, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);
                continue;
            }

            $autresJours = array_values(array_filter($jours, static fn ($j) => $j !== $jourDouble));
            $succes      = true;

            foreach ($autresJours as $jour) {
                $place = false;
                foreach ($this->shuffleArray($parJour[$jour]) as $creneau) {
                    if (!$this->candidatValide($unite, [$creneau], $classeBusy, $enseignantBusy)) {
                        continue;
                    }
                    $salles = $this->resoudreSalles($unite, [$creneau], $classeSalleMap, $sallesParType, $salleBusy);
                    if ($salles === null) {
                        continue;
                    }
                    $this->commitBloc($unite, [$creneau], $salles, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $prochainBlocId, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite, $journal);
                    $place = true;
                    break;
                }
                if (!$place) {
                    $succes = false;
                    break;
                }
            }

            if ($succes) {
                return ['heures' => 6];
            }

            $this->annulerDepuis($journal, 0, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);
        }

        return ['heures' => 0];
    }

    /**
     * Enregistre un nouveau bloc dans le registre et le journal (action réversible).
     *
     * @param Creneau[] $groupeCreneaux
     * @param array<int, Salle> $salles clé = id de l'Attribution
     * @param array<int, array<string, true>> $joursUtilisesParUnite
     * @param array<int, int> $nbPremiereHeurePrefereeParUnite
     * @param list<array{action: string, id: int, data?: array}> $journal
     */
    private function commitBloc(
        GenerationUnit $unite,
        array $groupeCreneaux,
        array $salles,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
        array &$blocs,
        int &$prochainBlocId,
        array &$joursUtilisesParUnite,
        array &$nbPremiereHeurePrefereeParUnite,
        array &$journal,
    ): int {
        $placements     = [];
        $clesClasse     = [];
        $clesEnseignant = [];
        $clesSalle      = [];

        foreach ($groupeCreneaux as $creneau) {
            $cId = $creneau->getId();

            foreach ($unite->classes as $classe) {
                $clesClasse[] = "{$classe->getId()}:{$cId}";
            }

            foreach ($unite->attributions as $attribution) {
                $clesEnseignant[] = "{$attribution->getEnseignant()->getId()}:{$cId}";

                $salle       = $salles[$attribution->getId()];
                $clesSalle[] = "{$salle->getId()}:{$cId}";

                $placements[] = ['attribution' => $attribution, 'creneau' => $creneau, 'salle' => $salle];
            }
        }

        $id       = $prochainBlocId++;
        $blocData = [
            'unite'          => $unite,
            'uniteId'        => spl_object_id($unite),
            'groupeCreneaux' => $groupeCreneaux,
            'jour'           => $groupeCreneaux[0]->getJourSemaine()->value,
            'placements'     => $placements,
            'clesClasse'     => $clesClasse,
            'clesEnseignant' => $clesEnseignant,
            'clesSalle'      => $clesSalle,
        ];

        $this->insererBlocBrut($id, $blocData, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);
        $journal[] = ['action' => 'insert', 'id' => $id];

        return $id;
    }

    /**
     * Retire un bloc du registre pour faire de la place à un autre (réparation) et
     * l'enregistre dans le journal pour pouvoir le restaurer si la réparation échoue.
     *
     * @param array<int, array<string, true>> $joursUtilisesParUnite
     * @param array<int, int> $nbPremiereHeurePrefereeParUnite
     * @param list<array{action: string, id: int, data?: array}> $journal
     * @return array{unite: GenerationUnit, uniteId: int, groupeCreneaux: Creneau[], jour: string, placements: array, clesClasse: string[], clesEnseignant: string[], clesSalle: string[]}
     */
    private function evincerBloc(
        int $id,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
        array &$blocs,
        array &$joursUtilisesParUnite,
        array &$nbPremiereHeurePrefereeParUnite,
        array &$journal,
    ): array {
        $blocData  = $this->retirerBlocBrut($id, $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);
        $journal[] = ['action' => 'remove', 'id' => $id, 'data' => $blocData];

        return $blocData;
    }

    /**
     * Annule toutes les opérations du journal depuis l'index $depuis (inclus), dans
     * l'ordre inverse : une pose est défaite en retirant le bloc, un retrait est défait
     * en réinsérant le bloc tel qu'il était. Rejouer en ordre inverse garantit que
     * chaque réinsertion retrouve son créneau libre (les opérations plus récentes qui
     * auraient pu le réoccuper sont défaites en premier).
     *
     * @param list<array{action: string, id: int, data?: array}> $journal
     * @param array<int, array<string, true>> $joursUtilisesParUnite
     * @param array<int, int> $nbPremiereHeurePrefereeParUnite
     */
    private function annulerDepuis(
        array &$journal,
        int $depuis,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
        array &$blocs,
        array &$joursUtilisesParUnite,
        array &$nbPremiereHeurePrefereeParUnite,
    ): void {
        while (count($journal) > $depuis) {
            $op = array_pop($journal);
            if ($op['action'] === 'insert') {
                $this->retirerBlocBrut($op['id'], $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);
            } else {
                $this->insererBlocBrut($op['id'], $op['data'], $classeBusy, $enseignantBusy, $salleBusy, $blocs, $joursUtilisesParUnite, $nbPremiereHeurePrefereeParUnite);
            }
        }
    }

    /**
     * @param array{unite: GenerationUnit, uniteId: int, groupeCreneaux: Creneau[], jour: string, placements: array, clesClasse: string[], clesEnseignant: string[], clesSalle: string[]} $blocData
     * @param array<int, array<string, true>> $joursUtilisesParUnite
     * @param array<int, int> $nbPremiereHeurePrefereeParUnite
     */
    private function insererBlocBrut(
        int $id,
        array $blocData,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
        array &$blocs,
        array &$joursUtilisesParUnite,
        array &$nbPremiereHeurePrefereeParUnite,
    ): void {
        $blocs[$id] = $blocData;

        foreach ($blocData['clesClasse'] as $cle) {
            $classeBusy[$cle] = $id;
        }
        foreach ($blocData['clesEnseignant'] as $cle) {
            $enseignantBusy[$cle] = $id;
        }
        foreach ($blocData['clesSalle'] as $cle) {
            $salleBusy[$cle] = $id;
        }
        $joursUtilisesParUnite[$blocData['uniteId']][$blocData['jour']] = true;

        if ($this->matchPreferencePremiereHeureMaths3eme($blocData['unite'], $blocData['groupeCreneaux'])) {
            $nbPremiereHeurePrefereeParUnite[$blocData['uniteId']] = ($nbPremiereHeurePrefereeParUnite[$blocData['uniteId']] ?? 0) + 1;
        }
    }

    /**
     * @param array<int, array<string, true>> $joursUtilisesParUnite
     * @param array<int, int> $nbPremiereHeurePrefereeParUnite
     * @return array{unite: GenerationUnit, uniteId: int, groupeCreneaux: Creneau[], jour: string, placements: array, clesClasse: string[], clesEnseignant: string[], clesSalle: string[]}
     */
    private function retirerBlocBrut(
        int $id,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
        array &$blocs,
        array &$joursUtilisesParUnite,
        array &$nbPremiereHeurePrefereeParUnite,
    ): array {
        $blocData = $blocs[$id];
        unset($blocs[$id]);

        foreach ($blocData['clesClasse'] as $cle) {
            unset($classeBusy[$cle]);
        }
        foreach ($blocData['clesEnseignant'] as $cle) {
            unset($enseignantBusy[$cle]);
        }
        foreach ($blocData['clesSalle'] as $cle) {
            unset($salleBusy[$cle]);
        }
        unset($joursUtilisesParUnite[$blocData['uniteId']][$blocData['jour']]);

        if ($this->matchPreferencePremiereHeureMaths3eme($blocData['unite'], $blocData['groupeCreneaux'])) {
            $nbPremiereHeurePrefereeParUnite[$blocData['uniteId']] = max(0, ($nbPremiereHeurePrefereeParUnite[$blocData['uniteId']] ?? 1) - 1);
        }

        return $blocData;
    }

    /**
     * Attribue une salle standard "fixe" à chaque classe pour toute la génération
     * (round-robin — si le nombre de salles standard est insuffisant, plusieurs
     * classes partagent la même salle et le solveur le traduira naturellement en
     * échecs de placement partiels, pas en double réservation silencieuse).
     *
     * @param Classe[] $classes
     * @param Salle[] $sallesStandard
     * @return array<int, Salle>
     */
    private function assignerSallesAttitrees(array $classes, array $sallesStandard): array
    {
        $mapping = [];
        $n       = count($sallesStandard);
        if ($n === 0) {
            return $mapping;
        }

        foreach (array_values($classes) as $i => $classe) {
            $mapping[$classe->getId()] = $sallesStandard[$i % $n];
        }

        return $mapping;
    }

    /** @param array<int, Salle> $classeSalleMap @param array<string, Salle[]> $sallesParType */
    private function raisonEchec(GenerationUnit $unite, array $classeSalleMap, array $sallesParType): string
    {
        foreach ($unite->attributions as $attribution) {
            $typeRequis = $attribution->getMatiere()->getSalleRequise();
            if ($typeRequis !== null && ($sallesParType[$typeRequis->value] ?? []) === []) {
                return "Aucune salle de type « {$typeRequis->label()} » disponible.";
            }
            if ($typeRequis === null && !isset($classeSalleMap[$attribution->getClasse()->getId()])) {
                return 'Aucune salle standard disponible pour cette classe.';
            }
        }

        return 'Aucun créneau disponible même après tentative de réparation locale (déplacement d\'autres séances) — conflit enseignant/classe trop contraint.';
    }

    /**
     * Bilan heures demandées / placées / capacité de créneaux par classe, pour signaler
     * un excédent structurel (demande > créneaux disponibles dans la semaine — problème
     * de données, cf. Tle A4/Philosophie) séparément d'un simple manque de placement
     * (capacité suffisante, mais conflits enseignant/salle empêchant de tout caser).
     *
     * @param GenerationUnit[] $unites
     * @param array<int, Classe> $classes
     * @param array<string, Creneau[]> $creneauxEligiblesParCycle
     * @param list<array{attribution: Attribution, creneau: Creneau, salle: Salle}> $placements
     * @return ClasseBilan[]
     */
    private function construireBilanClasses(array $unites, array $classes, array $creneauxEligiblesParCycle, array $placements): array
    {
        $demandeParClasse = [];
        foreach ($unites as $unite) {
            foreach ($unite->classes as $classe) {
                $demandeParClasse[$classe->getId()] = ($demandeParClasse[$classe->getId()] ?? 0) + $unite->heures;
            }
        }

        // Compte les créneaux DISTINCTS occupés, pas les lignes de placement brutes : une
        // matière parallèle (ex. ALL/ESP) produit 2 placements sur le MÊME créneau pour
        // la même classe (1 par attribution), qui ne doivent compter que pour 1h reçue
        // par l'élève — comme $unite->heures le fait déjà côté demande (déduplication
        // par min() dans uniteDepuisAttributions()).
        $creneauxParClasse = [];
        foreach ($placements as $p) {
            $classeId  = $p['attribution']->getClasse()->getId();
            $creneauId = $p['creneau']->getId();
            $creneauxParClasse[$classeId][$creneauId] = true;
        }
        $placeParClasse = array_map('count', $creneauxParClasse);

        $classesTriees = array_values($classes);
        usort($classesTriees, static function (Classe $a, Classe $b) {
            return [$a->getNiveau()->getOrdre(), $a->getNom()] <=> [$b->getNiveau()->getOrdre(), $b->getNom()];
        });

        $bilan = [];
        foreach ($classesTriees as $classe) {
            $classeId = $classe->getId();
            $cycle    = $classe->getNiveau()->getCycle()->getType();

            $bilan[] = new ClasseBilan(
                $classe->getNom(),
                $demandeParClasse[$classeId] ?? 0,
                $placeParClasse[$classeId] ?? 0,
                count($creneauxEligiblesParCycle[$cycle->value]),
            );
        }

        return $bilan;
    }
}
