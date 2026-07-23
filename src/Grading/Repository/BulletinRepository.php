<?php

declare(strict_types=1);

namespace App\Grading\Repository;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Grading\Entity\Bulletin;
use App\Grading\Entity\Trimestre;
use App\Student\Entity\Eleve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bulletin>
 */
class BulletinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bulletin::class);
    }

    /** @return Bulletin[] */
    public function findByClasseEtTrimestre(Classe $classe, Trimestre $trimestre): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('e')
            ->join('b.eleve', 'e')
            ->where('b.classe = :classe')
            ->andWhere('b.trimestre = :trimestre')
            ->setParameter('classe', $classe)
            ->setParameter('trimestre', $trimestre)
            ->orderBy('e.nom', 'ASC')
            ->addOrderBy('e.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Bulletin[] tous les bulletins déjà générés pour cet élève cette année scolaire, tous trimestres, triés par numéro. */
    public function findByEleveEtAnneeScolaire(Eleve $eleve, AnneeScolaire $anneeScolaire): array
    {
        return $this->createQueryBuilder('b')
            ->join('b.trimestre', 't')
            ->addSelect('t')
            ->where('b.eleve = :eleve')
            ->andWhere('t.anneeScolaire = :annee')
            ->setParameter('eleve', $eleve)
            ->setParameter('annee', $anneeScolaire)
            ->orderBy('t.numero', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
