<?php

declare(strict_types=1);

namespace App\Grading\Service\Dto;

/** Statistiques de classe pour un trimestre : plus faible/forte moyenne générale et moyenne de classe. */
final class BilanClasse
{
    public function __construct(
        public readonly ?string $moyenneFaible,
        public readonly ?string $moyenneForte,
        public readonly ?string $moyenneClasse,
    ) {
    }
}
