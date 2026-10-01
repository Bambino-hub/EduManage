<?php

declare(strict_types=1);

namespace App\Exam\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Academic\Entity\Cycle;
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
 *  - le pool éligible : le personnel actif dont la case **"Autorisé(e) à surveiller les
 *    devoirs"** est cochée (`Enseignant::autoriseSurveillance`) — ni le statut ni la fonction
 *    n'entrent plus en compte (voir décision du 2026-10-01 en fin de bloc) ;
 *  - la PÉRIODE de disponibilité de chacun (`Enseignant::surveillanceDu/Au`, ex. un stagiaire) :
 *    jamais programmé sur un devoir en dehors ;
 *  - PAS DE SURVEILLANCES SUCCESSIVES : qui surveille un devoir ne surveille pas le suivant ;
 *  - les DEMI-CHARGES (`Enseignant::surveillanceMoitie`) : la moitié, arrondie à l'entier
 *    supérieur, du nombre de surveillances le plus élevé ;
 *  - les classes réellement concernées (`ExamenClassesResolver`) : un examen de matière à choix
 *    (ALL/ESP) ne vise que les classes qui la suivent, et une classe qui passe deux examens
 *    parallèles au même moment (ex. 2nde A4 : ALL + ESP) ne reçoit qu'un seul jeu de surveillants ;
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
 *
 * DÉCISION (équité D'UNE GÉNÉRATION À L'AUTRE, 2026-09-18) : tout ce qui précède ne garantit
 * l'équité QUE pour les examens traités dans le MÊME appel à `genererPourAnnee()` — chaque
 * régénération repartait de `chargeParEnseignant` = 0 pour tout le monde, donc ne rééquilibrait
 * jamais l'écart accumulé au fil des générations précédentes (années scolaires antérieures :
 * seuls les examens de l'année en cours sont purgés/régénérés, l'historique des années passées
 * reste en base — cf. `SurveillanceRepository::compterParEnseignant()`, déjà utilisé tel quel par
 * la page récapitulatif, non filtrée par année). Un enseignant présent depuis plusieurs années
 * pouvait ainsi rester durablement plus chargé qu'un collègue arrivé récemment, sans que la bande
 * d'équité ni `reequilibrer()` ne le voient jamais, puisque tous deux repartaient à égalité (0) à
 * chaque régénération. Corrigé en initialisant `chargeParEnseignant` avec la charge historique
 * (toutes années confondues, hors celle qu'on vient de purger — cf. juste après la purge ci-
 * dessous) au lieu de 0 : la bande d'équité et `reequilibrer()` restant purement relatifs, ils se
 * mettent alors à niveler l'écart CUMULÉ, ce qui fait mécaniquement pencher les nouveaux postes de
 * cette génération vers qui avait le moins surveillé jusqu'ici — sans déroger aux autres règles
 * (cycle dur, non-chevauchement, préférence matière/domaine à l'intérieur de la bande).
 *
 * DÉCISION (case à cocher, période, demi-charge, alternance — 2026-10-01), demandée par
 * l'utilisateur, toutes les règles ci-dessus restant valables par ailleurs :
 *  1. Éligibilité par case à cocher, plus par statut + fonction : autoriser au cas par cas (un
 *     externe, par exemple) était devenu impossible sans tordre la règle.
 *  2. Période de disponibilité : la génération programmait un stagiaire au-delà de son stage.
 *     Contrainte dure (peutSurveiller()), réglée depuis le programme des devoirs.
 *  3. Demi-charge : ceil(maximum des enseignants normaux / 2) — 6 → 3, 5 → 3. Pendant la
 *     génération une surveillance leur pèse double dans la charge d'équité, puis
 *     ajusterDemiCharges() ramène au quota exact.
 *  4. Pas de surveillances successives (sontSuccessifs()) : contrainte appliquée à l'affectation
 *     ET aux rééquilibrages. D'où le traitement des examens dans l'ordre chronologique. Seule
 *     dérogation : s'il ne reste absolument personne d'autre pour un poste, on préfère une
 *     surveillance successive à une classe sans surveillant, et le résultat le signale.
 * Demi-charges et périodes limitées sont les deux seules exceptions à l'égalité de charge :
 * `reequilibrer()` ne nivelle plus que les enseignants "normaux" entre eux.
 */
class ExamenSurveillanceGenerator
{
    /**
     * Écart de charge maximum toléré, au-dessus du minimum parmi les candidats disponibles pour
     * un poste donné, pour rester dans la "bande d'équité" de ce poste. Voir la décision
     * "priorité à l'équité" ci-dessus pour la comparaison chiffrée à 1 vs 0 qui a mené à ce choix.
     */
    private const TOLERANCE_EQUILIBRAGE = 0;

    /* État de la génération en cours — réinitialisé à chaque appel de genererPourAnnee(). */

    /** @var array<int, Enseignant> tout le pool, par id */
    private array $poolParId = [];

    /** @var array<int, Enseignant> ni demi-charge ni période limitée : les seuls concernés par l'égalité stricte de reequilibrer() */
    private array $normaux = [];

    /** @var array<int, int> charge d'ÉQUITÉ par enseignant (historique + poids × surveillances de cette génération) */
    private array $charge = [];

    /** @var array<int, int> nombre de surveillances de CETTE génération, par enseignant */
    private array $compte = [];

    /** @var array<int, int> ce que pèse une surveillance dans la charge d'équité : 2 en demi-charge, 1 sinon */
    private array $poids = [];

    /** @var array<int, bool> enseignants à période limitée déjà entrés dans la répartition, cf. faireEntrer() */
    private array $entres = [];

    /** @var array<int, Examen[]> examens surveillés, par enseignant */
    private array $examensAffectes = [];

    /** @var array<int, array<int, Surveillance[]>> examenId => enseignantId => lignes créées */
    private array $lignes = [];

    /** @var array<int, array<int, array<int, bool>>> id enseignant => matiereId => niveauId => true */
    private array $enseigne = [];

    /** @var array<int, array<string, bool>> id enseignant => domaine->value => true */
    private array $domainesEnseignant = [];

    /** @var array<int, array{debut: int, fin: int, cycle: ?Cycle}> horaires et cycle de chaque examen de l'année */
    private array $creneaux = [];

    /** @var array<string, bool> cache de sontSuccessifs() */
    private array $successifs = [];

    public function __construct(
        private readonly ExamenRepository $examenRepo,
        private readonly EnseignantRepository $enseignantRepo,
        private readonly AttributionRepository $attributionRepo,
        private readonly SurveillanceRepository $surveillanceRepo,
        private readonly ClasseRepository $classeRepo,
        private readonly RegroupementSurveillanceRepository $regroupementRepo,
        private readonly ExamenClassesResolver $classesResolver,
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

        // Une fois la purge de CETTE année flushée, la table `surveillance` ne contient plus
        // que les années passées : compterParEnseignant() (déjà utilisé par la page
        // récapitulatif, non filtrée par année) donne donc directement la charge HISTORIQUE de
        // chacun. Elle sert de point de départ à la charge d'équité au lieu de 0 : la bande
        // d'équité et `reequilibrer()` (tous deux purement relatifs, voir leurs docblocks)
        // continuent alors à niveler l'écart cumulé d'une année sur l'autre — un enseignant
        // resté en retrait les années précédentes se retrouve avec une charge de départ plus
        // basse, donc prioritaire sur les nouveaux postes de CETTE génération, tant qu'il reste
        // des postes à distribuer et sans jamais déroger aux autres règles (cycle, chevauchement,
        // pertinence matière/domaine dans la bande). Voir aussi la page récapitulatif, qui
        // affiche cette même charge cumulée toutes années confondues.
        $chargeHistorique = $this->surveillanceRepo->compterParEnseignant();

        $pool = $this->enseignantRepo->findEligiblesSurveillance();
        if ($pool === [] || $examens === []) {
            return new GenerationResultSurveillance(count($examens), 0, 0, []);
        }

        // Répartition volontairement aléatoire : sans ce mélange, l'ordre alphabétique du pool
        // départageait toujours les ex-æquo de charge de la même façon → un clic sur "régénérer"
        // produisait exactement le même tableau. Les règles restent intactes, seul l'ordre de
        // résolution des égalités change.
        shuffle($pool);
        shuffle($examens);

        $this->initialiserEtat($pool, $examens, $chargeHistorique);

        // Examens traités dans l'ordre CHRONOLOGIQUE, les deux cycles entremêlés (jamais cycle
        // par cycle, voir la décision "cycle par cycle vs global") ; le mélange ci-dessus ne
        // départage plus que les examens qui démarrent au même instant (tri stable). C'est ce
        // qui permet d'alterner naturellement les surveillants d'un devoir au suivant — dans un
        // ordre quelconque, un devoir traité après ses deux voisins ne trouverait plus personne
        // qui n'ait surveillé ni l'un ni l'autre — voir la décision "pas de surveillances
        // successives".
        usort($examens, fn(Examen $a, Examen $b) => $this->creneaux[$a->getId()]['debut'] <=> $this->creneaux[$b->getId()]['debut']);

        [$this->enseigne, $this->domainesEnseignant] = $this->construireDonneesAttributions($annee);
        $classesActives    = $this->classeRepo->findByAnneeScolaireActive();
        $groupeParClasseId = $this->regroupementRepo->findGroupeParClasseId();
        // Classes réellement à surveiller par examen : matières à choix (ALL/ESP) limitées aux
        // classes qui les suivent, et une seule surveillance par classe quand elle passe deux
        // examens parallèles au même moment — voir ExamenClassesResolver.
        $repartition = $this->classesResolver->repartir($examens, $classesActives);

        $surveillancesCreees = 0;
        $postesRequis        = 0;
        $postesNonPourvus    = [];

        foreach ($examens as $examen) {
            $classesConcernees = array_values(array_column($repartition[$examen->getId()] ?? [], 'classe'));

            $unites = $this->regrouperClasses($classesConcernees, $groupeParClasseId);
            shuffle($unites);

            $this->faireEntrer($examen);

            foreach ($unites as $unite) {
                $requis                = $examen->getNombreSurveillantsParClasse();
                $dejaAffecteCetteUnite = [];

                for ($i = 0; $i < $requis; $i++) {
                    $postesRequis++;

                    $candidat = $this->meilleurCandidat($pool, $dejaAffecteCetteUnite, $examen);

                    if ($candidat === null) {
                        $postesNonPourvus[] = new PosteNonPourvu($examen->getLabel(), $this->nomUnite($unite), $requis - $i);
                        break;
                    }

                    $id = $candidat->getId();

                    foreach ($unite as $classe) {
                        $surveillance = new Surveillance();
                        $surveillance->setExamen($examen);
                        $surveillance->setClasse($classe);
                        $surveillance->setEnseignant($candidat);
                        $this->em->persist($surveillance);

                        $this->lignes[$examen->getId()][$id][] = $surveillance;
                    }

                    $this->examensAffectes[$id][] = $examen;
                    $dejaAffecteCetteUnite[$id]   = true;
                    $this->charge[$id]           += $this->poids[$id];
                    $this->compte[$id]++;
                    $surveillancesCreees++;
                }
            }
        }

        // Rééquilibrage des enseignants "normaux", puis mise au quota des demi-charges ; cette
        // dernière déplace des surveillances de/vers les normaux, d'où un nouveau rééquilibrage
        // tant qu'elle a bougé quelque chose (borné : converge en pratique en 1 ou 2 tours).
        for ($tour = 0; $tour < 10; $tour++) {
            $this->reequilibrer();
            if (!$this->ajusterDemiCharges()) {
                break;
            }
        }

        $this->em->flush();

        return new GenerationResultSurveillance(count($examens), $postesRequis, $surveillancesCreees, $postesNonPourvus, $this->successionsRestantes());
    }

    /**
     * @param Enseignant[] $pool
     * @param Examen[] $examens
     * @param array<int, int> $chargeHistorique
     */
    private function initialiserEtat(array $pool, array $examens, array $chargeHistorique): void
    {
        $this->poolParId = $this->normaux = $this->charge = $this->compte = $this->poids = [];
        $this->entres    = $this->examensAffectes = $this->lignes = $this->creneaux = $this->successifs = [];

        foreach ($pool as $enseignant) {
            $id                   = $enseignant->getId();
            $this->poolParId[$id] = $enseignant;
            $this->charge[$id]    = $chargeHistorique[$id] ?? 0;
            $this->compte[$id]    = 0;
            $this->poids[$id]     = $enseignant->isSurveillanceMoitie() ? 2 : 1;

            if (!$enseignant->isSurveillanceMoitie() && !$enseignant->hasPeriodeSurveillance()) {
                $this->normaux[$id] = $enseignant;
            }
        }

        foreach ($examens as $examen) {
            $premierNiveau = $examen->getNiveaux()->first();
            $jour          = $examen->getDate()?->format('Y-m-d') ?? '1970-01-01';

            $this->creneaux[$examen->getId()] = [
                'debut' => (int) strtotime($jour.' '.($examen->getHeureDebut()?->format('H:i:s') ?? '00:00:00')),
                'fin'   => (int) strtotime($jour.' '.($examen->getHeureFin()?->format('H:i:s') ?? '00:00:00')),
                'cycle' => $premierNiveau !== false ? $premierNiveau->getCycle() : null,
            ];
        }
    }

    /**
     * Un enseignant à période limitée (stagiaire…) entre dans la répartition au premier examen
     * de sa période, au niveau du moins chargé des enseignants normaux à cet instant — jamais en
     * dessous. Sans cela, quelqu'un qui arrive en cours de session (charge 0 face à des collègues
     * déjà à 3) serait choisi à chaque devoir possible jusqu'à les rattraper ; les examens étant
     * traités chronologiquement, il prend ainsi simplement sa part des devoirs de SA période, et
     * plus rien une fois celle-ci terminée.
     */
    private function faireEntrer(Examen $examen): void
    {
        if ($this->normaux === [] || $examen->getDate() === null) {
            return;
        }

        $plancher = null;
        foreach ($this->poolParId as $id => $enseignant) {
            if (isset($this->entres[$id]) || !$enseignant->hasPeriodeSurveillance() || !$enseignant->estDisponiblePourSurveillance($examen->getDate())) {
                continue;
            }

            $plancher ??= min(array_intersect_key($this->charge, $this->normaux));
            $this->entres[$id] = true;
            $this->charge[$id] = max($this->charge[$id], $plancher);
        }
    }

    /**
     * Passe de rééquilibrage post-génération : cherche, pour l'enseignant le plus chargé, un
     * examen qu'il couvre et qu'un enseignant STRICTEMENT moins chargé (pas forcément au minimum
     * absolu du pool) pourrait reprendre — même cycle, pas de chevauchement, pas de surveillance
     * successive, pas déjà affecté à cet examen — et effectue l'échange. Répété jusqu'à ce
     * qu'aucun échange amélioreur n'existe plus. Ne pas exiger le minimum absolu est essentiel :
     * la règle de cycle DURE sépare le pool en sous-groupes (mono-cycle 1, mono-cycle 2, partagés
     * "1/2") qui ne peuvent PAS toujours s'échanger directement entre eux — un enseignant
     * mono-cycle-2 chargé ne peut jamais reprendre la place d'un mono-cycle-1 sous-chargé. En
     * acceptant des échanges vers n'importe quel enseignant moins chargé (pas seulement le
     * minimum global), les enseignants "1/2" servent de passerelle : chaque échange fait
     * progressivement redescendre le maximum, même s'il faut plusieurs échanges en chaîne pour
     * atteindre l'équilibre réellement atteignable compte tenu de cette séparation de cycles.
     * Voir décision "passe de rééquilibrage final" en tête de fichier.
     *
     * Ne concerne que les enseignants "normaux" : les demi-charges ont leur propre quota
     * (ajusterDemiCharges()) et ceux à période limitée restent à ce que leur période leur a
     * donné — ce sont les deux exceptions assumées à l'égalité de charge.
     */
    private function reequilibrer(): void
    {
        // Garde-fou anti-boucle infinie : chaque échange accepté fait strictement baisser d'au
        // moins 1 la charge du plus chargé actuel (jamais de retour en arrière), donc borné par
        // la charge totale initiale — largement dépassé ici par sécurité.
        $maxIterations = 5000;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $charges = array_intersect_key($this->charge, $this->normaux);
            arsort($charges);

            $swapEffectue = false;

            foreach ($charges as $maxId => $chargeMaxId) {
                foreach ($this->examensAffectes[$maxId] ?? [] as $examen) {
                    $repreneurId = $this->trouveRepreneur($chargeMaxId, $examen, $maxId);
                    if ($repreneurId === null) {
                        continue;
                    }

                    $this->transferer($examen, $maxId, $repreneurId);

                    $swapEffectue = true;
                    break 2;
                }
            }

            if (!$swapEffectue) {
                return; // aucun échange amélioreur possible — équilibre optimal compte tenu des contraintes réelles (cycle, chevauchement, alternance)
            }
        }
    }

    /**
     * Le meilleur repreneur possible pour un examen actuellement couvert par `$maxId` : parmi
     * les enseignants normaux (pas seulement le minimum absolu), ceux strictement moins chargés
     * que `$maxId` de sorte que l'échange rapproche réellement les deux charges (le repreneur ne
     * doit pas se retrouver aussi chargé que ne l'était `$maxId`) et qui peuvent surveiller cet
     * examen (peutSurveiller()). Parmi les éligibles, le moins chargé l'emporte (impact
     * maximal) ; à égalité, priorité à un enseignant de la matière exacte pour ne pas sacrifier
     * inutilement la pertinence pédagogique déjà obtenue par la bande d'équité.
     */
    private function trouveRepreneur(int $chargeMaxId, Examen $examen, int $maxId): ?int
    {
        $matiereId = $examen->getMatiere()?->getId();

        $eligibles = [];
        foreach ($this->normaux as $id => $enseignant) {
            if ($id === $maxId || $this->charge[$id] >= $chargeMaxId - 1) {
                continue; // pas d'amélioration réelle si le repreneur atteindrait déjà l'ancienne charge du maximum
            }
            if ($this->peutSurveiller($enseignant, $examen, true)) {
                $eligibles[] = $id;
            }
        }

        if ($eligibles === []) {
            return null;
        }

        usort($eligibles, fn(int $a, int $b) => $this->charge[$a] <=> $this->charge[$b]);
        $chargeMinEligible = $this->charge[$eligibles[0]];

        foreach ($eligibles as $id) {
            if ($this->charge[$id] === $chargeMinEligible && !empty($this->enseigne[$id][$matiereId])) {
                return $id;
            }
        }

        return $eligibles[0];
    }

    /**
     * Fait passer la surveillance d'un examen d'un enseignant à un autre. Déplace TOUTES les
     * lignes de l'examen tenues par `$deId` (jamais une ligne seule d'un `RegroupementSurveillance`).
     */
    private function transferer(Examen $examen, int $deId, int $versId): void
    {
        $examenId = $examen->getId();

        foreach ($this->lignes[$examenId][$deId] as $ligne) {
            $ligne->setEnseignant($this->poolParId[$versId]);
        }
        $this->lignes[$examenId][$versId] = $this->lignes[$examenId][$deId];
        unset($this->lignes[$examenId][$deId]);

        $this->examensAffectes[$deId] = array_values(array_filter(
            $this->examensAffectes[$deId],
            static fn(Examen $e) => $e->getId() !== $examenId,
        ));
        $this->examensAffectes[$versId][] = $examen;

        $this->charge[$deId]   -= $this->poids[$deId];
        $this->charge[$versId] += $this->poids[$versId];
        $this->compte[$deId]--;
        $this->compte[$versId]++;
    }

    /**
     * Demi-charges (`Enseignant::surveillanceMoitie`) : chacune doit finir à la MOITIÉ du nombre
     * de surveillances le plus élevé parmi les enseignants normaux, arrondie à l'entier supérieur
     * (6 → 3, 5 → 3). La génération les en approche déjà (une surveillance leur pèse double dans
     * la charge d'équité) ; cette passe ramène au quota exact : une demi-charge au-dessus cède un
     * examen au normal le moins chargé qui peut le reprendre, une demi-charge en dessous en
     * reprend un au normal le plus chargé — toujours dans le respect des autres règles
     * (peutSurveiller()). Au mieux des contraintes : sans repreneur ou donneur possible, on en
     * reste là. Une demi-charge à période limitée n'est jamais complétée (sa période décide),
     * seulement plafonnée.
     *
     * @return bool vrai si au moins une surveillance a changé de main
     */
    private function ajusterDemiCharges(): bool
    {
        $demis = array_filter($this->poolParId, static fn(Enseignant $e) => $e->isSurveillanceMoitie());
        if ($demis === [] || $this->normaux === []) {
            return false;
        }

        $modifie = false;

        // Un seul mouvement par tour : le quota dépend du maximum, qui peut changer à chaque mouvement.
        for ($tour = 0; $tour < 500; $tour++) {
            $cible     = (int) ceil(max(array_intersect_key($this->compte, $this->normaux)) / 2);
            $mouvement = false;

            foreach ($demis as $id => $enseignant) {
                if ($this->compte[$id] > $cible) {
                    $mouvement = $this->cederUnExamen($id);
                } elseif ($this->compte[$id] < $cible && !$enseignant->hasPeriodeSurveillance()) {
                    $mouvement = $this->reprendreUnExamen($id);
                }
                if ($mouvement) {
                    break;
                }
            }

            if (!$mouvement) {
                break;
            }
            $modifie = true;
        }

        return $modifie;
    }

    /** Cède un examen de la demi-charge `$demiId` au normal le moins chargé qui peut le reprendre. */
    private function cederUnExamen(int $demiId): bool
    {
        $meilleur = null; // [examen, repreneurId]

        foreach ($this->examensAffectes[$demiId] ?? [] as $examen) {
            foreach ($this->normaux as $id => $enseignant) {
                if (($meilleur === null || $this->charge[$id] < $this->charge[$meilleur[1]]) && $this->peutSurveiller($enseignant, $examen, true)) {
                    $meilleur = [$examen, $id];
                }
            }
        }

        if ($meilleur === null) {
            return false;
        }

        $this->transferer($meilleur[0], $demiId, $meilleur[1]);

        return true;
    }

    /**
     * Fait reprendre à la demi-charge `$demiId` un examen d'un enseignant normal, en commençant
     * par les plus chargés. Refusé si, le donneur ayant une surveillance de moins, le maximum
     * baisse au point que la demi-charge dépasserait son nouveau quota (sinon cette passe
     * reprendrait puis recéderait la même surveillance indéfiniment).
     */
    private function reprendreUnExamen(int $demiId): bool
    {
        $comptes = array_intersect_key($this->compte, $this->normaux);
        arsort($comptes);

        foreach ($comptes as $donneurId => $compteDonneur) {
            $apres             = $comptes;
            $apres[$donneurId] = $compteDonneur - 1;
            if ($this->compte[$demiId] + 1 > (int) ceil(max($apres) / 2)) {
                continue;
            }

            foreach ($this->examensAffectes[$donneurId] ?? [] as $examen) {
                if ($this->peutSurveiller($this->poolParId[$demiId], $examen, true)) {
                    $this->transferer($examen, $donneurId, $demiId);

                    return true;
                }
            }
        }

        return false;
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
     * Le surveillant à affecter au prochain poste de l'examen : d'abord parmi ceux qui peuvent
     * le surveiller SANS enchaîner deux devoirs de suite ; seulement s'il n'y a plus personne,
     * parmi ceux qui l'enchaîneraient — une classe sans surveillant étant pire qu'une surveillance
     * successive (signalée dans le résultat, cf. successionsRestantes()).
     *
     * @param Enseignant[] $pool
     * @param array<int, bool> $dejaAffecteCetteUnite
     */
    private function meilleurCandidat(array $pool, array $dejaAffecteCetteUnite, Examen $examen): ?Enseignant
    {
        foreach ([true, false] as $sansSuccession) {
            $disponibles = array_values(array_filter(
                $pool,
                fn(Enseignant $e) => !isset($dejaAffecteCetteUnite[$e->getId()]) && $this->peutSurveiller($e, $examen, $sansSuccession),
            ));

            if ($disponibles !== []) {
                return $this->meilleurDansLaBandeEquite($disponibles, $examen);
            }
        }

        return null;
    }

    /**
     * Toutes les contraintes DURES d'une affectation : cycle (estHorsCycle()), période de
     * disponibilité pour la surveillance (`Enseignant::estDisponiblePourSurveillance()`), pas
     * déjà sur cet examen ni sur un autre qui le chevauche, et — si `$sansSuccession` — pas sur
     * le devoir qui précède ou suit immédiatement (sontSuccessifs()).
     */
    private function peutSurveiller(Enseignant $e, Examen $examen, bool $sansSuccession): bool
    {
        if ($this->estHorsCycle($e, $this->creneaux[$examen->getId()]['cycle'])) {
            return false;
        }
        if ($examen->getDate() !== null && !$e->estDisponiblePourSurveillance($examen->getDate())) {
            return false;
        }

        foreach ($this->examensAffectes[$e->getId()] ?? [] as $autre) {
            if ($autre->getId() === $examen->getId() || $examen->chevauche($autre)) {
                return false;
            }
            if ($sansSuccession && $this->sontSuccessifs($e, $examen, $autre)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deux examens sont "successifs" pour un enseignant s'il n'existe, entre la fin du premier et
     * le début du second, AUCUN autre devoir qu'il aurait pu surveiller (c.-à-d. de son ou ses
     * cycles) : les surveiller tous les deux reviendrait à enchaîner deux devoirs de suite. Se
     * juge sur la suite des devoirs de l'enseignant, pas sur le calendrier : le devoir de
     * l'après-midi et celui du lendemain matin sont successifs, et pour un enseignant du seul
     * cycle 1 un devoir du cycle 2 placé entre les deux ne compte pas comme une pause.
     */
    private function sontSuccessifs(Enseignant $e, Examen $a, Examen $b): bool
    {
        if ($this->creneaux[$a->getId()]['debut'] > $this->creneaux[$b->getId()]['debut']) {
            [$a, $b] = [$b, $a];
        }

        $cle = trim((string) $e->getCycle()).'|'.$a->getId().'|'.$b->getId();
        if (isset($this->successifs[$cle])) {
            return $this->successifs[$cle];
        }

        $finPremier  = $this->creneaux[$a->getId()]['fin'];
        $debutSecond = $this->creneaux[$b->getId()]['debut'];

        $successifs = $debutSecond >= $finPremier; // sinon ils se chevauchent : autre règle
        if ($successifs) {
            foreach ($this->creneaux as $examenId => $creneau) {
                if ($examenId !== $a->getId() && $examenId !== $b->getId()
                    && $creneau['debut'] >= $finPremier && $creneau['fin'] <= $debutSecond
                    && !$this->estHorsCycle($e, $creneau['cycle'])
                ) {
                    $successifs = false;
                    break;
                }
            }
        }

        return $this->successifs[$cle] = $successifs;
    }

    /**
     * Surveillances successives encore présentes dans le tableau final : celles que
     * meilleurCandidat() n'a pas pu éviter faute de tout autre surveillant disponible. Recalculé
     * sur le résultat final (les rééquilibrages n'en créent jamais, mais peuvent en défaire).
     *
     * @return string[] une phrase par enchaînement, ex. "KOMOU Padabadi : MATHS 16/11 08:00 puis FR 16/11 15:00"
     */
    private function successionsRestantes(): array
    {
        $libelle = static fn(Examen $x) => trim(sprintf('%s %s %s', $x->getMatiere()?->getCode(), $x->getDate()?->format('d/m'), $x->getHeureDebut()?->format('H:i')));

        $successions = [];
        foreach ($this->examensAffectes as $id => $examens) {
            usort($examens, fn(Examen $a, Examen $b) => $this->creneaux[$a->getId()]['debut'] <=> $this->creneaux[$b->getId()]['debut']);

            for ($i = 1, $n = count($examens); $i < $n; $i++) {
                if ($this->sontSuccessifs($this->poolParId[$id], $examens[$i - 1], $examens[$i])) {
                    $successions[] = sprintf('%s : %s puis %s', $this->poolParId[$id]->getNomComplet(), $libelle($examens[$i - 1]), $libelle($examens[$i]));
                }
            }
        }
        sort($successions);

        return $successions;
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
     * @param Enseignant[] $enseignants déjà filtrés par `meilleurCandidat()` (peutSurveiller())
     */
    private function meilleurDansLaBandeEquite(array $enseignants, Examen $examen): ?Enseignant
    {
        if ($enseignants === []) {
            return null;
        }

        $chargeMin = min(array_map(fn(Enseignant $e) => $this->charge[$e->getId()], $enseignants));
        $bande     = array_values(array_filter(
            $enseignants,
            fn(Enseignant $e) => $this->charge[$e->getId()] <= $chargeMin + self::TOLERANCE_EQUILIBRAGE,
        ));

        $matiereId = $examen->getMatiere()?->getId();
        $domaine   = $examen->getMatiere()?->getDomaine();

        $memeMatiere = array_values(array_filter($bande, fn(Enseignant $e) => !empty($this->enseigne[$e->getId()][$matiereId])));
        if ($memeMatiere !== []) {
            return $this->parMoindreCharge($memeMatiere);
        }

        if ($domaine !== null) {
            $memeDomaine = array_values(array_filter($bande, fn(Enseignant $e) => !empty($this->domainesEnseignant[$e->getId()][$domaine->value])));
            if ($memeDomaine !== []) {
                return $this->parMoindreCharge($memeDomaine);
            }
        }

        return $this->parMoindreCharge($bande);
    }

    /** @param Enseignant[] $enseignants */
    private function parMoindreCharge(array $enseignants): ?Enseignant
    {
        $meilleur        = null;
        $meilleureCharge = null;
        foreach ($enseignants as $enseignant) {
            $charge = $this->charge[$enseignant->getId()];
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
