<?php

declare(strict_types=1);

namespace App\Academic\Repository;

use App\Academic\Entity\AnneeScolaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnneeScolaire>
 */
class AnneeScolaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnneeScolaire::class);
    }

    public function findActive(): ?AnneeScolaire
    {
        return $this->findOneBy(['active' => true]);
    }
}
