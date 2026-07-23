<?php

declare(strict_types=1);

namespace App\ExamenNational\Enum;

enum TypeExamenNational: string
{
    case BEPC = 'bepc';
    case BAC1 = 'bac1';
    case BAC2 = 'bac2';

    public function label(): string
    {
        return match($this) {
            self::BEPC => 'BEPC',
            self::BAC1 => 'BAC — 1ère partie',
            self::BAC2 => 'BAC — 2ème partie',
        };
    }
}
