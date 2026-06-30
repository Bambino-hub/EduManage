<?php

declare(strict_types=1);

namespace App\Scheduling\Enum;

enum JourSemaine: string
{
    case LUNDI    = 'lundi';
    case MARDI    = 'mardi';
    case MERCREDI = 'mercredi';
    case JEUDI    = 'jeudi';
    case VENDREDI = 'vendredi';
    case SAMEDI   = 'samedi';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function ordre(): int
    {
        return match($this) {
            self::LUNDI    => 1,
            self::MARDI    => 2,
            self::MERCREDI => 3,
            self::JEUDI    => 4,
            self::VENDREDI => 5,
            self::SAMEDI   => 6,
        };
    }
}
