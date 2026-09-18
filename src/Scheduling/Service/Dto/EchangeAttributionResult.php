<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

/** Résultat de l'échange ciblé de deux attributions (AttributionEchangeService). */
final class EchangeAttributionResult
{
    /** @param string[] $erreurs */
    public function __construct(
        public readonly bool $succes,
        public readonly array $erreurs = [],
    ) {
    }
}
