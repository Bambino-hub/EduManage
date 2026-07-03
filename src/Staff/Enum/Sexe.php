<?php

declare(strict_types=1);

namespace App\Staff\Enum;

enum Sexe: string
{
    case M = 'M';
    case F = 'F';

    public function label(): string
    {
        return match($this) {
            self::M => 'Masculin',
            self::F => 'Féminin',
        };
    }
}
