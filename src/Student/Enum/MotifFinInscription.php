<?php

declare(strict_types=1);

namespace App\Student\Enum;

enum MotifFinInscription: string
{
    case TRANSFERT = 'transfert';
    case ABANDON = 'abandon';
    case EXCLUSION = 'exclusion';
    case FIN_ANNEE = 'fin_annee';

    public function label(): string
    {
        return match($this) {
            self::TRANSFERT => 'Transfert vers une autre classe',
            self::ABANDON => 'Abandon',
            self::EXCLUSION => 'Exclusion',
            self::FIN_ANNEE => 'Fin d\'année scolaire',
        };
    }
}
