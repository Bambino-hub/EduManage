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

    /**
     * Pool mobilisable par la génération auto du tableau de surveillance : personnel actif
     * dont la case "Autorisé(e) à surveiller les devoirs" est cochée, quels que soient son
     * statut et sa fonction (jusqu'au 2026-10-01 : internes à poste d'enseignement +
     * stagiaires, déduit du statut et de la fonction — abandonné, trop rigide pour autoriser
     * au cas par cas, ex. un externe).
     *
     * @return Enseignant[]
     */
    public function findEligiblesSurveillance(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.actif = true')
            ->andWhere('e.autoriseSurveillance = true')
            ->orderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
