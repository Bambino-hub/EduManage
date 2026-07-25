<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Grading\Service\Dto\FicheNotesExtraite;
use App\Grading\Service\Dto\LigneAssociee;
use App\Student\Entity\Inscription;

/**
 * Rapproche les lignes d'une fiche de notes extraite par OCR des élèves inscrits — délègue
 * l'algorithme de rapprochement de noms (identique pour toute fiche papier, bulletin ou
 * examen blanc) à {@see NomEleveMatcher}, et ne s'occupe plus que de reconstituer les
 * `LigneAssociee` avec leurs colonnes de notes (moy interro/devoir/compos).
 */
class NoteExtractionMatcher
{
    public function __construct(
        private readonly NomEleveMatcher $nomEleveMatcher,
    ) {
    }

    /**
     * @param Inscription[] $inscriptionsActives
     * @return LigneAssociee[]
     */
    public function associer(FicheNotesExtraite $fiche, array $inscriptionsActives): array
    {
        $noms = [];
        foreach ($fiche->lignes as $indexLigne => $ligne) {
            $noms[$indexLigne] = $ligne->nomExtrait;
        }

        $affectations = $this->nomEleveMatcher->associer($noms, $inscriptionsActives);

        $resultat = [];
        foreach ($fiche->lignes as $indexLigne => $ligne) {
            [$inscription, $score] = $affectations[$indexLigne];
            $resultat[] = new LigneAssociee(
                nomExtrait: $ligne->nomExtrait,
                eleve: $inscription?->getEleve(),
                score: $score,
                moyInterro: $ligne->moyInterro,
                moyDevoir: $ligne->moyDevoir,
                compos: $ligne->compos,
            );
        }

        return $resultat;
    }
}
