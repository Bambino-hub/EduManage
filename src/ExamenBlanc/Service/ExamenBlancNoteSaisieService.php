<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Entity\ExamenBlancNote;
use App\ExamenBlanc\Repository\ExamenBlancNoteRepository;
use App\Student\Entity\Inscription;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistrement en lot des notes d'une fiche d'examen blanc — une fiche par (niveau,
 * matière), fusionnant toutes les classes du niveau (un élève par ligne, une seule colonne
 * Note/20) — sur le modèle de Grading\Service\NoteSaisieService. Crée une ligne
 * ExamenBlancNote pour CHAQUE élève du niveau, même laissée vide : c'est ce qui permet à
 * ExamenBlancMoyenneCalculator de distinguer "personne noté" (matière pas encore commencée,
 * pas de faux zéro) de "fiche remplie, cet élève laissé vide" (compte 0).
 */
final class ExamenBlancNoteSaisieService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExamenBlancNoteRepository $noteRepo,
    ) {
    }

    /**
     * @param Inscription[] $inscriptionsActives élèves à noter (niveau entier)
     * @param array<int, array{valeur?: string, absent?: string}> $donneesParEleveId
     */
    public function enregistrer(ExamenBlanc $examenBlanc, Niveau $niveau, Matiere $matiere, array $inscriptionsActives, array $donneesParEleveId): void
    {
        $notesExistantes = $this->noteRepo->findByNiveauEtMatiereIndexeesParInscription($examenBlanc, $niveau, $matiere);

        foreach ($inscriptionsActives as $inscription) {
            $eleveId     = $inscription->getEleve()->getId();
            $donnees     = $donneesParEleveId[$eleveId] ?? [];
            $absent      = !empty($donnees['absent']);
            $valeurBrute = trim((string) ($donnees['valeur'] ?? ''));

            $note = $notesExistantes[$inscription->getId()] ?? new ExamenBlancNote();
            if ($note->getId() === null) {
                $note->setExamenBlanc($examenBlanc);
                $note->setMatiere($matiere);
                $note->setInscription($inscription);
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
