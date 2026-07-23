<?php

declare(strict_types=1);

namespace App\ExamenNational\Repository;

use App\ExamenNational\Entity\CandidatExamenNational;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CandidatExamenNational>
 */
class CandidatExamenNationalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CandidatExamenNational::class);
    }
}
