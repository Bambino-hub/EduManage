<?php

declare(strict_types=1);

namespace App\Website\Repository;

use App\Website\Entity\Actualite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Actualite>
 */
class ActualiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Actualite::class);
    }

    /**
     * Actualités publiées, les plus récentes en premier — utilisé par la page publique
     * "Actualités" (les brouillons ne sont visibles que dans l'admin).
     *
     * @return Actualite[]
     */
    public function findPublieesRecentes(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.publie = true')
            ->orderBy('a.datePublication', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
