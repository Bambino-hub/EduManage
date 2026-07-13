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
     * Pool mobilisable par la génération auto du tableau de surveillance : enseignants
     * internes et stagiaires actifs — les vacataires ("externe") en sont exclus, de même que
     * le personnel non-enseignant ("autre" ET internes dont le poste n'est pas un poste
     * d'enseignement, ex. Censeur, Économe, Secrétaire, Directrice — ces derniers ont le type
     * "interne" mais ne surveillent pas). Les stagiaires n'ont pas de champ "poste" (ils sont
     * toujours éligibles, par construction).
     *
     * @return Enseignant[]
     */
    public function findEligiblesSurveillance(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.actif = true')
            ->andWhere('e.type = :stagiaire OR (e.type = :interne AND e.poste LIKE :poste)')
            ->setParameter('stagiaire', TypePersonnel::STAGIAIRE)
            ->setParameter('interne', TypePersonnel::INTERNE)
            ->setParameter('poste', '%enseignant%')
            ->orderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
