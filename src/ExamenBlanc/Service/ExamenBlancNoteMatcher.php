<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service;

use App\ExamenBlanc\Service\Dto\FicheNoteExamenBlancExtraite;
use App\ExamenBlanc\Service\Dto\LigneNoteAssociee;
use App\Grading\Service\NomEleveMatcher;
use App\Student\Entity\Inscription;

/**
 * Rapproche les lignes d'une fiche d'examen blanc extraite par OCR des élèves inscrits —
 * délègue l'algorithme de rapprochement de noms à Grading\Service\NomEleveMatcher (identique
 * à celui utilisé pour l'import des notes de bulletin), ne reconstitue que la colonne Note.
 */
final class ExamenBlancNoteMatcher
{
    public function __construct(
        private readonly NomEleveMatcher $nomEleveMatcher,
    ) {
    }

    /**
     * @param Inscription[] $inscriptionsActives
     * @return LigneNoteAssociee[]
     */
    public function associer(FicheNoteExamenBlancExtraite $fiche, array $inscriptionsActives): array
    {
        $noms = [];
        foreach ($fiche->lignes as $indexLigne => $ligne) {
            $noms[$indexLigne] = $ligne->nomExtrait;
        }

        $affectations = $this->nomEleveMatcher->associer($noms, $inscriptionsActives);

        $resultat = [];
        foreach ($fiche->lignes as $indexLigne => $ligne) {
            [$inscription, $score] = $affectations[$indexLigne];
            $resultat[] = new LigneNoteAssociee(
                nomExtrait: $ligne->nomExtrait,
                eleve: $inscription?->getEleve(),
                score: $score,
                note: $ligne->note,
            );
        }

        return $resultat;
    }
}
