<?php

declare(strict_types=1);

namespace App\Exam\Repository;

use App\Exam\Entity\Surveillance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Surveillance>
 */
class SurveillanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Surveillance::class);
    }

    /**
     * Toutes les surveillances des examens donnés, avec classe et enseignant déjà chargés —
     * utilisé pour l'affichage du tableau (superposé à la grille d'examens) et pour la purge
     * avant régénération.
     *
     * @param int[] $examenIds
     * @return Surveillance[]
     */
    public function findByExamens(array $examenIds): array
    {
        if ($examenIds === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->join('s.classe', 'c')
            ->addSelect('c')
            ->join('s.enseignant', 'e')
            ->addSelect('e')
            ->where('s.examen IN (:examenIds)')
            ->setParameter('examenIds', $examenIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Surveillances données par id, avec examen (+ niveaux), classe et enseignant déjà chargés
     * — utilisé par la permutation manuelle pour valider un lot de changements.
     *
     * @param int[] $ids
     * @return Surveillance[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->join('s.examen', 'ex')
            ->addSelect('ex')
            ->join('ex.niveaux', 'n')
            ->addSelect('n')
            ->join('s.classe', 'c')
            ->addSelect('c')
            ->join('s.enseignant', 'e')
            ->addSelect('e')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
