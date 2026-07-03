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

    /**
     * Classes réellement en cours cette année : année scolaire active ET classe elle-même
     * active (une classe désactivée reste en base — utile si son niveau n'a pas de cohorte
     * cette année, ex. série qui alterne — mais ne doit apparaître dans aucun emploi du
     * temps, aucune génération auto, ni la vérification des attributions).
     *
     * @return Classe[]
     */
    public function findByAnneeScolaireActive(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('mo')
            ->join('c.anneeScolaire', 'a')
            ->join('c.niveau', 'n')
            ->leftJoin('c.matieresOptionnelles', 'mo')
            ->where('a.active = true')
            ->andWhere('c.active = true')
            ->orderBy('n.ordre', 'ASC')
            ->addOrderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
