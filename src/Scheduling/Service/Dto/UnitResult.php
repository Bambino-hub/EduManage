<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

/** Résultat de placement pour une unité (une Attribution, ou un groupe d'Attributions parallèles). */
final class UnitResult
{
    /** @param string[] $raisonsEchec */
    public function __construct(
        public readonly string $libelle,
        public readonly int $heuresDemandees,
        public readonly int $heuresPlacees,
        public readonly array $raisonsEchec = [],
    ) {
    }

    public function estComplet(): bool
    {
        return $this->heuresPlacees >= $this->heuresDemandees;
    }
}
