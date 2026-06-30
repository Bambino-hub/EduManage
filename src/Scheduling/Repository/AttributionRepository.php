<?php

declare(strict_types=1);

namespace App\Scheduling\Repository;

use App\Scheduling\Entity\Attribution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Attribution>
 */
class AttributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
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
}
