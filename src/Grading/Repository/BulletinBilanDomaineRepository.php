<?php

declare(strict_types=1);

namespace App\Grading\Repository;

use App\Grading\Entity\BulletinBilanDomaine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BulletinBilanDomaine>
 */
class BulletinBilanDomaineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BulletinBilanDomaine::class);
    }
}
