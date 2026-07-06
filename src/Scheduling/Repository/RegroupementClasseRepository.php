<?php

declare(strict_types=1);

namespace App\Scheduling\Repository;

use App\Scheduling\Entity\RegroupementClasse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegroupementClasse>
 */
class RegroupementClasseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegroupementClasse::class);
    }

    /** @return RegroupementClasse[] */
    public function findAllAvecRelations(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c', 'm')
            ->leftJoin('r.classes', 'c')
            ->leftJoin('r.matieres', 'm')
            ->orderBy('r.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Index [classeId][matiereId] => regroupementId, pour retrouver rapidement si une
     * Attribution (classe × matière) fait partie d'un regroupement de classes
     * fusionnées. Partagé entre le générateur d'EDT, l'éditeur manuel de permutations et
     * l'affichage de la vue globale — un seul endroit pour cette indexation évite qu'elle
     * diverge entre ces 3 usages.
     *
     * @return array<int, array<int, int>>
     */
    public function indexerParClasseEtMatiere(): array
    {
        $index = [];
        foreach ($this->findAllAvecRelations() as $regroupement) {
            foreach ($regroupement->getClasses() as $classe) {
                foreach ($regroupement->getMatieres() as $matiere) {
                    $index[$classe->getId()][$matiere->getId()] = $regroupement->getId();
                }
            }
        }

        return $index;
    }
}
