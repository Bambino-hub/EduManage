<?php

declare(strict_types=1);

namespace App\ExamenNational\Repository;

use App\ExamenNational\Entity\SessionExamenNational;
use App\ExamenNational\Enum\StatutSessionExamenNational;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SessionExamenNational>
 */
class SessionExamenNationalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionExamenNational::class);
    }

    /** @return SessionExamenNational[] */
    public function findValideesTriees(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.statut = :statut')
            ->setParameter('statut', StatutSessionExamenNational::VALIDE)
            ->orderBy('s.anneeSession', 'DESC')
            ->addOrderBy('s.type', 'ASC')
            ->addOrderBy('s.serie', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
