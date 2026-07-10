<?php

declare(strict_types=1);

namespace App\Staff\Enum;

enum TypePersonnel: string
{
    case INTERNE   = 'interne';
    case EXTERNE   = 'externe';
    case AUTRE     = 'autre';
    case STAGIAIRE = 'stagiaire';

    public function label(): string
    {
        return match($this) {
            self::INTERNE   => 'Enseignant interne',
            self::EXTERNE   => 'Vacataire / Externe',
            self::AUTRE     => 'Personnel non-enseignant',
            self::STAGIAIRE => 'Stagiaire',
        };
    }
}
