<?php

declare(strict_types=1);

namespace App\Grading\Repository;

use App\Grading\Entity\BulletinMatiere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BulletinMatiere>
 */
class BulletinMatiereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BulletinMatiere::class);
    }
}
