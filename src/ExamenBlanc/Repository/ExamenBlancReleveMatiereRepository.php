<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Repository;

use App\ExamenBlanc\Entity\ExamenBlancReleveMatiere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExamenBlancReleveMatiere>
 */
class ExamenBlancReleveMatiereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamenBlancReleveMatiere::class);
    }
}
