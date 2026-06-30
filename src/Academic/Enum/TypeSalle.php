<?php

declare(strict_types=1);

namespace App\Academic\Enum;

enum TypeSalle: string
{
    case STANDARD      = 'standard';
    case LABORATOIRE   = 'laboratoire';
    case INFORMATIQUE  = 'informatique';
    case GYMNASE       = 'gymnase';

    public function label(): string
    {
        return match($this) {
            self::STANDARD     => 'Salle standard',
            self::LABORATOIRE  => 'Laboratoire',
            self::INFORMATIQUE => 'Salle informatique',
            self::GYMNASE      => 'Gymnase / Terrain',
        };
    }
}
