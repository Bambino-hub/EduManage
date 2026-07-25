<?php

declare(strict_types=1);

namespace App\Student\Repository;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Academic\Entity\Niveau;
use App\Student\Entity\Inscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inscription>
 */
class InscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inscription::class);
    }

    /** Élèves actuellement inscrits (inscription non clôturée) dans une classe. @return Inscription[] */
    public function findActivesByClasse(Classe $classe): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('e')
            ->join('i.eleve', 'e')
            ->where('i.classe = :classe')
            ->andWhere('i.dateFin IS NULL')
            ->setParameter('classe', $classe)
            ->orderBy('e.nom', 'ASC')
            ->addOrderBy('e.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Élèves actuellement inscrits dans une classe active d'un niveau, TOUTES CLASSES DU
     * NIVEAU CONFONDUES — un examen blanc note le niveau entier comme un seul groupe, pas
     * classe par classe (voir ExamenBlanc\Service\ExamenBlancMoyenneCalculator) : triés par
     * nom/prénom sur l'ensemble du niveau, pas groupés par classe.
     *
     * @return Inscription[]
     */
    public function findActivesByNiveauEtAnnee(Niveau $niveau, AnneeScolaire $anneeScolaire): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('e', 'c')
            ->join('i.eleve', 'e')
            ->join('i.classe', 'c')
            ->where('c.niveau = :niveau')
            ->andWhere('c.anneeScolaire = :annee')
            ->andWhere('c.active = true')
            ->andWhere('i.dateFin IS NULL')
            ->setParameter('niveau', $niveau)
            ->setParameter('annee', $anneeScolaire)
            ->orderBy('e.nom', 'ASC')
            ->addOrderBy('e.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Inscriptions en cours sans classe pour un niveau donné — écran d'affectation en lot. @return Inscription[] */
    public function findEnCoursSansClasseByNiveau(Niveau $niveau): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('e')
            ->join('i.eleve', 'e')
            ->where('i.niveau = :niveau')
            ->andWhere('i.dateFin IS NULL')
            ->andWhere('i.classe IS NULL')
            ->setParameter('niveau', $niveau)
            ->orderBy('e.nom', 'ASC')
            ->addOrderBy('e.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
