<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Enum\TypeCycle;
use App\Scheduling\Enum\JourSemaine;

/**
 * Règles métier de placement d'un créneau, communes au générateur automatique
 * (EmploiDuTempsGenerator) et aux permutations manuelles depuis la vue globale
 * (EmploiDuTempsPermutationService) — un seul endroit pour ces contraintes évite
 * qu'elles divergent entre les deux points d'entrée.
 */
final class ReglesPlacementCreneau
{
    /** 8ème heure : réservée au lycée, uniquement lundi et jeudi. */
    public static function ordre8Eligible(TypeCycle $cycle, JourSemaine $jour): bool
    {
        return $cycle === TypeCycle::LYCEE
            && in_array($jour, [JourSemaine::LUNDI, JourSemaine::JEUDI], true);
    }

    /**
     * EPS ne se place jamais à la 4ème ni à la 5ème heure, quel que soit le cycle
     * (ces créneaux précèdent immédiatement la pause déjeuner, jugés inadaptés à une
     * séance de sport).
     */
    public static function epsInterdit(int $ordre): bool
    {
        return in_array($ordre, [4, 5], true);
    }

    /** FHR (Formation Humaine et Religieuse) ne se place jamais le vendredi après-midi. */
    public static function fhrInterdit(JourSemaine $jour, \DateTimeImmutable $heureDebut): bool
    {
        return $jour === JourSemaine::VENDREDI && (int) $heureDebut->format('H') >= 13;
    }
}
