<?php

declare(strict_types=1);

namespace App\Scheduling\Repository;

use App\Scheduling\Entity\EmploiDuTempsVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmploiDuTempsVersion>
 */
class EmploiDuTempsVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmploiDuTempsVersion::class);
    }

    /**
     * Historique d'une année, du plus récent au plus ancien.
     *
     * @return EmploiDuTempsVersion[]
     */
    public function findByAnnee(int $anneeScolaireId): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.anneeScolaire = :annee')
            ->setParameter('annee', $anneeScolaireId)
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
