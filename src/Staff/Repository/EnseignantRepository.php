<?php

declare(strict_types=1);

namespace App\Staff\Repository;

use App\Staff\Entity\Enseignant;
use App\Staff\Enum\TypePersonnel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Enseignant>
 */
class EnseignantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enseignant::class);
    }

    /** @return Enseignant[] */
    public function findActifs(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.actif = true')
            ->orderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Personnel "classique" (hors stagiaires, qui ont leur propre page). @return Enseignant[] */
    public function findHorsStagiaires(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.type != :stagiaire')
            ->setParameter('stagiaire', TypePersonnel::STAGIAIRE)
            ->orderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
