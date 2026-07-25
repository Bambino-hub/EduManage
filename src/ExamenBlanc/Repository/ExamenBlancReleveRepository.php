<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Repository;

use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Entity\ExamenBlancReleve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExamenBlancReleve>
 */
class ExamenBlancReleveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamenBlancReleve::class);
    }

    /** @return ExamenBlancReleve[] triés par rang croissant, classe puis nom/prénom pour les non-classés. */
    public function findByExamenBlancEtNiveau(ExamenBlanc $examenBlanc, Niveau $niveau): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('e', 'c')
            ->join('r.eleve', 'e')
            ->join('r.classe', 'c')
            ->where('r.examenBlanc = :examenBlanc')
            ->andWhere('r.niveau = :niveau')
            ->setParameter('examenBlanc', $examenBlanc)
            ->setParameter('niveau', $niveau)
            ->orderBy('r.rangNiveau', 'ASC')
            ->addOrderBy('e.nom', 'ASC')
            ->addOrderBy('e.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
