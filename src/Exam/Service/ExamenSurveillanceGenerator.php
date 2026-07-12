<?php

declare(strict_types=1);

namespace App\Exam\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Academic\Entity\Cycle;
use App\Academic\Entity\Niveau;
use App\Academic\Enum\DomaineMatiere;
use App\Academic\Repository\ClasseRepository;
use App\Exam\Entity\Examen;
use App\Exam\Entity\Surveillance;
use App\Exam\Repository\ExamenRepository;
use App\Exam\Repository\RegroupementSurveillanceRepository;
use App\Exam\Repository\SurveillanceRepository;
use App\Exam\Service\Dto\GenerationResultSurveillance;
use App\Exam\Service\Dto\PosteNonPourvu;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Staff\Entity\Enseignant;
use App\Staff\Repository\EnseignantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère automatiquement le tableau de surveillance : affecte des enseignants aux classes de
 * chaque examen de l'année scolaire, **tous cycles confondus en un seul passage** (voir
 * décision ci-dessous), en respectant :
 *  - la disponibilité réelle (pas déjà en cours normal à ce moment, croisé avec la grille
 *    Creneau existante via Examen::getJourSemaine()) — contrainte dure ;
 *  - le pool éligible : enseignants **internes et stagiaires actifs** (ni externes, ni
 *    personnel non-enseignant) ;
 *  - la priorité, dans l'ordre, à (1) un enseignant de la matière exacte de l'examen, (2) à
 *    défaut un enseignant du même domaine (scientifique/littéraire), (3) à défaut n'importe
 *    quel enseignant du pool — avec un **quota minimum ciblé** (jusqu'à 3) d'enseignants de la
 *    matière exacte forcé en priorité absolue sur l'ensemble de l'examen (toutes classes
 *    confondues), tant qu'il reste des candidats de cette matière disponibles ;
 *  - la priorité aux enseignants du **même cycle** que l'examen (déduit des niveaux de
 *    l'examen), complétée par l'autre cycle en cas de manque, sans jamais casser l'équilibrage
 *    au-delà d'une tolérance ;
 *  - l'équilibrage du nombre de surveillances entre enseignants sur **toute l'année, les deux
 *    cycles confondus** ;
 *  - les classes réunies via `RegroupementSurveillance` (ex. 1ère C + 1ère D1, physiquement
 *    dans la même salle) reçoivent toujours le(s) même(s) surveillant(s) — un seul jeu de
 *    postes pour le groupe, pas un par classe.
 *
 * DÉCISION : générer cycle par cycle indépendamment a été essayé puis abandonné (mesuré le
 * 2026-07-12) — le cycle généré EN PREMIER atteignait systématiquement ~100% de postes pourvus
 * tandis que le second, généré avec un pool déjà partiellement consommé par des enseignants
 * "1/2" (partagés entre les deux cycles), plafonnait à ~65-70%. Un seul passage sur TOUS les
 * examens de l'année (mélangés, pas traités cycle par cycle) élimine cet effet d'ordre : les
 * deux cycles sont alors en compétition équitable pour le même pool partagé pendant toute la
 * génération, au lieu que l'un monopolise les enseignants "1/2" avant que l'autre ne démarre.
 */
class ExamenSurveillanceGenerator
{
    /** Écart de charge au-delà duquel on préfère un enseignant de l'autre cycle moins chargé. */
    private const TOLERANCE_CYCLE = 2;

    /** Écart de charge au-delà duquel on préfère un enseignant hors de la matière/du domaine préféré. */
    private const TOLERANCE_EQUILIBRAGE = 2;

    /** Nombre d'enseignants de la matière exacte visé par examen (toutes classes confondues). */
    private const QUOTA_MEME_MATIERE_CIBLE = 3;

    public function __construct(
        private readonly ExamenRepository $examenRepo,
        private readonly SeanceRepository $seanceRepo,
        private readonly EnseignantRepository $enseignantRepo,
        private readonly AttributionRepository $attributionRepo,
        private readonly SurveillanceRepository $surveillanceRepo,
        private readonly ClasseRepository $classeRepo,
        private readonly RegroupementSurveillanceRepository $regroupementRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Génère (ou régénère) le tableau de surveillance des DEUX cycles pour l'année donnée. */
    public function genererPourAnnee(AnneeScolaire $annee): GenerationResultSurveillance
    {
        $examens   = $this->examenRepo->findByAnnee($annee);
        $examenIds = array_map(static fn(Examen $e) => $e->getId(), $examens);

        foreach ($this->surveillanceRepo->findByExamens($examenIds) as $ancienne) {
            $this->em->remove($ancienne);
        }
        $this->em->flush();

        $pool = $this->enseignantRepo->findEligiblesSurveillance();
        if ($pool === [] || $examens === []) {
            return new GenerationResultSurveillance(count($examens), 0, 0, []);
        }

        // Répartition volontairement aléatoire : sans ce mélange, l'ordre alphabétique du pool
        // départageait toujours les ex-æquo de charge de la même façon → un clic sur "régénérer"
        // produisait exactement le même tableau. Les règles (cascade matière/domaine/cycle,
        // quota, non-conflit) restent intactes, seul l'ordre de résolution des égalités change.
        // Mélanger $examens (au lieu de les traiter cycle par cycle) est ce qui garantit
        // l'équité entre les deux cycles — voir la décision documentée au-dessus de la classe.
        shuffle($pool);
        shuffle($examens);

        [$enseigne, $domainesEnseignant] = $this->construireDonneesAttributions($annee);
        $classesActives   = $this->classeRepo->findByAnneeScolaireActive();
        $groupeParClasseId = $this->regroupementRepo->findGroupeParClasseId();

        $chargeParEnseignant          = [];
        $examensAffectesParEnseignant = [];
        foreach ($pool as $enseignant) {
            $chargeParEnseignant[$enseignant->getId()] = 0;
        }

        $surveillancesCreees   = 0;
        $postesRequis          = 0;
        $postesNonPourvus      = [];
        $matiereCountParExamen = [];

        foreach ($examens as $examen) {
            $jour       = $examen->getJourSemaine();
            $occupesEdt = $jour !== null
                ? array_flip($this->seanceRepo->findEnseignantIdsOccupes($jour, $examen->getHeureDebut(), $examen->getHeureFin()))
                : [];

            $niveauIds         = array_map(static fn(Niveau $n) => $n->getId(), $examen->getNiveaux()->toArray());
            $classesConcernees = array_values(array_filter(
                $classesActives,
                static fn(Classe $c) => in_array($c->getNiveau()->getId(), $niveauIds, true),
            ));

            $unites        = $this->regrouperClasses($classesConcernees, $groupeParClasseId);
            $matiereId     = $examen->getMatiere()?->getId();
            $premierNiveau = $examen->getNiveaux()->first();
            $cycle         = $premierNiveau !== false ? $premierNiveau->getCycle() : null;
            shuffle($unites);

            foreach ($unites as $unite) {
                $requis                = $examen->getNombreSurveillantsParClasse();
                $dejaAffecteCetteUnite = [];

                for ($i = 0; $i < $requis; $i++) {
                    $postesRequis++;

                    $quotaAtteint = ($matiereCountParExamen[$examen->getId()] ?? 0) >= self::QUOTA_MEME_MATIERE_CIBLE;

                    $candidat = $this->meilleurCandidat(
                        $pool,
                        $cycle,
                        $occupesEdt,
                        $examensAffectesParEnseignant,
                        $dejaAffecteCetteUnite,
                        $chargeParEnseignant,
                        $enseigne,
                        $domainesEnseignant,
                        $examen,
                        $quotaAtteint,
                    );

                    if ($candidat === null) {
                        $postesNonPourvus[] = new PosteNonPourvu($examen->getLabel(), $this->nomUnite($unite), $requis - $i);
                        break;
                    }

                    foreach ($unite as $classe) {
                        $surveillance = new Surveillance();
                        $surveillance->setExamen($examen);
                        $surveillance->setClasse($classe);
                        $surveillance->setEnseignant($candidat);
                        $this->em->persist($surveillance);
                    }

                    $id                                    = $candidat->getId();
                    $examensAffectesParEnseignant[$id][]    = $examen;
                    $dejaAffecteCetteUnite[$id]             = true;
                    $chargeParEnseignant[$id]              += 1;
                    $surveillancesCreees++;

                    if (!empty($enseigne[$id][$matiereId])) {
                        $matiereCountParExamen[$examen->getId()] = ($matiereCountParExamen[$examen->getId()] ?? 0) + 1;
                    }
                }
            }
        }

        $this->em->flush();

        return new GenerationResultSurveillance(count($examens), $postesRequis, $surveillancesCreees, $postesNonPourvus);
    }

    /**
     * Regroupe les classes concernées par un examen selon `RegroupementSurveillance` : les
     * classes d'un même groupe présentes dans la liste sont fusionnées en une seule "unité"
     * (un seul jeu de postes, le(s) même(s) surveillant(s) affecté(s) à chacune). Une classe
     * sans regroupement reste seule dans sa propre unité.
     *
     * @param Classe[] $classes
     * @param array<int, int> $groupeParClasseId
     * @return Classe[][]
     */
    private function regrouperClasses(array $classes, array $groupeParClasseId): array
    {
        $unites   = [];
        $dejaVues = [];

        foreach ($classes as $classe) {
            $id = $classe->getId();
            if (isset($dejaVues[$id])) {
                continue;
            }

            $groupeId = $groupeParClasseId[$id] ?? null;
            if ($groupeId === null) {
                $unites[]      = [$classe];
                $dejaVues[$id] = true;
                continue;
            }

            $membres = array_values(array_filter(
                $classes,
                static fn(Classe $c) => ($groupeParClasseId[$c->getId()] ?? null) === $groupeId,
            ));
            foreach ($membres as $membre) {
                $dejaVues[$membre->getId()] = true;
            }
            $unites[] = $membres;
        }

        return $unites;
    }

    /** @param Classe[] $unite */
    private function nomUnite(array $unite): string
    {
        return implode(' + ', array_map(static fn(Classe $c) => $c->getNom(), $unite));
    }

    /**
     * @param Enseignant[] $pool
     * @param array<int, bool> $occupesEdt
     * @param array<int, Examen[]> $examensAffectesParEnseignant
     * @param array<int, bool> $dejaAffecteCetteUnite
     * @param array<int, int> $chargeParEnseignant
     * @param array<int, array<int, array<int, bool>>> $enseigne id enseignant => matiereId => niveauId => true
     * @param array<int, array<string, bool>> $domainesEnseignant id enseignant => domaine->value => true
     */
    private function meilleurCandidat(
        array $pool,
        ?Cycle $cycle,
        array $occupesEdt,
        array $examensAffectesParEnseignant,
        array $dejaAffecteCetteUnite,
        array $chargeParEnseignant,
        array $enseigne,
        array $domainesEnseignant,
        Examen $examen,
        bool $quotaMatiereAtteint,
    ): ?Enseignant {
        $disponibles = array_values(array_filter(
            $pool,
            static function (Enseignant $e) use ($occupesEdt, $examensAffectesParEnseignant, $dejaAffecteCetteUnite, $examen): bool {
                $id = $e->getId();
                if (isset($occupesEdt[$id]) || isset($dejaAffecteCetteUnite[$id])) {
                    return false;
                }
                foreach ($examensAffectesParEnseignant[$id] ?? [] as $autre) {
                    if ($examen->chevauche($autre)) {
                        return false;
                    }
                }
                return true;
            },
        ));

        if ($disponibles === []) {
            return null;
        }

        return $this->meilleurParSujetEtCharge($disponibles, $cycle, $chargeParEnseignant, $enseigne, $domainesEnseignant, $examen, $quotaMatiereAtteint);
    }

    /**
     * Un enseignant n'est éligible qu'en secours si son cycle est confirmé et différent de
     * celui de l'examen. Si l'examen n'a lui-même aucun cycle déterminable (cas théorique,
     * niveau sans cycle), personne n'est relégué en secours.
     */
    private function estCycleFallbackUniquement(Enseignant $e, ?Cycle $cycle): bool
    {
        if ($cycle === null) {
            return false;
        }

        $valeur = trim((string) $e->getCycle());
        if ($valeur === '' || $valeur === '1/2') {
            return false;
        }

        return $valeur !== (string) $cycle->getId();
    }

    /**
     * Cascade à 3 niveaux : (1) même matière exacte que l'examen, (2) à défaut même domaine
     * (scientifique/littéraire/autre), (3) à défaut n'importe qui du pool reçu. Tant que le
     * quota d'enseignants de la matière exacte n'est pas atteint sur cet examen, le niveau 1
     * est forcé en priorité absolue (pas de tolérance d'équilibrage) dès qu'un candidat y est
     * disponible — au-delà du quota, ou si le niveau 1 est vide, on repasse en préférence
     * souple habituelle (tolérance de charge).
     *
     * IMPORTANT : la matière/le domaine est le critère PRINCIPAL, le cycle n'est qu'un
     * départage SECONDAIRE appliqué à l'intérieur de chaque niveau (voir
     * `meilleurParCycleEtCharge`) — jamais l'inverse. Avant correction (2026-07-12), le cycle
     * était filtré en premier : un examen de SVT (5 profs au total dans l'établissement) s'est
     * retrouvé affecté à un professeur de Philosophie du bon cycle plutôt qu'à un professeur
     * scientifique de l'autre cycle, alors qu'aucun prof de SVT/scientifique n'était disponible
     * dans le cycle de l'examen à cet horaire précis — la préférence de cycle prenait le pas
     * sur la préférence de matière, ce qui est l'inverse de la règle métier voulue.
     *
     * @param Enseignant[] $enseignants
     * @param array<int, int> $chargeParEnseignant
     * @param array<int, array<int, array<int, bool>>> $enseigne
     * @param array<int, array<string, bool>> $domainesEnseignant
     */
    private function meilleurParSujetEtCharge(
        array $enseignants,
        ?Cycle $cycle,
        array $chargeParEnseignant,
        array $enseigne,
        array $domainesEnseignant,
        Examen $examen,
        bool $quotaMatiereAtteint,
    ): ?Enseignant {
        if ($enseignants === []) {
            return null;
        }

        $matiereId = $examen->getMatiere()?->getId();
        $domaine   = $examen->getMatiere()?->getDomaine();

        $memeMatiere = array_values(array_filter($enseignants, static fn(Enseignant $e) => !empty($enseigne[$e->getId()][$matiereId])));
        $reste       = array_values(array_filter($enseignants, static fn(Enseignant $e) => empty($enseigne[$e->getId()][$matiereId])));

        $meilleurMemeMatiere = $this->meilleurParCycleEtCharge($memeMatiere, $cycle, $chargeParEnseignant);

        if (!$quotaMatiereAtteint && $meilleurMemeMatiere !== null) {
            return $meilleurMemeMatiere;
        }

        if ($domaine === null) {
            return $this->combinerPriorite($meilleurMemeMatiere, $this->meilleurParCycleEtCharge($reste, $cycle, $chargeParEnseignant), $chargeParEnseignant, self::TOLERANCE_EQUILIBRAGE);
        }

        $memeDomaine = array_values(array_filter($reste, static fn(Enseignant $e) => !empty($domainesEnseignant[$e->getId()][$domaine->value])));
        $autres      = array_values(array_filter($reste, static fn(Enseignant $e) => empty($domainesEnseignant[$e->getId()][$domaine->value])));

        $meilleurDomaine = $this->meilleurParCycleEtCharge($memeDomaine, $cycle, $chargeParEnseignant);
        $meilleurAutres  = $this->meilleurParCycleEtCharge($autres, $cycle, $chargeParEnseignant);

        $meilleurMatiereOuDomaine = $this->combinerPriorite($meilleurMemeMatiere, $meilleurDomaine, $chargeParEnseignant, self::TOLERANCE_EQUILIBRAGE);

        return $this->combinerPriorite($meilleurMatiereOuDomaine, $meilleurAutres, $chargeParEnseignant, self::TOLERANCE_EQUILIBRAGE);
    }

    /**
     * Départage SECONDAIRE par cycle à l'intérieur d'un groupe déjà filtré par matière/domaine
     * (voir note d'architecture ci-dessus) : préfère le même cycle que l'examen, complété par
     * l'autre cycle en cas de manque, sans jamais casser l'équilibrage au-delà d'une tolérance.
     *
     * @param Enseignant[] $enseignants
     * @param array<int, int> $chargeParEnseignant
     */
    private function meilleurParCycleEtCharge(array $enseignants, ?Cycle $cycle, array $chargeParEnseignant): ?Enseignant
    {
        if ($enseignants === []) {
            return null;
        }

        $memeCycle  = array_values(array_filter($enseignants, fn(Enseignant $e) => !$this->estCycleFallbackUniquement($e, $cycle)));
        $autreCycle = array_values(array_filter($enseignants, fn(Enseignant $e) => $this->estCycleFallbackUniquement($e, $cycle)));

        return $this->combinerPriorite(
            $this->parMoindreCharge($memeCycle, $chargeParEnseignant),
            $this->parMoindreCharge($autreCycle, $chargeParEnseignant),
            $chargeParEnseignant,
            self::TOLERANCE_CYCLE,
        );
    }

    /**
     * Choisit entre deux candidats déjà résolus (un "prioritaire" et un "secondaire") selon la
     * charge : le prioritaire l'emporte sauf s'il est plus chargé que le secondaire au-delà de
     * la tolérance donnée — même mécanisme réutilisé pour la priorité cycle et matière/domaine.
     */
    private function combinerPriorite(?Enseignant $prioritaire, ?Enseignant $secondaire, array $chargeParEnseignant, int $tolerance): ?Enseignant
    {
        if ($prioritaire === null) {
            return $secondaire;
        }
        if ($secondaire === null) {
            return $prioritaire;
        }

        $ecart = $chargeParEnseignant[$prioritaire->getId()] - $chargeParEnseignant[$secondaire->getId()];

        return $ecart <= $tolerance ? $prioritaire : $secondaire;
    }

    /** @param Enseignant[] $enseignants */
    private function parMoindreCharge(array $enseignants, array $chargeParEnseignant): ?Enseignant
    {
        $meilleur       = null;
        $meilleureCharge = null;
        foreach ($enseignants as $enseignant) {
            $charge = $chargeParEnseignant[$enseignant->getId()];
            if ($meilleureCharge === null || $charge < $meilleureCharge) {
                $meilleur        = $enseignant;
                $meilleureCharge = $charge;
            }
        }

        return $meilleur;
    }

    /**
     * Précharge, en une seule requête, les données d'Attribution de l'année (les deux cycles)
     * pour construire deux index en mémoire plutôt que d'interroger la base à chaque candidat.
     *
     * @return array{0: array<int, array<int, array<int, bool>>>, 1: array<int, array<string, bool>>}
     */
    private function construireDonneesAttributions(AnneeScolaire $annee): array
    {
        $enseigne           = [];
        $domainesEnseignant = [];

        foreach ($this->attributionRepo->findByAnneeScolaire((int) $annee->getId()) as $attribution) {
            $enseignantId = $attribution->getEnseignant()->getId();
            $matiere      = $attribution->getMatiere();
            $niveauId     = $attribution->getClasse()->getNiveau()->getId();
            $domaine      = $matiere->getDomaine();

            $enseigne[$enseignantId][$matiere->getId()][$niveauId] = true;

            if ($domaine instanceof DomaineMatiere) {
                $domainesEnseignant[$enseignantId][$domaine->value] = true;
            }
        }

        return [$enseigne, $domainesEnseignant];
    }
}
