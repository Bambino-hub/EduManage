<?php

declare(strict_types=1);

namespace App\Exam\Service\Dto;

/** Résultat global d'une exécution d'ExamenSurveillanceGenerator sur un cycle. */
final class GenerationResultSurveillance
{
    /**
     * @param PosteNonPourvu[] $postesNonPourvus
     * @param string[] $successions surveillances successives qui n'ont pas pu être évitées (une phrase chacune)
     */
    public function __construct(
        public readonly int $examensTraites,
        public readonly int $postesRequis,
        public readonly int $surveillancesCreees,
        public readonly array $postesNonPourvus = [],
        public readonly array $successions = [],
    ) {
    }

    public function succes(): bool
    {
        return $this->postesNonPourvus === [] && $this->postesRequis > 0;
    }
}
