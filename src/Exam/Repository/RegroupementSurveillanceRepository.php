<?php

declare(strict_types=1);

namespace App\Exam\Repository;

use App\Exam\Entity\RegroupementSurveillance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegroupementSurveillance>
 */
class RegroupementSurveillanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegroupementSurveillance::class);
    }

    /** @return RegroupementSurveillance[] */
    public function findAllAvecRelations(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c')
            ->leftJoin('r.classes', 'c')
            ->orderBy('r.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Index classeId => regroupementId, pour que le générateur de surveillance sache
     * quelles classes doivent recevoir le(s) même(s) surveillant(s) sur un examen donné.
     *
     * @return array<int, int>
     */
    public function findGroupeParClasseId(): array
    {
        $map = [];
        foreach ($this->findAllAvecRelations() as $regroupement) {
            foreach ($regroupement->getClasses() as $classe) {
                $map[$classe->getId()] = $regroupement->getId();
            }
        }
        return $map;
    }
}
