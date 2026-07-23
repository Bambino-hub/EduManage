<?php

declare(strict_types=1);

namespace App\ExamenNational\Service\Dto;

/** Statistiques d'une matière sur une session : N/min/max + répartition [0;6[ [6;10[ [10;15[ [15;20]. */
final class StatistiqueMatiere
{
    public function __construct(
        public readonly string $libelle,
        public readonly string $typeEpreuve,
        public readonly int $n,
        public readonly float $min,
        public readonly float $max,
        public readonly int $bande0a6,
        public readonly int $bande6a10,
        public readonly int $bande10a15,
        public readonly int $bande15a20,
    ) {
    }
}
