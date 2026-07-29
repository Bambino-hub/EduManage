<?php

declare(strict_types=1);

namespace App\Economat\Repository;

use App\Academic\Entity\AnneeScolaire;
use App\Economat\Entity\Recu;
use App\Student\Entity\Eleve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recu>
 */
class RecuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recu::class);
    }

    /** Reçus d'un élève pour une année scolaire, du plus ancien au plus récent. @return Recu[] */
    public function findByEleveEtAnnee(Eleve $eleve, AnneeScolaire $anneeScolaire): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('l')
            ->join('r.lignes', 'l')
            ->where('r.eleve = :eleve')
            ->andWhere('r.anneeScolaire = :annee')
            ->setParameter('eleve', $eleve)
            ->setParameter('annee', $anneeScolaire)
            ->orderBy('r.dateEmission', 'ASC')
            ->addOrderBy('r.numero', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les années pour lesquelles cet élève a au moins un reçu, la plus récente d'abord.
     * Deux requêtes plutôt qu'un DISTINCT sur l'entité jointe directement : Doctrine DQL
     * refuse "SELECT DISTINCT <alias joint>" sans sélectionner aussi l'alias racine.
     *
     * @return AnneeScolaire[]
     */
    public function findAnneesAvecRecuPourEleve(Eleve $eleve): array
    {
        $ids = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.anneeScolaire)')
            ->distinct()
            ->where('r.eleve = :eleve')
            ->setParameter('eleve', $eleve)
            ->getQuery()
            ->getSingleColumnResult();

        if ($ids === []) {
            return [];
        }

        return $this->getEntityManager()->createQueryBuilder()
            ->select('a')
            ->from(AnneeScolaire::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('a.dateDebut', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Somme des montants versés par un élève pour une année, tous reçus/lignes confondus. */
    public function sommeLignesPourEleveEtAnnee(Eleve $eleve, AnneeScolaire $anneeScolaire): int
    {
        $total = $this->createQueryBuilder('r')
            ->select('SUM(l.montant)')
            ->join('r.lignes', 'l')
            ->where('r.eleve = :eleve')
            ->andWhere('r.anneeScolaire = :annee')
            ->setParameter('eleve', $eleve)
            ->setParameter('annee', $anneeScolaire)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }
}
