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

    /**
     * Un enseignant marqué indisponible aux N premières heures (Enseignant::getNbPremieresHeuresAEviter())
     * ne se voit jamais programmer un créneau d'ordre <= N, quel que soit le jour de la semaine.
     */
    public static function premieresHeuresInterdites(int $ordre, int $nbPremieresHeuresAEviter): bool
    {
        return $nbPremieresHeuresAEviter > 0 && $ordre <= $nbPremieresHeuresAEviter;
    }

    /**
     * Un enseignant peut être marqué indisponible sur certaines heures de l'après-midi
     * (Enseignant::getHeuresApresMidiInterdites() — liste d'ordres parmi [6, 7, 8],
     * paramétrable finement : seulement la 8ème heure, ou toute l'après-midi, etc.).
     *
     * @param int[] $heuresApresMidiInterdites
     */
    public static function apresMidiInterdit(int $ordre, array $heuresApresMidiInterdites): bool
    {
        return in_array($ordre, $heuresApresMidiInterdites, true);
    }

    /**
     * Deux séances d'EPS d'une même classe ne doivent jamais tomber sur deux jours qui
     * se suivent (au moins un jour plein entre les deux : lundi puis mercredi au plus
     * tôt). Retourne true si les deux jours sont consécutifs.
     */
    public static function epsJoursTropProches(JourSemaine $a, JourSemaine $b): bool
    {
        return $a !== $b && abs($a->ordre() - $b->ordre()) <= 1;
    }
}
