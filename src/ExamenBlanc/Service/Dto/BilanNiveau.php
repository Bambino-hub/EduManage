<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

/** Statistiques du niveau entier pour un examen blanc : plus faible/forte moyenne générale et moyenne du niveau. */
final class BilanNiveau
{
    public function __construct(
        public readonly ?string $moyenneFaible,
        public readonly ?string $moyenneForte,
        public readonly ?string $moyenneNiveau,
    ) {
    }
}
