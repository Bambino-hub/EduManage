<?php

declare(strict_types=1);

namespace App\Grading\Repository;

use App\Grading\Entity\Evaluation;
use App\Grading\Entity\Trimestre;
use App\Scheduling\Entity\Attribution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evaluation>
 */
class EvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluation::class);
    }

    /** @return Evaluation[] */
    public function findByAttributionEtTrimestre(Attribution $attribution, Trimestre $trimestre): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.attribution = :attribution')
            ->andWhere('e.trimestre = :trimestre')
            ->setParameter('attribution', $attribution)
            ->setParameter('trimestre', $trimestre)
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
