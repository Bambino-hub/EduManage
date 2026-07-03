<?php

declare(strict_types=1);

namespace App\Academic\Enum;

enum GroupeOptionnel: string
{
    case LV2    = 'lv2';
    case TM_EM  = 'tm_em';

    public function label(): string
    {
        return match($this) {
            self::LV2   => 'LV2 au choix (ex. Allemand / Espagnol)',
            self::TM_EM => 'Travail Manuel / Enseignement Ménager',
        };
    }
}
