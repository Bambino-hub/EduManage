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
 * Algorithme "meilleur effort" : plusieurs tentatives avec un ordre aléatoire des
 * unités et des créneaux candidats ; on conserve la tentative qui place le plus
 * d'heures. Pas de garantie de solution complète — les unités non placées sont
 * remontées dans le rapport pour correction manuelle des données (salles/attributions).
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

        $meilleur           = null;
        $tentativesEffectuees = 0;

        for ($tentative = 1; $tentative <= $maxRestarts; $tentative++) {
            $tentativesEffectuees = $tentative;

            $classeBusy      = [];
            $enseignantBusy  = [];
            $salleBusy       = [];
            $placementsTotal = [];
            $resultatsUnites = [];
            $heuresPlaceesTotal = 0;

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
                );

                $heuresPlaceesTotal += $resultat['heures'];
                array_push($placementsTotal, ...$resultat['placements']);

                $raisons = $resultat['heures'] < $unite->heures
                    ? [$this->raisonEchec($unite, $classeSalleMap, $sallesParType)]
                    : [];
                $resultatsUnites[] = new UnitResult($unite->libelle, $unite->heures, $resultat['heures'], $raisons);
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
     * Place une unité (tous ses blocs) ou annule tout ce qu'elle a commis pour cette
     * tentative si un seul bloc ne trouve pas de créneau libre. Chaque bloc de l'unité
     * tombe sur un jour distinct des autres (sauf le cas spécial collège 6h/semaine,
     * seul cas où un même jour porte 2 séances — voir placerUniteCollegeSixHeures).
     *
     * @param array<string, Creneau[]> $creneauxEligiblesParCycle
     * @param array<int, Salle> $classeSalleMap
     * @param array<string, Salle[]> $sallesParType
     * @return array{heures: int, placements: list<array{attribution: Attribution, creneau: Creneau, salle: Salle}>}
     */
    private function placerUnite(
        GenerationUnit $unite,
        array $creneauxEligiblesParCycle,
        array $classeSalleMap,
        array $sallesParType,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
    ): array {
        $cycle     = $unite->classes[0]->getNiveau()->getCycle()->getType();
        $eligibles = $this->filtrerFHR($unite, $creneauxEligiblesParCycle[$cycle->value]);
        $eligibles = $this->filtrerEPS($unite, $eligibles);

        if ($cycle === TypeCycle::COLLEGE && $unite->heures === 6) {
            return $this->placerUniteCollegeSixHeures($unite, $eligibles, $classeSalleMap, $sallesParType, $classeBusy, $enseignantBusy, $salleBusy);
        }

        $blocs = $this->decomposerHeures($unite, $unite->heures, $cycle);

        $placementsTotal         = [];
        $clesCommitees           = [];
        $joursUtilises           = [];
        $nbPremiereHeurePreferee = 0;

        foreach ($blocs as $taille) {
            $candidats = $taille === 1
                ? array_map(static fn (Creneau $c) => [$c], $this->shuffleArray($eligibles))
                : $this->shuffleArray($this->trouverBlocsCandidats($this->creneauxParJour($eligibles), $taille));

            $candidats = $this->trierParPreference($unite, $candidats, $nbPremiereHeurePreferee);

            $blocPlace = false;

            foreach ($candidats as $groupeCreneaux) {
                $jour = $groupeCreneaux[0]->getJourSemaine()->value;
                if (isset($joursUtilises[$jour])) {
                    continue;
                }
                if (!$this->candidatValide($unite, $groupeCreneaux, $classeBusy, $enseignantBusy)) {
                    continue;
                }
                $salles = $this->resoudreSalles($unite, $groupeCreneaux, $classeSalleMap, $sallesParType, $salleBusy);
                if ($salles === null) {
                    continue;
                }

                [$placements, $cles] = $this->commitPlacement($unite, $groupeCreneaux, $salles, $classeBusy, $enseignantBusy, $salleBusy);
                $placementsTotal      = [...$placementsTotal, ...$placements];
                $clesCommitees        = [...$clesCommitees, ...$cles];
                $joursUtilises[$jour] = true;
                $blocPlace            = true;

                if ($this->matchPreferencePremiereHeureMaths3eme($unite, $groupeCreneaux)) {
                    $nbPremiereHeurePreferee++;
                }

                break;
            }

            if (!$blocPlace) {
                $this->rollback($clesCommitees, $classeBusy, $enseignantBusy, $salleBusy);
                return ['heures' => 0, 'placements' => []];
            }
        }

        return ['heures' => $unite->heures, 'placements' => $placementsTotal];
    }

    /**
     * Cas spécial collège = 6h/semaine (seul volume collège qui dépasse les 5 jours
     * disponibles) : un jour porte 2 séances (1h le matin + 1h l'après-midi — jamais
     * adjacentes vu la coupure repas), les 4 autres jours portent chacun 1 séance.
     *
     * @param Creneau[] $eligibles
     * @param array<int, Salle> $classeSalleMap
     * @param array<string, Salle[]> $sallesParType
     * @return array{heures: int, placements: list<array{attribution: Attribution, creneau: Creneau, salle: Salle}>}
     */
    private function placerUniteCollegeSixHeures(
        GenerationUnit $unite,
        array $eligibles,
        array $classeSalleMap,
        array $sallesParType,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
    ): array {
        $parJour = $this->creneauxParJour($eligibles);
        $jours   = $this->shuffleArray(array_keys($parJour));

        foreach ($jours as $jourDouble) {
            $matin     = array_values(array_filter($parJour[$jourDouble], static fn (Creneau $c) => (int) $c->getHeureDebut()->format('H') < 13));
            $apresMidi = array_values(array_filter($parJour[$jourDouble], static fn (Creneau $c) => (int) $c->getHeureDebut()->format('H') >= 13));

            if ($matin === [] || $apresMidi === []) {
                continue;
            }

            $double = $this->tenterPlacerJourDouble(
                $unite,
                $this->shuffleArray($matin),
                $this->shuffleArray($apresMidi),
                $classeSalleMap,
                $sallesParType,
                $classeBusy,
                $enseignantBusy,
                $salleBusy,
            );
            if ($double === null) {
                continue;
            }
            [$placementsDouble, $clesDouble] = $double;

            $autresJours      = array_values(array_filter($jours, static fn ($j) => $j !== $jourDouble));
            $placementsAutres = [];
            $clesAutres       = [];
            $succes           = true;

            foreach ($autresJours as $jour) {
                $trouve = false;

                foreach ($this->shuffleArray($parJour[$jour]) as $creneau) {
                    if (!$this->candidatValide($unite, [$creneau], $classeBusy, $enseignantBusy)) {
                        continue;
                    }
                    $salles = $this->resoudreSalles($unite, [$creneau], $classeSalleMap, $sallesParType, $salleBusy);
                    if ($salles === null) {
                        continue;
                    }

                    [$placements, $cles] = $this->commitPlacement($unite, [$creneau], $salles, $classeBusy, $enseignantBusy, $salleBusy);
                    $placementsAutres = [...$placementsAutres, ...$placements];
                    $clesAutres       = [...$clesAutres, ...$cles];
                    $trouve           = true;
                    break;
                }

                if (!$trouve) {
                    $succes = false;
                    break;
                }
            }

            if ($succes) {
                return ['heures' => 6, 'placements' => [...$placementsDouble, ...$placementsAutres]];
            }

            $this->rollback([...$clesDouble, ...$clesAutres], $classeBusy, $enseignantBusy, $salleBusy);
        }

        return ['heures' => 0, 'placements' => []];
    }

    /**
     * @param Creneau[] $matin
     * @param Creneau[] $apresMidi
     * @param array<int, Salle> $classeSalleMap
     * @param array<string, Salle[]> $sallesParType
     * @return array{0: list<array{attribution: Attribution, creneau: Creneau, salle: Salle}>, 1: list<array{arr: string, cle: string}>}|null
     */
    private function tenterPlacerJourDouble(
        GenerationUnit $unite,
        array $matin,
        array $apresMidi,
        array $classeSalleMap,
        array $sallesParType,
        array &$classeBusy,
        array &$enseignantBusy,
        array &$salleBusy,
    ): ?array {
        foreach ($matin as $creneauMatin) {
            if (!$this->candidatValide($unite, [$creneauMatin], $classeBusy, $enseignantBusy)) {
                continue;
            }
            $sallesMatin = $this->resoudreSalles($unite, [$creneauMatin], $classeSalleMap, $sallesParType, $salleBusy);
            if ($sallesMatin === null) {
                continue;
            }

            foreach ($apresMidi as $creneauPm) {
                if (!$this->candidatValide($unite, [$creneauPm], $classeBusy, $enseignantBusy)) {
                    continue;
                }
                $sallesPm = $this->resoudreSalles($unite, [$creneauPm], $classeSalleMap, $sallesParType, $salleBusy);
                if ($sallesPm === null) {
                    continue;
                }

                [$placementsMatin, $clesMatin] = $this->commitPlacement($unite, [$creneauMatin], $sallesMatin, $classeBusy, $enseignantBusy, $salleBusy);
                [$placementsPm, $clesPm]       = $this->commitPlacement($unite, [$creneauPm], $sallesPm, $classeBusy, $enseignantBusy, $salleBusy);

                return [[...$placementsMatin, ...$placementsPm], [...$clesMatin, ...$clesPm]];
            }
        }

        return null;
    }

    /**
     * @param Creneau[] $groupeCreneaux
     * @param array<int, Salle> $salles clé = id de l'Attribution
     * @return array{0: list<array{attribution: Attribution, creneau: Creneau, salle: Salle}>, 1: list<array{arr: string, cle: string}>}
     */
    private function commitPlacement(GenerationUnit $unite, array $groupeCreneaux, array $salles, array &$classeBusy, array &$enseignantBusy, array &$salleBusy): array
    {
        $placements = [];
        $cles       = [];

        foreach ($groupeCreneaux as $creneau) {
            $cId = $creneau->getId();

            foreach ($unite->classes as $classe) {
                $classeCle              = "{$classe->getId()}:{$cId}";
                $classeBusy[$classeCle] = true;
                $cles[]                 = ['arr' => 'classe', 'cle' => $classeCle];
            }

            foreach ($unite->attributions as $attribution) {
                $ensCle                  = "{$attribution->getEnseignant()->getId()}:{$cId}";
                $enseignantBusy[$ensCle] = true;
                $cles[]                  = ['arr' => 'enseignant', 'cle' => $ensCle];

                $salle                = $salles[$attribution->getId()];
                $salleCle              = "{$salle->getId()}:{$cId}";
                $salleBusy[$salleCle]  = true;
                $cles[]                = ['arr' => 'salle', 'cle' => $salleCle];

                $placements[] = ['attribution' => $attribution, 'creneau' => $creneau, 'salle' => $salle];
            }
        }

        return [$placements, $cles];
    }

    /** @param list<array{arr: string, cle: string}> $cles */
    private function rollback(array $cles, array &$classeBusy, array &$enseignantBusy, array &$salleBusy): void
    {
        foreach ($cles as $c) {
            if ($c['arr'] === 'classe') {
                unset($classeBusy[$c['cle']]);
            } elseif ($c['arr'] === 'enseignant') {
                unset($enseignantBusy[$c['cle']]);
            } else {
                unset($salleBusy[$c['cle']]);
            }
        }
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

        return 'Aucun créneau disponible sans conflit après plusieurs tentatives (salle/enseignant/classe déjà pris).';
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

        $placeParClasse = [];
        foreach ($placements as $p) {
            $classeId = $p['attribution']->getClasse()->getId();
            $placeParClasse[$classeId] = ($placeParClasse[$classeId] ?? 0) + 1;
        }

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
