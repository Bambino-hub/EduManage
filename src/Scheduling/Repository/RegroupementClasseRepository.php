<?php

declare(strict_types=1);

namespace App\Scheduling\Repository;

use App\Scheduling\Entity\RegroupementClasse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegroupementClasse>
 */
class RegroupementClasseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegroupementClasse::class);
    }

    /** @return RegroupementClasse[] */
    public function findAllAvecRelations(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c', 'm')
            ->leftJoin('r.classes', 'c')
            ->leftJoin('r.matieres', 'm')
            ->orderBy('r.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
