<?php

declare(strict_types=1);

namespace App\Academic\Repository;

use App\Academic\Entity\Classe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Classe>
 */
class ClasseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Classe::class);
    }

    /** @return Classe[] */
    public function findByAnneeScolaireActive(): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.anneeScolaire', 'a')
            ->where('a.active = true')
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
