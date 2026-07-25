<?php

declare(strict_types=1);

namespace App\Academic\Repository;

use App\Academic\Entity\Matiere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Matiere>
 */
class MatiereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Matiere::class);
    }

    /**
     * Matières "core" susceptibles d'être évaluées à un examen blanc : ni facultatives
     * (groupeOptionnel) ni EPS. Sert de présélection par défaut sur ExamenBlanc::matieres —
     * l'admin décoche ensuite celles qui ne sont pas testées (Dessin, Musique, Bibliothèque…).
     *
     * @return Matiere[]
     */
    public function findEvaluablesParDefaut(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.groupeOptionnel IS NULL')
            ->andWhere('m.eps = false')
            ->orderBy('m.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
