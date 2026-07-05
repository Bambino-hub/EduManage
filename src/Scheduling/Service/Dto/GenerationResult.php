<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

/** Résultat global d'une exécution du générateur d'emploi du temps. */
final class GenerationResult
{
    /**
     * @param UnitResult[] $unites
     * @param ClasseBilan[] $bilanClasses
     */
    public function __construct(
        public readonly int $tentatives,
        public readonly int $heuresPlacees,
        public readonly int $heuresNonPlacees,
        public readonly array $unites,
        public readonly array $bilanClasses = [],
    ) {
    }

    public function succes(): bool
    {
        return $this->heuresNonPlacees === 0 && $this->heuresPlacees > 0;
    }

    /** @return UnitResult[] */
    public function unitesIncompletes(): array
    {
        return array_values(array_filter($this->unites, fn (UnitResult $u) => !$u->estComplet()));
    }

    /** @return ClasseBilan[] classes avec un écart, structurel ou de placement */
    public function classesAvecEcart(): array
    {
        return array_values(array_filter($this->bilanClasses, fn (ClasseBilan $c) => !$c->estComplet()));
    }
}
