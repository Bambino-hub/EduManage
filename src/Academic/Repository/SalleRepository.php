<?php

declare(strict_types=1);

namespace App\Academic\Repository;

use App\Academic\Entity\Salle;
use App\Academic\Enum\TypeSalle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Salle>
 */
class SalleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Salle::class);
    }

    /** @return Salle[] */
    public function findByType(TypeSalle $type): array
    {
        return $this->findBy(['type' => $type], ['nom' => 'ASC']);
    }
}
