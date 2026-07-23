<?php

declare(strict_types=1);

namespace App\Grading\Enum;

enum MentionConseil: string
{
    case FELICITATIONS = 'felicitations';
    case ENCOURAGEMENTS = 'encouragements';
    case TABLEAU_HONNEUR = 'tableau_honneur';
    case AVERTISSEMENT = 'avertissement';
    case BLAME = 'blame';

    public function label(): string
    {
        return match ($this) {
            self::FELICITATIONS => 'Félicitations',
            self::ENCOURAGEMENTS => 'Encouragements',
            self::TABLEAU_HONNEUR => 'Tableau d\'honneur',
            self::AVERTISSEMENT => 'Avertissement',
            self::BLAME => 'Blâme',
        };
    }
}
