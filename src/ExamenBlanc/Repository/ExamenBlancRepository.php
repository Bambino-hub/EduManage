<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Repository;

use App\Academic\Entity\AnneeScolaire;
use App\ExamenBlanc\Entity\ExamenBlanc;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExamenBlanc>
 */
class ExamenBlancRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamenBlanc::class);
    }

    /** @return ExamenBlanc[] les plus récents d'abord, niveaux déjà chargés. */
    public function findByAnneeScolaire(AnneeScolaire $anneeScolaire): array
    {
        return $this->createQueryBuilder('eb')
            ->addSelect('n')
            ->leftJoin('eb.niveaux', 'n')
            ->where('eb.anneeScolaire = :annee')
            ->setParameter('annee', $anneeScolaire)
            ->orderBy('eb.dateDebut', 'DESC')
            ->addOrderBy('eb.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
