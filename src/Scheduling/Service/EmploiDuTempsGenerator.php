<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Academic\Entity\Salle;
use App\Academic\Enum\TypeCycle;
use App\Academic\Enum\TypeSalle;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Academic\Repository\SalleRepository;
use App\Scheduling\Entity\Attribution;
use App\Scheduling\Entity\Creneau;
use App\Scheduling\Entity\Seance;
use App\Scheduling\Enum\JourSemaine;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Service\Dto\GenerationResult;
use App\Scheduling\Service\Dto\UnitResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère l'emploi du temps hebdomadaire d'une année scolaire : pour chaque Attribution
 * (ou groupe d'Attributions parallèles), place son volume horaire dans des créneaux
 * libres, sans conflit enseignant/classe/salle.
 *
 * Algorithme "meilleur effort" : plusieurs tentatives avec un ordre aléatoire des
 * unités et des créneaux candidats ; on conserve la tentative qui place le plus
 * d'heures. Pas de garantie de solution complète — les unités non placées sont
 * remontées dans le rapport pour correction manuelle des données (salles/attributions).
 */
class EmploiDuTempsGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttributionRepository $attributionRepo,
        private readonly CreneauRepository $creneauRepo,
        private readonly SalleRepository $salleRepo,
        private readonly MatiereNiveauRepository $matiereNiveauRepo,
    ) {
    }

    public function generer(AnneeScolaire $annee, int $maxRestarts = 20): GenerationResult
    {
        $attributions = $this->attributionRepo->findByAnneeScolaire((int) $annee->getId());
        $this->purgerSeances($attributions);

        if ($attributions === []) {
            return new GenerationResult(0, 0, 0, []);
        }

        $unites              = $this->construireUnites($attributions);
        $heuresTotalDemandees = array_sum(array_map(fn (GenerationUnit $u) => $u->heures, $unites));

        $tousCreneaux              = $this->creneauRepo->findOrdonnes();
        $creneauxEligiblesParCycle = [
            TypeCycle::COLLEGE->value => $this->filtrerEligibles($tousCreneaux, TypeCycle::COLLEGE),
            TypeCycle::LYCEE->value   => $this->filtrerEligibles($tousCreneaux, TypeCycle::LYCEE),
        ];

        $classes = [];
        foreach ($unites as $unite) {
            $classes[$unite->classe->getId()] = $unite->classe;
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

            $ordreUnites = $unites;
            shuffle($ordreUnites);

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
        );
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
     * @param Attribution[] $attributions
     * @return GenerationUnit[]
     */
    private function construireUnites(array $attributions): array
    {
        $groupes = [];
        $unites  = [];

        foreach ($attributions as $attribution) {
            $groupeOptionnel = $attribution->getMatiere()->getGroupeOptionnel();
            if ($groupeOptionnel !== null) {
                $cle              = $attribution->getClasse()->getId().':'.$groupeOptionnel->value;
                $groupes[$cle][]  = $attribution;
            } else {
                $unites[] = $this->uniteDepuisAttributions($attribution->getClasse(), [$attribution]);
            }
        }

        foreach ($groupes as $attrs) {
            $unites[] = $this->uniteDepuisAttributions($attrs[0]->getClasse(), $attrs);
        }

        return $unites;
    }

    /** @param Attribution[] $attributions */
    private function uniteDepuisAttributions(Classe $classe, array $attributions): GenerationUnit
    {
        $heures = min(array_map(fn (Attribution $a) => $this->resoudreHeures($a), $attributions));

        $libelle = implode(' + ', array_map(
            fn (Attribution $a) => $a->getMatiere()->getNom().' ('.$a->getEnseignant()->getNomComplet().')',
            $attributions,
        )).' — '.$classe->getNom();

        return new GenerationUnit($classe, $attributions, $heures, $libelle);
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
     * Collège : toujours des séances isolées d'1h.
     * Lycée : mélange aléatoire de blocs de 1h/2h/3h, en favorisant les blocs de 2h.
     *
     * @return int[]
     */
    private function decomposerHeures(int $heures, TypeCycle $cycle): array
    {
        if ($heures <= 0) {
            return [];
        }

        if ($cycle === TypeCycle::COLLEGE) {
            return array_fill(0, $heures, 1);
        }

        $blocs   = [];
        $restant = $heures;

        while ($restant > 0) {
            $taille = match (true) {
                $restant === 1 => 1,
                $restant === 2 => 2,
                $restant === 3 => random_int(1, 10) <= 7 ? 2 : 3,
                default        => random_int(1, 10) <= 2 ? 3 : 2,
            };
            $blocs[]  = $taille;
            $restant -= $taille;
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
            if (isset($classeBusy["{$unite->classe->getId()}:{$cId}"])) {
                return false;
            }
            foreach ($unite->attributions as $attribution) {
                if (isset($enseignantBusy["{$attribution->getEnseignant()->getId()}:{$cId}"])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param Creneau[] $groupeCreneaux @param Creneau[] $creneauxUnite */
    private function adjacentAUniteExistante(array $groupeCreneaux, array $creneauxUnite): bool
    {
        foreach ($groupeCreneaux as $candidat) {
            foreach ($creneauxUnite as $existant) {
                if ($existant->getJourSemaine() === $candidat->getJourSemaine()
                    && abs($existant->getOrdre() - $candidat->getOrdre()) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Résout une salle par Attribution du groupe (attitrée pour les matières standards,
     * cherchée dans le pool spécialisé sinon), libre sur TOUS les créneaux du bloc.
     *
     * @param Creneau[] $groupeCreneaux
     * @param array<int, Salle> $classeSalleMap
     * @param array<string, Salle[]> $sallesParType
     * @return array<int, Salle>|null clé = id de l'Attribution
     */
    private function resoudreSalles(GenerationUnit $unite, array $groupeCreneaux, array $classeSalleMap, array $sallesParType, array $salleBusy): ?array
    {
        $resultat        = [];
        $sallesRetenues   = []; // ids déjà pris DANS cette résolution — un groupe parallèle a besoin
                                 // de salles distinctes pour ses membres simultanés, jamais la même deux fois

        foreach ($unite->attributions as $attribution) {
            $typeRequis = $attribution->getMatiere()->getSalleRequise();

            if ($typeRequis === null) {
                // La salle attitrée de la classe est essayée en priorité ; si elle est déjà prise
                // par un autre membre du même groupe parallèle (ex. ALL a pris la salle de la classe,
                // ESP a besoin d'une autre salle standard au même moment), on pioche dans le pool.
                $salleAttitree = $classeSalleMap[$unite->classe->getId()] ?? null;
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
            $resultat[$attribution->getId()] = $trouve;
            $sallesRetenues[]                = $trouve->getId();
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
     * tentative si un seul bloc ne trouve pas de créneau libre.
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
        $cycle     = $unite->classe->getNiveau()->getCycle()->getType();
        $eligibles = $creneauxEligiblesParCycle[$cycle->value];
        $blocs     = $this->decomposerHeures($unite->heures, $cycle);

        $placements     = [];
        $clesCommitees  = [];
        $creneauxUnite  = []; // créneaux déjà retenus par CETTE unité durant cette tentative

        foreach ($blocs as $taille) {
            $candidats = $taille === 1
                ? array_map(static fn (Creneau $c) => [$c], $this->shuffleArray($eligibles))
                : $this->shuffleArray($this->trouverBlocsCandidats($this->creneauxParJour($eligibles), $taille));

            $blocPlace = false;

            foreach ($candidats as $groupeCreneaux) {
                if (!$this->candidatValide($unite, $groupeCreneaux, $classeBusy, $enseignantBusy)) {
                    continue;
                }
                // Un même sujet ne doit jamais former un bloc plus grand que celui décomposé :
                // on refuse un candidat collé (même jour, période adjacente) à un bloc déjà placé
                // pour cette unité, sinon deux séances isolées d'1h finissent accolées en 2h.
                if ($this->adjacentAUniteExistante($groupeCreneaux, $creneauxUnite)) {
                    continue;
                }
                $salles = $this->resoudreSalles($unite, $groupeCreneaux, $classeSalleMap, $sallesParType, $salleBusy);
                if ($salles === null) {
                    continue;
                }
                $creneauxUnite = [...$creneauxUnite, ...$groupeCreneaux];

                foreach ($groupeCreneaux as $creneau) {
                    $cId = $creneau->getId();

                    $classeCle              = "{$unite->classe->getId()}:{$cId}";
                    $classeBusy[$classeCle] = true;
                    $clesCommitees[]        = ['arr' => 'classe', 'cle' => $classeCle];

                    foreach ($unite->attributions as $attribution) {
                        $ensCle                     = "{$attribution->getEnseignant()->getId()}:{$cId}";
                        $enseignantBusy[$ensCle]     = true;
                        $clesCommitees[]             = ['arr' => 'enseignant', 'cle' => $ensCle];

                        $salle      = $salles[$attribution->getId()];
                        $salleCle   = "{$salle->getId()}:{$cId}";
                        $salleBusy[$salleCle] = true;
                        $clesCommitees[]      = ['arr' => 'salle', 'cle' => $salleCle];

                        $placements[] = ['attribution' => $attribution, 'creneau' => $creneau, 'salle' => $salle];
                    }
                }

                $blocPlace = true;
                break;
            }

            if (!$blocPlace) {
                foreach ($clesCommitees as $c) {
                    if ($c['arr'] === 'classe') {
                        unset($classeBusy[$c['cle']]);
                    } elseif ($c['arr'] === 'enseignant') {
                        unset($enseignantBusy[$c['cle']]);
                    } else {
                        unset($salleBusy[$c['cle']]);
                    }
                }

                return ['heures' => 0, 'placements' => []];
            }
        }

        return ['heures' => $unite->heures, 'placements' => $placements];
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
            if ($typeRequis === null && !isset($classeSalleMap[$unite->classe->getId()])) {
                return 'Aucune salle standard disponible pour cette classe.';
            }
        }

        return 'Aucun créneau disponible sans conflit après plusieurs tentatives (salle/enseignant/classe déjà pris).';
    }
}
