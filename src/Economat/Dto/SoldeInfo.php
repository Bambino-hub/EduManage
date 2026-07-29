<?php

declare(strict_types=1);

namespace App\Economat\Dto;

/**
 * Récapitulatif financier d'un élève pour une année scolaire donnée : montant dû (d'après
 * le Tarif du niveau, null si aucun tarif défini), montant versé cumulé, solde restant.
 */
final readonly class SoldeInfo
{
    public function __construct(
        public ?int $montantDu,
        public int $montantPaye,
    ) {
    }

    public function tarifDefini(): bool
    {
        return $this->montantDu !== null;
    }

    public function solde(): ?int
    {
        return $this->montantDu !== null ? $this->montantDu - $this->montantPaye : null;
    }

    public function estSolde(): bool
    {
        return $this->tarifDefini() && $this->solde() <= 0;
    }
}
