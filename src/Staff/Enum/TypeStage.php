<?php

declare(strict_types=1);

namespace App\Staff\Enum;

/** Pertinent uniquement quand Enseignant::type = TypePersonnel::STAGIAIRE. */
enum TypeStage: string
{
    case PEDAGOGIQUE   = 'pedagogique';
    case ADMINISTRATIF = 'administratif';

    public function label(): string
    {
        return match($this) {
            self::PEDAGOGIQUE   => 'Stage pédagogique (classes)',
            self::ADMINISTRATIF => 'Stage administratif (bureau)',
        };
    }
}
