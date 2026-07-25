<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

/** Résultat brut de l'extraction vision d'une fiche d'examen blanc papier, avant tout matching. */
final class FicheNoteExamenBlancExtraite
{
    /** @param LigneNoteExtraite[] $lignes */
    public function __construct(
        public readonly ?string $classe,
        public readonly ?string $matiere,
        public readonly ?string $professeur,
        public readonly array $lignes,
    ) {
    }
}
