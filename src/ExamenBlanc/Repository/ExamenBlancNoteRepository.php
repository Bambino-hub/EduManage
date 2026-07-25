<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Repository;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Entity\ExamenBlancNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExamenBlancNote>
 */
class ExamenBlancNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamenBlancNote::class);
    }

    /** Notes déjà saisies pour une fiche niveau/matière, indexées par id inscription (pré-remplissage de la grille). @return array<int, ExamenBlancNote> */
    public function findByNiveauEtMatiereIndexeesParInscription(ExamenBlanc $examenBlanc, Niveau $niveau, Matiere $matiere): array
    {
        $notes = $this->createQueryBuilder('n')
            ->join('n.inscription', 'i')
            ->join('i.classe', 'c')
            ->where('n.examenBlanc = :examenBlanc')
            ->andWhere('n.matiere = :matiere')
            ->andWhere('c.niveau = :niveau')
            ->setParameter('examenBlanc', $examenBlanc)
            ->setParameter('matiere', $matiere)
            ->setParameter('niveau', $niveau)
            ->getQuery()
            ->getResult();

        $indexees = [];
        foreach ($notes as $note) {
            $indexees[$note->getInscription()->getId()] = $note;
        }

        return $indexees;
    }

    /**
     * Toutes les notes d'un niveau entier pour un examen blanc (toutes matières et classes
     * confondues), avec matière/inscription/élève/classe déjà chargés — utilisé par
     * ExamenBlancMoyenneCalculator pour éviter le N+1.
     *
     * @return ExamenBlancNote[]
     */
    public function findByExamenBlancEtNiveau(ExamenBlanc $examenBlanc, Niveau $niveau): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('m', 'i', 'e', 'c')
            ->join('n.matiere', 'm')
            ->join('n.inscription', 'i')
            ->join('i.eleve', 'e')
            ->join('i.classe', 'c')
            ->where('n.examenBlanc = :examenBlanc')
            ->andWhere('c.niveau = :niveau')
            ->andWhere('c.active = true')
            ->setParameter('examenBlanc', $examenBlanc)
            ->setParameter('niveau', $niveau)
            ->getQuery()
            ->getResult();
    }

    /**
     * Vrai si au moins une note a été réellement saisie (valeur ou absent) pour cette fiche
     * niveau/matière — sert à exclure une matière jamais notée du calcul (pas de faux zéro) et
     * à afficher le statut "rempli/non rempli" sur la grille de complétude.
     */
    public function existeNoteRenseignee(ExamenBlanc $examenBlanc, Niveau $niveau, Matiere $matiere): bool
    {
        $resultat = $this->createQueryBuilder('n')
            ->select('1')
            ->join('n.inscription', 'i')
            ->join('i.classe', 'c')
            ->where('n.examenBlanc = :examenBlanc')
            ->andWhere('n.matiere = :matiere')
            ->andWhere('c.niveau = :niveau')
            ->andWhere('n.valeur IS NOT NULL OR n.absent = true')
            ->setParameter('examenBlanc', $examenBlanc)
            ->setParameter('matiere', $matiere)
            ->setParameter('niveau', $niveau)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $resultat !== null;
    }
}
