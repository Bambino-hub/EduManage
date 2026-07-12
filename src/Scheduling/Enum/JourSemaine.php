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

    /**
     * Jour de la semaine correspondant à une date calendaire (null le dimanche, jour sans
     * grille de cours). Source unique de cette correspondance — utilisée à la fois par le
     * module Exam (Examen::getJourSemaine()) pour croiser une date d'examen avec la grille
     * hebdomadaire Creneau, et pour l'affichage des tableaux par cycle.
     */
    public static function depuisDate(\DateTimeImmutable $date): ?self
    {
        return match ((int) $date->format('N')) {
            1 => self::LUNDI,
            2 => self::MARDI,
            3 => self::MERCREDI,
            4 => self::JEUDI,
            5 => self::VENDREDI,
            6 => self::SAMEDI,
            default => null,
        };
    }
}
