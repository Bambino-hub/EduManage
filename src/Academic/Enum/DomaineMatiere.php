<?php

declare(strict_types=1);

namespace App\Academic\Enum;

enum DomaineMatiere: string
{
    case SCIENTIFIQUE = 'scientifique';
    case LITTERAIRE   = 'litteraire';
    case AUTRE        = 'autre';

    public function label(): string
    {
        return match($this) {
            self::SCIENTIFIQUE => 'Scientifique',
            self::LITTERAIRE   => 'Littéraire',
            self::AUTRE        => 'Autre',
        };
    }
}
