<?php

declare(strict_types=1);

namespace App\Exam\Service\Dto;

/** Un poste de surveillance (examen × classe) qu'aucun enseignant éligible/disponible n'a pu couvrir. */
final class PosteNonPourvu
{
    public function __construct(
        public readonly string $examenLabel,
        public readonly string $classeNom,
        public readonly int $manque,
    ) {
    }
}
