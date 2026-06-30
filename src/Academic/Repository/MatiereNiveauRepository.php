<?php

declare(strict_types=1);

namespace App\Academic\Repository;

use App\Academic\Entity\MatiereNiveau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MatiereNiveau>
 */
class MatiereNiveauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatiereNiveau::class);
    }
}
