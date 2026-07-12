<?php

declare(strict_types=1);

namespace App\Exam\Service\Dto;

/** Résultat de l'application d'un lot de permutations manuelles (tableau de surveillance). */
final class PermutationResultSurveillance
{
    /** @param string[] $erreurs */
    public function __construct(
        public readonly bool $succes,
        public readonly array $erreurs = [],
    ) {
    }
}
