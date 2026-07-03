<?php

declare(strict_types=1);

namespace App\Scheduling\Repository;

use App\Scheduling\Entity\Seance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Seance>
 */
class SeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seance::class);
    }

    /** @return Seance[] */
    public function findByClasse(int $classeId): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.attribution', 'a')
            ->join('s.creneau', 'c')
            ->where('a.classe = :classeId')
            ->setParameter('classeId', $classeId)
            ->orderBy('c.ordre', 'ASC')
            ->addOrderBy('c.heureDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Toutes les séances de l'année, pour la vue globale (toutes classes côte à côte). @return Seance[] */
    public function findByAnneeScolaire(int $anneeScolaireId): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.attribution', 'a')
            ->addSelect('a')
            ->join('a.classe', 'cl')
            ->addSelect('cl')
            ->join('a.matiere', 'm')
            ->addSelect('m')
            ->join('s.creneau', 'c')
            ->addSelect('c')
            ->where('cl.anneeScolaire = :anneeId')
            ->setParameter('anneeId', $anneeScolaireId)
            ->getQuery()
            ->getResult();
    }

    /** @return Seance[] */
    public function findByEnseignant(int $enseignantId): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.attribution', 'a')
            ->join('s.creneau', 'c')
            ->where('a.enseignant = :enseignantId')
            ->setParameter('enseignantId', $enseignantId)
            ->orderBy('c.ordre', 'ASC')
            ->addOrderBy('c.heureDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Détecte si un créneau est déjà pris pour une salle donnée */
    public function existeConflitSalle(int $salleId, int $creneauId, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.salle = :salleId AND s.creneau = :creneauId')
            ->setParameter('salleId', $salleId)
            ->setParameter('creneauId', $creneauId);

        if ($excludeId) {
            $qb->andWhere('s.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** Détecte si un créneau est déjà pris pour un enseignant donné */
    public function existeConflitEnseignant(int $enseignantId, int $creneauId, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.attribution', 'a')
            ->where('a.enseignant = :enseignantId AND s.creneau = :creneauId')
            ->setParameter('enseignantId', $enseignantId)
            ->setParameter('creneauId', $creneauId);

        if ($excludeId) {
            $qb->andWhere('s.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** Détecte si un créneau est déjà pris pour une classe donnée */
    public function existeConflitClasse(int $classeId, int $creneauId, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.attribution', 'a')
            ->where('a.classe = :classeId AND s.creneau = :creneauId')
            ->setParameter('classeId', $classeId)
            ->setParameter('creneauId', $creneauId);

        if ($excludeId) {
            $qb->andWhere('s.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
