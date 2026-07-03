<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

/** Rapport de complétude des attributions pour une année scolaire, un NiveauCompletude par niveau. */
final class RapportCompletude
{
    /** @param NiveauCompletude[] $niveaux */
    public function __construct(
        public readonly array $niveaux,
    ) {
    }

    public function estComplet(): bool
    {
        foreach ($this->niveaux as $niveau) {
            if (!$niveau->estComplet()) {
                return false;
            }
        }

        return true;
    }

    public function nombreManquants(): int
    {
        return array_sum(array_map(
            static fn (NiveauCompletude $n): int => $n->nombreManquants(),
            $this->niveaux,
        ));
    }
}
