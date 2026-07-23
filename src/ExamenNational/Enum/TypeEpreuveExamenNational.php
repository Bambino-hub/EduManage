<?php

declare(strict_types=1);

namespace App\ExamenNational\Enum;

enum TypeEpreuveExamenNational: string
{
    case ECRITE = 'ecrite';
    case FACULTATIVE = 'facultative';

    public function label(): string
    {
        return match($this) {
            self::ECRITE => 'Épreuve écrite',
            self::FACULTATIVE => 'Épreuve facultative',
        };
    }
}
