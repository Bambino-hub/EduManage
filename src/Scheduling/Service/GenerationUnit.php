<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\Classe;
use App\Scheduling\Entity\Attribution;

/**
 * Une unité à placer dans l'emploi du temps : soit une Attribution seule,
 * soit un groupe d'Attributions "parallèles" (ex. Allemand/Espagnol) qui doivent
 * partager exactement les mêmes créneaux pour une même classe.
 */
final class GenerationUnit
{
    /** @param Attribution[] $attributions */
    public function __construct(
        public readonly Classe $classe,
        public readonly array $attributions,
        public readonly int $heures,
        public readonly string $libelle,
    ) {
    }
}
