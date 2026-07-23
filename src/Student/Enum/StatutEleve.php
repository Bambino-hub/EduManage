<?php

declare(strict_types=1);

namespace App\Student\Enum;

enum StatutEleve: string
{
    case ACTIF = 'actif';
    case TRANSFERE = 'transfere';
    case EXCLU = 'exclu';
    case DIPLOME = 'diplome';

    public function label(): string
    {
        return match($this) {
            self::ACTIF => 'Actif',
            self::TRANSFERE => 'Transféré',
            self::EXCLU => 'Exclu',
            self::DIPLOME => 'Diplômé',
        };
    }
}
