<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

/** Statut d'une fiche niveau/matière sur la grille de complétude : remplie ou non, effectif du niveau. */
final class StatutFicheBlanc
{
    public function __construct(
        public readonly bool $renseignee,
        public readonly int $effectif,
    ) {
    }
}
