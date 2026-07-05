<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\Classe;
use App\Scheduling\Entity\Attribution;

/**
 * Une unité à placer dans l'emploi du temps : soit une Attribution seule, soit un
 * groupe d'Attributions qui doivent partager exactement les mêmes créneaux — parce
 * qu'elles sont parallèles au sein d'une classe (ex. Allemand/Espagnol), ou parce que
 * leurs classes sont fusionnées pour cette matière (ex. 1ère C / 1ère D1 en HG).
 */
final class GenerationUnit
{
    /**
     * @param Classe[] $classes Une seule classe la plupart du temps ; plusieurs si
     *                          l'unité fusionne des classes différentes (RegroupementClasse).
     * @param Attribution[] $attributions
     */
    public function __construct(
        public readonly array $classes,
        public readonly array $attributions,
        public readonly int $heures,
        public readonly string $libelle,
    ) {
    }
}
