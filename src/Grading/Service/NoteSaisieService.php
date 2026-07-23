<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Grading\Entity\Evaluation;
use App\Grading\Entity\Note;
use App\Grading\Repository\NoteRepository;
use App\Student\Entity\Inscription;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistrement en lot des notes d'une évaluation, à partir de la grille de saisie
 * (un élève par ligne). Logique partagée entre la saisie enseignant et la correction
 * admin — les deux contrôleurs ne font que collecter le POST et appeler ce service.
 */
class NoteSaisieService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteRepository $noteRepo,
    ) {
    }

    /**
     * @param Inscription[] $inscriptionsActives élèves à noter (classe de l'évaluation)
     * @param array<int, array{valeur?: string, absent?: string}> $donneesParEleveId
     */
    public function enregistrer(Evaluation $evaluation, array $inscriptionsActives, array $donneesParEleveId): void
    {
        $notesExistantes = $this->noteRepo->findByEvaluationIndexeesParEleve($evaluation);

        foreach ($inscriptionsActives as $inscription) {
            $eleve      = $inscription->getEleve();
            $eleveId    = $eleve->getId();
            $donnees    = $donneesParEleveId[$eleveId] ?? [];
            $absent     = !empty($donnees['absent']);
            $valeurBrute = trim((string) ($donnees['valeur'] ?? ''));

            $note = $notesExistantes[$eleveId] ?? new Note();
            if ($note->getId() === null) {
                $note->setEvaluation($evaluation);
                $note->setEleve($eleve);
                $this->em->persist($note);
            }

            $note->setAbsent($absent);
            $note->setValeur($absent ? null : $this->normaliserValeur($valeurBrute));
        }
    }

    private function normaliserValeur(string $valeurBrute): ?string
    {
        if ($valeurBrute === '' || !is_numeric($valeurBrute)) {
            return null;
        }

        $valeur = max(0.0, min(20.0, (float) $valeurBrute));

        return number_format($valeur, 2, '.', '');
    }
}
