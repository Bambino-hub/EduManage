<?php

declare(strict_types=1);

namespace App\Grading\Repository;

use App\Grading\Entity\Evaluation;
use App\Grading\Entity\Note;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    /** Notes déjà saisies pour une évaluation, indexées par id élève (pré-remplissage de la grille). @return array<int, Note> */
    public function findByEvaluationIndexeesParEleve(Evaluation $evaluation): array
    {
        $notes = $this->createQueryBuilder('n')
            ->where('n.evaluation = :evaluation')
            ->setParameter('evaluation', $evaluation)
            ->getQuery()
            ->getResult();

        $indexees = [];
        foreach ($notes as $note) {
            $indexees[$note->getEleve()->getId()] = $note;
        }

        return $indexees;
    }

    /**
     * Vrai si au moins une évaluation de la liste a été réellement traitée (note saisie ou
     * élève marqué absent) — sert à distinguer une matière effectivement évaluée d'une
     * évaluation créée mais dont la fiche n'a encore jamais été enregistrée (voir
     * MoyenneCalculator::calculer(), qui exclut cette dernière du tableau des moyennes).
     *
     * @param int[] $evaluationIds
     */
    public function existeNoteRenseignee(array $evaluationIds): bool
    {
        if ($evaluationIds === []) {
            return false;
        }

        $resultat = $this->createQueryBuilder('n')
            ->select('1')
            ->where('n.evaluation IN (:ids)')
            ->andWhere('n.valeur IS NOT NULL OR n.absent = true')
            ->setParameter('ids', $evaluationIds)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $resultat !== null;
    }
}
