<?php

declare(strict_types=1);

namespace App\ExamenNational\Repository;

use App\ExamenNational\Entity\NoteMatiereCandidat;
use App\ExamenNational\Entity\SessionExamenNational;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NoteMatiereCandidat>
 */
class NoteMatiereCandidatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteMatiereCandidat::class);
    }

    /** Toutes les notes d'une session, candidat chargé en une requête — base des statistiques et exports. @return NoteMatiereCandidat[] */
    public function findBySession(SessionExamenNational $session): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.candidat', 'c')
            ->addSelect('c')
            ->where('c.session = :session')
            ->setParameter('session', $session)
            ->orderBy('c.pageNumero', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
