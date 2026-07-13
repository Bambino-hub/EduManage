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
use App\Staff\Entity\Enseignant;
use App\Staff\Repository\EnseignantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère automatiquement le tableau de surveillance : affecte des enseignants aux classes de
 * chaque examen de l'année scolaire, **tous cycles confondus en un seul passage** (voir
 * décision ci-dessous), en respectant :
 *  - la seule contrainte de disponibilité réelle en période d'examens : ne pas déjà surveiller
 *    un AUTRE examen dont l'horaire chevauche (`Examen::chevauche()`) — les cours normaux sont
 *    suspendus pendant les examens, donc la grille Creneau habituelle n'est plus pertinente ici
 *    (vérifiée jusqu'au 2026-07-13, retirée ce jour-là — voir décision "cours suspendus" ci-dessous) ;
 *  - le pool éligible : enseignants **internes dont le poste est un poste d'enseignement, et
 *    stagiaires actifs** (ni externes, ni personnel non-enseignant même de type "interne" —
 *    Censeur, Économe, Secrétaire, etc.) ;
 *  - l'ÉQUITÉ DE CHARGE avant tout : à chaque poste à pourvoir, seuls les candidats dont la
 *    charge actuelle est au plus `TOLERANCE_EQUILIBRAGE` au-dessus du minimum parmi les
 *    disponibles ("bande d'équité") sont considérés — jamais quelqu'un de plus chargé, quelle
 *    que soit sa pertinence de matière (voir décision "priorité à l'équité" ci-dessous) ;
 *  - à l'intérieur de cette bande d'équité seulement, priorité à (1) un enseignant de la
 *    matière exacte de l'examen, (2) à défaut un enseignant du même domaine
 *    (scientifique/littéraire), (3) à défaut n'importe qui de la bande ;
 *  - la règle de cycle DURE (voir décision ci-dessous) : un enseignant rattaché à un seul cycle
 *    (`Enseignant::cycle` = "1" ou "2") ne surveille JAMAIS l'autre cycle ; un enseignant partagé
 *    ("1/2", ou cycle non renseigné) peut surveiller les deux ;
 *  - l'équilibrage du nombre de surveillances entre enseignants sur **toute l'année, les deux
 *    cycles confondus** ;
 *  - les classes réunies via `RegroupementSurveillance` (ex. 1ère C + 1ère D1, physiquement
 *    dans la même salle) reçoivent toujours le(s) même(s) surveillant(s) — un seul jeu de
 *    postes pour le groupe, pas un par classe.
 *
 * DÉCISION (cycle par cycle vs global) : générer cycle par cycle indépendamment a été essayé
 * puis abandonné (mesuré le 2026-07-12) — le cycle généré EN PREMIER atteignait systématiquement
 * ~100% de postes pourvus tandis que le second, généré avec un pool déjà partiellement consommé
 * par des enseignants "1/2" (partagés entre les deux cycles), plafonnait à ~65-70%. Un seul
 * passage sur TOUS les examens de l'année (mélangés, pas traités cycle par cycle) élimine cet
 * effet d'ordre : les deux cycles sont alors en compétition équitable pour le même pool partagé
 * pendant toute la génération, au lieu que l'un monopolise les enseignants "1/2" avant que
 * l'autre ne démarre.
 *
 * DÉCISION (règle de cycle dure + suppression de la vérification EDT, 2026-07-13) : l'utilisateur
 * a demandé si bloquer STRICTEMENT un enseignant mono-cycle sur son propre cycle améliorerait
 * l'équité de charge. Mesuré (générateur dupliqué temporairement, code supprimé après coup) : en
 * gardant la vérification EDT normale, la règle dure faisait chuter le remplissage à 181/223
 * postes (42 non pourvus, concentrés sur le cycle 2 dont le pool dédié est trop petit face à sa
 * charge). L'utilisateur a alors fait remarquer que les cours normaux sont SUSPENDUS pendant les
 * examens — la vérification EDT (grille Creneau habituelle) n'a donc plus lieu d'être : seul le
 * non-chevauchement avec une AUTRE surveillance reste une vraie contrainte. Une fois la
 * vérification EDT retirée, la règle dure atteint 223/223 (100%) ET améliore l'écart-type de
 * charge par rapport à la préférence souple précédente — mais voir la décision suivante : cet
 * écart-type restait trompeur, l'écart RÉEL min/max était encore trop grand pour être perçu
 * comme juste par un humain qui compte les postes un par un.
 *
 * DÉCISION (priorité à l'équité, refonte 2026-07-13, même session) : l'utilisateur a compté
 * lui-même le nombre de surveillances par enseignant et signalé une vraie injustice — certains à
 * 3, d'autres à 6-7, "si c'est une surveillance de surplus c'est acceptable, au-delà c'est
 * injuste". Root cause identifiée en deux temps :
 *  1. Le "quota minimum 3 enseignants de la matière exacte" forçait un enseignant de la matière
 *     en priorité ABSOLUE (sans aucune vérification de charge) tant que le quota de l'examen
 *     n'était pas atteint — un enseignant d'une matière peu pourvue en pool (ex. Informatique, 2-3
 *     personnes) se voyait donc réaffecté sans limite à chaque examen de sa matière, quel que soit
 *     son écart de charge avec le reste du pool.
 *  2. Même une fois le quota atteint, la comparaison matière-vs-reste puis domaine-vs-reste
 *     utilisait CHACUNE sa propre tolérance de 2 en cascade (`combinerPriorite` imbriqué deux
 *     fois) : l'écart final toléré entre le candidat choisi et le vrai minimum du pool pouvait
 *     donc composer jusqu'à 4, pas 2.
 * Corrigé en remplaçant toute la cascade par une "bande d'équité" calculée UNE SEULE FOIS par
 * poste (candidats dont la charge ≤ minimum des disponibles + `TOLERANCE_EQUILIBRAGE`), la
 * préférence matière/domaine ne s'appliquant plus JAMAIS en dehors de cette bande — plus de
 * dérogation "quota" qui outrepasse l'équité, plus de composition de tolérances.
 *
 * `TOLERANCE_EQUILIBRAGE` testé en conditions réelles à 1 PUIS à 0 (plusieurs régénérations
 * consécutives à chaque valeur, mesurées) : à 1, l'écart min/max observé par groupe de cycle
 * restait à 2-3 (occasionnellement 4) — encore perceptible comme injuste en comptage manuel. À 0
 * (un candidat n'est retenu que s'il est EXACTEMENT au minimum de charge des disponibles pour ce
 * poste ; la préférence matière/domaine ne sert alors qu'à départager d'authentiques ex-æquo),
 * l'écart observé descend à 1-2 de façon quasi systématique, pour un coût mesuré négligeable sur
 * la pertinence pédagogique (taux d'affectation en matière exacte : 60/208 ≈ 29% à tolérance 1,
 * contre 53/208 ≈ 25% à tolérance 0). Retenu à 0 : l'équité prime désormais explicitement sur la
 * préférence de matière dès qu'elles entrent en conflit.
 *
 * DÉCISION (passe de rééquilibrage final, 2026-07-13, même session) : même à tolérance 0, un
 * écart final de 2 restait courant (ex. 7 enseignants à 6, 9 à 4, le reste à 5) — la bande
 * d'équité ne regarde que le minimum DISPONIBLE pour un poste donné à un instant T, elle ne peut
 * pas revenir en arrière si les disponibilités réelles (chevauchements d'examens) ont empêché
 * d'atteindre le minimum global à ce moment précis. L'utilisateur a demandé si l'écart pouvait
 * descendre à 0, ou à défaut 1. Ajout de `reequilibrer()`, exécutée une fois la génération
 * gloutonne terminée : VISE l'égalité parfaite (max === min) — tant que ce n'est pas le cas,
 * cherche un échange enseignant-le-plus-chargé ↔ enseignant-le-moins-chargé sur un examen que le
 * second peut reprendre (cycle valide, pas de chevauchement, pas déjà sur cet examen) et
 * l'applique, jusqu'à ce qu'aucun échange de ce type ne soit plus possible. Ne déplace jamais une
 * surveillance seule d'un `RegroupementSurveillance` — tout le groupe de lignes de l'examen
 * concerné migre ensemble vers le nouvel enseignant.
 *
 * Un écart de 0 n'est mathématiquement atteignable QUE si le total de postes est divisible par
 * la taille du pool (ex. 208 postes / 42 enseignants = 4,95 → même dans le meilleur des cas,
 * 40 personnes à 5 et 2 à 4, écart plancher = 1, quelles que soient les disponibilités). Dans ce
 * cas `reequilibrer()` converge vers 1 et s'arrête proprement (aucun swap ne peut plus réduire
 * l'écart). Un écart final > 1 malgré la passe indique une vraie contrainte de disponibilité
 * (chevauchements d'examens qui empêchent tout transfert supplémentaire), pas un bug.
 */
class ExamenSurveillanceGenerator
{
    /**
     * Écart de charge maximum toléré, au-dessus du minimum parmi les candidats disponibles pour
     * un poste donné, pour rester dans la "bande d'équité" de ce poste. Voir la décision
     * "priorité à l'équité" ci-dessus pour la comparaison chiffrée à 1 vs 0 qui a mené à ce choix.
     */
    private const TOLERANCE_EQUILIBRAGE = 0;

    public function __construct(
        private readonly ExamenRepository $examenRepo,
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
        $poolParId                    = [];
        foreach ($pool as $enseignant) {
            $chargeParEnseignant[$enseignant->getId()] = 0;
            $poolParId[$enseignant->getId()]           = $enseignant;
        }

        $surveillancesCreees              = 0;
        $postesRequis                     = 0;
        $postesNonPourvus                 = [];
        $surveillancesParExamenEtEnseignant = [];

        foreach ($examens as $examen) {
            $niveauIds         = array_map(static fn(Niveau $n) => $n->getId(), $examen->getNiveaux()->toArray());
            $classesConcernees = array_values(array_filter(
                $classesActives,
                static fn(Classe $c) => in_array($c->getNiveau()->getId(), $niveauIds, true),
            ));

            $unites        = $this->regrouperClasses($classesConcernees, $groupeParClasseId);
            $premierNiveau = $examen->getNiveaux()->first();
            $cycle         = $premierNiveau !== false ? $premierNiveau->getCycle() : null;
            shuffle($unites);

            foreach ($unites as $unite) {
                $requis                = $examen->getNombreSurveillantsParClasse();
                $dejaAffecteCetteUnite = [];

                for ($i = 0; $i < $requis; $i++) {
                    $postesRequis++;

                    $candidat = $this->meilleurCandidat(
                        $pool,
                        $cycle,
                        $examensAffectesParEnseignant,
                        $dejaAffecteCetteUnite,
                        $chargeParEnseignant,
                        $enseigne,
                        $domainesEnseignant,
                        $examen,
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

                        $surveillancesParExamenEtEnseignant[$examen->getId()][$candidat->getId()][] = $surveillance;
                    }

                    $id                                    = $candidat->getId();
                    $examensAffectesParEnseignant[$id][]    = $examen;
                    $dejaAffecteCetteUnite[$id]             = true;
                    $chargeParEnseignant[$id]              += 1;
                    $surveillancesCreees++;
                }
            }
        }

        $this->reequilibrer($poolParId, $enseigne, $chargeParEnseignant, $examensAffectesParEnseignant, $surveillancesParExamenEtEnseignant);

        $this->em->flush();

        return new GenerationResultSurveillance(count($examens), $postesRequis, $surveillancesCreees, $postesNonPourvus);
    }

    /**
     * Passe de rééquilibrage post-génération : cherche, pour l'enseignant le plus chargé, un
     * examen qu'il couvre et qu'un enseignant STRICTEMENT moins chargé (pas forcément au minimum
     * absolu du pool) pourrait reprendre — même cycle, pas de chevauchement, pas déjà affecté à
     * cet examen — et effectue l'échange. Répété jusqu'à ce qu'aucun échange amélioreur n'existe
     * plus. Ne pas exiger le minimum absolu est essentiel : la règle de cycle DURE sépare le pool
     * en sous-groupes (mono-cycle 1, mono-cycle 2, partagés "1/2") qui ne peuvent PAS toujours
     * s'échanger directement entre eux — un enseignant mono-cycle-2 chargé ne peut jamais reprendre
     * la place d'un mono-cycle-1 sous-chargé. En acceptant des échanges vers n'importe quel
     * enseignant moins chargé (pas seulement le minimum global), les enseignants "1/2" servent de
     * passerelle : chaque échange fait progressivement redescendre le maximum, même s'il faut
     * plusieurs échanges en chaîne pour atteindre l'équilibre réellement atteignable compte tenu de
     * cette séparation de cycles. Voir décision "passe de rééquilibrage final" en tête de fichier.
     *
     * @param array<int, Enseignant> $poolParId
     * @param array<int, array<int, array<int, bool>>> $enseigne id enseignant => matiereId => niveauId => true
     * @param array<int, int> $chargeParEnseignant modifié en place
     * @param array<int, Examen[]> $examensAffectesParEnseignant modifié en place
     * @param array<int, array<int, Surveillance[]>> $surveillancesParExamenEtEnseignant examenId => enseignantId => lignes ; modifié en place
     */
    private function reequilibrer(
        array $poolParId,
        array $enseigne,
        array &$chargeParEnseignant,
        array &$examensAffectesParEnseignant,
        array &$surveillancesParExamenEtEnseignant,
    ): void {
        // Garde-fou anti-boucle infinie : chaque échange accepté fait strictement baisser d'au
        // moins 1 la charge du plus chargé actuel (jamais de retour en arrière), donc borné par
        // la charge totale initiale — largement dépassé ici par sécurité.
        $maxIterations = 5000;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            arsort($chargeParEnseignant);
            $idsParChargeDesc = array_keys($chargeParEnseignant);

            $swapEffectue = false;

            foreach ($idsParChargeDesc as $maxId) {
                $chargeMaxId = $chargeParEnseignant[$maxId];

                foreach ($examensAffectesParEnseignant[$maxId] ?? [] as $examen) {
                    $examenId = $examen->getId();
                    $lignes   = $surveillancesParExamenEtEnseignant[$examenId][$maxId] ?? null;
                    if ($lignes === null) {
                        continue;
                    }

                    $repreneurId = $this->trouveRepreneur($poolParId, $enseigne, $chargeParEnseignant, $chargeMaxId, $examen, $examensAffectesParEnseignant, $maxId);
                    if ($repreneurId === null) {
                        continue;
                    }

                    foreach ($lignes as $ligne) {
                        $ligne->setEnseignant($poolParId[$repreneurId]);
                    }

                    $chargeParEnseignant[$maxId]--;
                    $chargeParEnseignant[$repreneurId]++;

                    $examensAffectesParEnseignant[$maxId] = array_values(array_filter(
                        $examensAffectesParEnseignant[$maxId],
                        static fn(Examen $e) => $e->getId() !== $examenId,
                    ));
                    $examensAffectesParEnseignant[$repreneurId][] = $examen;

                    unset($surveillancesParExamenEtEnseignant[$examenId][$maxId]);
                    $surveillancesParExamenEtEnseignant[$examenId][$repreneurId] = $lignes;

                    $swapEffectue = true;
                    break 2;
                }
            }

            if (!$swapEffectue) {
                return; // aucun échange amélioreur possible — équilibre optimal compte tenu des contraintes réelles (cycle, chevauchement)
            }
        }
    }

    /**
     * Le meilleur repreneur possible pour un examen actuellement couvert par `$maxId` : parmi
     * tout le pool (pas seulement le minimum absolu), les enseignants strictement moins chargés
     * que `$maxId` de sorte que l'échange rapproche réellement les deux charges (le repreneur ne
     * doit pas se retrouver aussi chargé que ne l'était `$maxId`), éligibles au cycle de l'examen,
     * pas déjà affectés à cet examen, sans chevauchement avec leurs autres examens. Parmi les
     * éligibles, le moins chargé l'emporte (impact maximal) ; à égalité, priorité à un enseignant
     * de la matière exacte pour ne pas sacrifier inutilement la pertinence pédagogique déjà
     * obtenue par la bande d'équité.
     *
     * @param array<int, Enseignant> $poolParId
     * @param array<int, array<int, array<int, bool>>> $enseigne
     * @param array<int, int> $chargeParEnseignant
     * @param array<int, Examen[]> $examensAffectesParEnseignant
     */
    private function trouveRepreneur(
        array $poolParId,
        array $enseigne,
        array $chargeParEnseignant,
        int $chargeMaxId,
        Examen $examen,
        array $examensAffectesParEnseignant,
        int $maxId,
    ): ?int {
        $premierNiveau = $examen->getNiveaux()->first();
        $cycle         = $premierNiveau !== false ? $premierNiveau->getCycle() : null;
        $matiereId     = $examen->getMatiere()?->getId();

        $eligibles = [];
        foreach ($poolParId as $id => $enseignant) {
            if ($id === $maxId || $chargeParEnseignant[$id] >= $chargeMaxId - 1) {
                continue; // pas d'amélioration réelle si le repreneur atteindrait déjà l'ancienne charge du maximum
            }
            if ($this->estHorsCycle($enseignant, $cycle)) {
                continue;
            }

            $conflit = false;
            foreach ($examensAffectesParEnseignant[$id] ?? [] as $autre) {
                if ($autre->getId() === $examen->getId() || $examen->chevauche($autre)) {
                    $conflit = true;
                    break;
                }
            }
            if (!$conflit) {
                $eligibles[] = $id;
            }
        }

        if ($eligibles === []) {
            return null;
        }

        usort($eligibles, static fn(int $a, int $b) => $chargeParEnseignant[$a] <=> $chargeParEnseignant[$b]);
        $chargeMinEligible = $chargeParEnseignant[$eligibles[0]];

        foreach ($eligibles as $id) {
            if ($chargeParEnseignant[$id] === $chargeMinEligible && !empty($enseigne[$id][$matiereId])) {
                return $id;
            }
        }

        return $eligibles[0];
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
     * @param array<int, Examen[]> $examensAffectesParEnseignant
     * @param array<int, bool> $dejaAffecteCetteUnite
     * @param array<int, int> $chargeParEnseignant
     * @param array<int, array<int, array<int, bool>>> $enseigne id enseignant => matiereId => niveauId => true
     * @param array<int, array<string, bool>> $domainesEnseignant id enseignant => domaine->value => true
     */
    private function meilleurCandidat(
        array $pool,
        ?Cycle $cycle,
        array $examensAffectesParEnseignant,
        array $dejaAffecteCetteUnite,
        array $chargeParEnseignant,
        array $enseigne,
        array $domainesEnseignant,
        Examen $examen,
    ): ?Enseignant {
        $disponibles = array_values(array_filter(
            $pool,
            function (Enseignant $e) use ($examensAffectesParEnseignant, $dejaAffecteCetteUnite, $examen, $cycle): bool {
                $id = $e->getId();
                if (isset($dejaAffecteCetteUnite[$id]) || $this->estHorsCycle($e, $cycle)) {
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

        return $this->meilleurDansLaBandeEquite($disponibles, $chargeParEnseignant, $enseigne, $domainesEnseignant, $examen);
    }

    /**
     * Règle de cycle DURE : un enseignant rattaché à un seul cycle ("1" ou "2") est totalement
     * exclu des examens de l'autre cycle. Un enseignant partagé ("1/2") ou dont le cycle n'est
     * pas renseigné reste éligible aux deux. Si l'examen lui-même n'a aucun cycle déterminable
     * (cas théorique, niveau sans cycle), personne n'est exclu sur ce critère.
     */
    private function estHorsCycle(Enseignant $e, ?Cycle $cycle): bool
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
     * L'ÉQUITÉ D'ABORD : restreint les candidats à la "bande d'équité" (charge ≤ minimum des
     * disponibles + `TOLERANCE_EQUILIBRAGE`), PUIS seulement à l'intérieur de cette bande,
     * cascade à 3 niveaux : (1) même matière exacte que l'examen, (2) à défaut même domaine
     * (scientifique/littéraire/autre), (3) à défaut n'importe qui de la bande. La préférence de
     * matière/domaine ne peut donc jamais faire choisir quelqu'un de plus chargé que la
     * tolérance autorisée — contrairement à l'ancien mécanisme de quota (voir décision "priorité
     * à l'équité" en tête de fichier).
     *
     * @param Enseignant[] $enseignants déjà filtrés par disponibilité/cycle dans `meilleurCandidat()`
     * @param array<int, int> $chargeParEnseignant
     * @param array<int, array<int, array<int, bool>>> $enseigne
     * @param array<int, array<string, bool>> $domainesEnseignant
     */
    private function meilleurDansLaBandeEquite(
        array $enseignants,
        array $chargeParEnseignant,
        array $enseigne,
        array $domainesEnseignant,
        Examen $examen,
    ): ?Enseignant {
        if ($enseignants === []) {
            return null;
        }

        $chargeMin = min(array_map(static fn(Enseignant $e) => $chargeParEnseignant[$e->getId()], $enseignants));
        $bande     = array_values(array_filter(
            $enseignants,
            static fn(Enseignant $e) => $chargeParEnseignant[$e->getId()] <= $chargeMin + self::TOLERANCE_EQUILIBRAGE,
        ));

        $matiereId = $examen->getMatiere()?->getId();
        $domaine   = $examen->getMatiere()?->getDomaine();

        $memeMatiere = array_values(array_filter($bande, static fn(Enseignant $e) => !empty($enseigne[$e->getId()][$matiereId])));
        if ($memeMatiere !== []) {
            return $this->parMoindreCharge($memeMatiere, $chargeParEnseignant);
        }

        if ($domaine !== null) {
            $memeDomaine = array_values(array_filter($bande, static fn(Enseignant $e) => !empty($domainesEnseignant[$e->getId()][$domaine->value])));
            if ($memeDomaine !== []) {
                return $this->parMoindreCharge($memeDomaine, $chargeParEnseignant);
            }
        }

        return $this->parMoindreCharge($bande, $chargeParEnseignant);
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
