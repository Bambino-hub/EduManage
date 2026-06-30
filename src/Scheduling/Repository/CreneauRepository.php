<?php

declare(strict_types=1);

namespace App\Scheduling\Repository;

use App\Scheduling\Entity\Creneau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Creneau>
 */
class CreneauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Creneau::class);
    }

    /** @return Creneau[] triés par jour puis par heure */
    public function findOrdonnes(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.ordre', 'ASC')
            ->addOrderBy('c.heureDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
