<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Etablissement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Etablissement>
 */
class EtablissementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Etablissement::class);
    }

    /** Une seule ligne existe toujours en base : la crée au premier accès si besoin. */
    public function getOuCreer(): Etablissement
    {
        $etablissement = $this->findOneBy([]);
        if ($etablissement !== null) {
            return $etablissement;
        }

        $etablissement = new Etablissement();
        $this->getEntityManager()->persist($etablissement);
        $this->getEntityManager()->flush();

        return $etablissement;
    }
}
