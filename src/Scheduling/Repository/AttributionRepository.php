<?php

declare(strict_types=1);

namespace App\Scheduling\Repository;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Scheduling\Entity\Attribution;
use App\Staff\Entity\Enseignant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Attribution>
 */
class AttributionRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly RegroupementClasseRepository $regroupementRepo,
    ) {
        parent::__construct($registry, Attribution::class);
    }

    /** @return Attribution[] */
    public function findByClasse(int $classeId): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.matiere', 'm')
            ->where('a.classe = :classeId')
            ->setParameter('classeId', $classeId)
            ->orderBy('m.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Exclut les attributions d'une classe désactivée (niveau sans cohorte cette année) :
     * elles ne doivent ni être plannifiées par le générateur d'emploi du temps, ni compter
     * dans les indicateurs (dashboard, vérification de complétude).
     *
     * @return Attribution[]
     */
    public function findByAnneeScolaire(int $anneeScolaireId): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('cl', 'm', 'e')
            ->join('a.classe', 'cl')
            ->join('a.matiere', 'm')
            ->join('a.enseignant', 'e')
            ->where('cl.anneeScolaire = :anneeId')
            ->andWhere('cl.active = true')
            ->setParameter('anneeId', $anneeScolaireId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Une classe ne peut avoir qu'un seul enseignant pour une matière donnée.
     * Retourne l'attribution qui bloquerait cette règle si elle existe déjà
     * (pour un autre enseignant que celui en cours d'affectation), sinon null.
     */
    public function findConflitMatiereClasse(Matiere $matiere, Classe $classe, ?int $excludeAttributionId = null): ?Attribution
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.matiere = :matiere')
            ->andWhere('a.classe = :classe')
            ->setParameter('matiere', $matiere)
            ->setParameter('classe', $classe)
            ->setMaxResults(1);

        if ($excludeAttributionId !== null) {
            $qb->andWhere('a.id != :excludeId')
                ->setParameter('excludeId', $excludeAttributionId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Charge horaire hebdomadaire totale par enseignant (toutes classes/matières confondues),
     * pour visualiser la charge globale sur la liste des attributions.
     *
     * Déduplique les heures quand un même enseignant intervient, pour la même matière,
     * sur plusieurs classes fusionnées via un `RegroupementClasse` (ex. 1ère C / 1ère D1) :
     * ces séances ont lieu simultanément (classes réunies dans une seule salle), donc ne
     * comptent qu'une fois dans sa charge réelle — pas une fois par classe. Si les classes
     * fusionnées ont des enseignants différents pour cette matière (autorisé par
     * `RegroupementClasse`, seul le créneau est imposé), chacun garde bien son propre
     * volume : la déduplication ne s'applique qu'au doublon d'un même enseignant.
     *
     * @return list<array{enseignant: Enseignant, total: int}>
     */
    public function totalHeuresParEnseignant(): array
    {
        $regroupementParClasseEtMatiere = [];
        foreach ($this->regroupementRepo->findAllAvecRelations() as $regroupement) {
            foreach ($regroupement->getClasses() as $classe) {
                foreach ($regroupement->getMatieres() as $matiere) {
                    $regroupementParClasseEtMatiere[$classe->getId()][$matiere->getId()] = $regroupement->getId();
                }
            }
        }

        $attributions = $this->createQueryBuilder('a')
            ->addSelect('e', 'cl', 'm')
            ->join('a.enseignant', 'e')
            ->join('a.classe', 'cl')
            ->join('a.matiere', 'm')
            ->getQuery()
            ->getResult();

        $enseignantParId    = [];
        $totalParEnseignant = [];
        $groupesComptes     = []; // enseignantId => [clé de dédoublonnage => true]

        foreach ($attributions as $attribution) {
            $enseignant   = $attribution->getEnseignant();
            $enseignantId = $enseignant->getId();
            $enseignantParId[$enseignantId] ??= $enseignant;

            $classeId       = $attribution->getClasse()->getId();
            $matiereId      = $attribution->getMatiere()->getId();
            $regroupementId = $regroupementParClasseEtMatiere[$classeId][$matiereId] ?? null;

            $cle = $regroupementId !== null
                ? "regroupement:{$regroupementId}:{$matiereId}"
                : "attribution:{$attribution->getId()}";

            if (isset($groupesComptes[$enseignantId][$cle])) {
                continue; // même enseignant, même matière fusionnée : déjà compté via une autre classe
            }
            $groupesComptes[$enseignantId][$cle] = true;

            $totalParEnseignant[$enseignantId] = ($totalParEnseignant[$enseignantId] ?? 0) + $attribution->getVolumeHoraireHebdo();
        }

        $resultats = [];
        foreach ($totalParEnseignant as $enseignantId => $total) {
            $resultats[] = ['enseignant' => $enseignantParId[$enseignantId], 'total' => $total];
        }

        usort($resultats, static fn (array $a, array $b) => [$b['total'], $a['enseignant']->getNom()] <=> [$a['total'], $b['enseignant']->getNom()]);

        return $resultats;
    }
}
