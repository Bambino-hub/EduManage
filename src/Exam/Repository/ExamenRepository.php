<?php

declare(strict_types=1);

namespace App\Exam\Repository;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Cycle;
use App\Exam\Entity\Examen;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Examen>
 */
class ExamenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Examen::class);
    }

    /**
     * Tous les examens d'un cycle pour une année scolaire, avec matière et niveaux déjà
     * chargés (évite le lazy-load niveau par niveau lors de la construction de la grille).
     *
     * @return Examen[]
     */
    public function findByCycle(Cycle $cycle, AnneeScolaire $annee): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.matiere', 'm')
            ->addSelect('m')
            ->join('e.niveaux', 'n')
            ->addSelect('n')
            ->where('n.cycle = :cycle')
            ->andWhere('e.anneeScolaire = :annee')
            ->setParameter('cycle', $cycle)
            ->setParameter('annee', $annee)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.heureDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les examens de l'année scolaire, tous cycles confondus, avec matière et niveaux déjà
     * chargés — utilisé par la génération globale de la surveillance (un seul passage sur les
     * deux cycles à la fois, pour ne pas avantager celui généré en premier).
     *
     * @return Examen[]
     */
    public function findByAnnee(AnneeScolaire $annee): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.matiere', 'm')
            ->addSelect('m')
            ->join('e.niveaux', 'n')
            ->addSelect('n')
            ->where('e.anneeScolaire = :annee')
            ->setParameter('annee', $annee)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.heureDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Examens donnés par id, avec niveaux déjà chargés — utilisé par la permutation manuelle
     * pour résoudre les examens d'origine et de destination d'un lot de changements.
     *
     * @param int[] $ids
     * @return Examen[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->join('e.niveaux', 'n')
            ->addSelect('n')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
