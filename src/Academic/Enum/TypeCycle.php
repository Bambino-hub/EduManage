<?php

declare(strict_types=1);

namespace App\Academic\Enum;

enum TypeCycle: string
{
    case COLLEGE = 'college';
    case LYCEE   = 'lycee';

    public function label(): string
    {
        return match($this) {
            self::COLLEGE => 'Collège',
            self::LYCEE   => 'Lycée',
        };
    }
}
