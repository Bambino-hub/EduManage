<?php

declare(strict_types=1);

namespace App\Salaire\Repository;

use App\Salaire\Entity\PaiementSalaire;
use App\Staff\Entity\Enseignant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaiementSalaire>
 */
class PaiementSalaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaiementSalaire::class);
    }

    /** Paiements d'un employé, du plus ancien au plus récent. @return PaiementSalaire[] */
    public function findByEnseignant(Enseignant $enseignant): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('l')
            ->join('p.lignes', 'l')
            ->where('p.enseignant = :enseignant')
            ->setParameter('enseignant', $enseignant)
            ->orderBy('p.dateEmission', 'ASC')
            ->addOrderBy('p.numero', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
