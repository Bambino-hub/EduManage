<?php

declare(strict_types=1);

namespace App\Grading\Repository;

use App\Grading\Entity\MoyenneManuelle;
use App\Grading\Entity\Trimestre;
use App\Scheduling\Entity\Attribution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoyenneManuelle>
 */
class MoyenneManuelleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoyenneManuelle::class);
    }

    /** Surcharges déjà saisies pour une attribution/trimestre, indexées par id élève. @return array<int, MoyenneManuelle> */
    public function findByAttributionEtTrimestreIndexeesParEleve(Attribution $attribution, Trimestre $trimestre): array
    {
        $lignes = $this->createQueryBuilder('m')
            ->where('m.attribution = :attribution')
            ->andWhere('m.trimestre = :trimestre')
            ->setParameter('attribution', $attribution)
            ->setParameter('trimestre', $trimestre)
            ->getQuery()
            ->getResult();

        $indexees = [];
        foreach ($lignes as $ligne) {
            $indexees[$ligne->getEleve()->getId()] = $ligne;
        }

        return $indexees;
    }
}
