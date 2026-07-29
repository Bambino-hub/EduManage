<?php

declare(strict_types=1);

namespace App\Economat\Repository;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Niveau;
use App\Economat\Entity\Tarif;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tarif>
 */
class TarifRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tarif::class);
    }

    public function findOneByNiveauEtAnnee(Niveau $niveau, AnneeScolaire $anneeScolaire): ?Tarif
    {
        return $this->findOneBy(['niveau' => $niveau, 'anneeScolaire' => $anneeScolaire]);
    }

    /** Tarifs de tous les niveaux pour une année, triés par ordre de niveau. @return Tarif[] */
    public function findAllPourAnnee(AnneeScolaire $anneeScolaire): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('n')
            ->join('t.niveau', 'n')
            ->where('t.anneeScolaire = :annee')
            ->setParameter('annee', $anneeScolaire)
            ->orderBy('n.ordre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
