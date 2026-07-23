<?php

declare(strict_types=1);

namespace App\Grading\Service\Dto;

/** Résultat brut de l'extraction vision d'une fiche de notes papier, avant tout matching. */
final class FicheNotesExtraite
{
    /** @param LigneEleveExtraite[] $lignes */
    public function __construct(
        public readonly ?string $classe,
        public readonly ?string $matiere,
        public readonly ?string $professeur,
        public readonly array $lignes,
    ) {
    }
}
