<?php

declare(strict_types=1);

namespace App\Grading\Repository;

use App\Academic\Entity\AnneeScolaire;
use App\Grading\Entity\Trimestre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trimestre>
 */
class TrimestreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trimestre::class);
    }

    public function findActive(): ?Trimestre
    {
        return $this->findOneBy(['active' => true]);
    }

    /** @return Trimestre[] */
    public function findByAnneeScolaire(AnneeScolaire $anneeScolaire): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.anneeScolaire = :annee')
            ->setParameter('annee', $anneeScolaire)
            ->orderBy('t.numero', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
