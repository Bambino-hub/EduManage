<?php

declare(strict_types=1);

namespace App\Grading\Enum;

enum TypeEvaluation: string
{
    case INTERROGATION = 'interrogation';
    case DEVOIR = 'devoir';
    case COMPOSITION = 'composition';

    public function label(): string
    {
        return match($this) {
            self::INTERROGATION => 'Interrogation',
            self::DEVOIR => 'Devoir',
            self::COMPOSITION => 'Composition',
        };
    }
}
