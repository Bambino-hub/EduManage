<?php

declare(strict_types=1);

namespace App\Exam\Repository;

use App\Exam\Entity\Surveillance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Surveillance>
 */
class SurveillanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Surveillance::class);
    }

    /**
     * Toutes les surveillances des examens donnés, avec classe et enseignant déjà chargés —
     * utilisé pour l'affichage du tableau (superposé à la grille d'examens) et pour la purge
     * avant régénération.
     *
     * @param int[] $examenIds
     * @return Surveillance[]
     */
    public function findByExamens(array $examenIds): array
    {
        if ($examenIds === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->join('s.classe', 'c')
            ->addSelect('c')
            ->join('s.enseignant', 'e')
            ->addSelect('e')
            ->where('s.examen IN (:examenIds)')
            ->setParameter('examenIds', $examenIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Surveillances données par id, avec examen (+ niveaux), classe et enseignant déjà chargés
     * — utilisé par la permutation manuelle pour valider un lot de changements.
     *
     * @param int[] $ids
     * @return Surveillance[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->join('s.examen', 'ex')
            ->addSelect('ex')
            ->join('ex.niveaux', 'n')
            ->addSelect('n')
            ->join('s.classe', 'c')
            ->addSelect('c')
            ->join('s.enseignant', 'e')
            ->addSelect('e')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les surveillances existantes des enseignants donnés, année confondue, avec examen
     * déjà chargé — utilisé par la permutation manuelle pour vérifier qu'un enseignant déplacé
     * vers un autre examen n'est pas déjà occupé à cet horaire par une autre surveillance.
     *
     * @param int[] $enseignantIds
     * @return Surveillance[]
     */
    public function findByEnseignants(array $enseignantIds): array
    {
        if ($enseignantIds === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->join('s.examen', 'ex')
            ->addSelect('ex')
            ->where('s.enseignant IN (:ids)')
            ->setParameter('ids', $enseignantIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les surveillances d'UN enseignant, examen (+ matière) et classe déjà chargés,
     * triées chronologiquement — utilisé par la fiche détaillée de l'enseignant pour afficher
     * son planning de surveillance complet et le nombre total de fois qu'il surveille.
     *
     * @return Surveillance[]
     */
    public function findByEnseignant(int $enseignantId): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.examen', 'ex')
            ->addSelect('ex')
            ->join('ex.matiere', 'm')
            ->addSelect('m')
            ->join('s.classe', 'c')
            ->addSelect('c')
            ->where('s.enseignant = :id')
            ->setParameter('id', $enseignantId)
            ->orderBy('ex.date', 'ASC')
            ->addOrderBy('ex.heureDebut', 'ASC')
            ->addOrderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre total de surveillances par enseignant, toute l'année — utilisé par le récapitulatif
     * des surveillants et par la fiche enseignant pour repérer les écarts d'équité en un coup
     * d'œil. Compte les EXAMENS distincts couverts (`COUNT(DISTINCT s.examen)`), pas les lignes
     * brutes : un enseignant affecté à un `RegroupementSurveillance` (ex. 1ère C + 1ère D1, même
     * salle) génère 2 lignes en base pour ce même examen, mais c'est une seule et même
     * surveillance en pratique — la compter deux fois gonflerait artificiellement sa charge
     * affichée de 1 par examen concerné, ce qui a effectivement biaisé la mesure d'équité perçue
     * jusqu'au 2026-07-13 (l'algorithme, lui, comptait déjà correctement en interne : la charge
     * réelle était équilibrée à ±1, alors que l'affichage brut montrait un écart de 2-3).
     *
     * @return array<int, int> id enseignant => nombre de surveillances
     */
    public function compterParEnseignant(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.enseignant) as enseignantId', 'COUNT(DISTINCT s.examen) as charge')
            ->groupBy('s.enseignant')
            ->getQuery()
            ->getResult();

        return array_map('intval', array_column($rows, 'charge', 'enseignantId'));
    }
}
