<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

/** Résultat de l'application d'un lot de permutations manuelles (vue globale de l'EDT). */
final class PermutationResult
{
    /** @param string[] $erreurs */
    public function __construct(
        public readonly bool $succes,
        public readonly array $erreurs = [],
    ) {
    }
}
