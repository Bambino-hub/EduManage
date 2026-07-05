<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

/**
 * Bilan heures demandées / placées / capacité de créneaux pour une classe, après une
 * génération. Distingue un écart "structurel" (la classe demande plus d'heures que de
 * créneaux disponibles dans la semaine — aucun algorithme ne peut combler ça, c'est un
 * problème de données) d'un simple échec de placement (capacité suffisante mais des
 * conflits enseignant/salle empêchent de tout caser).
 */
final class ClasseBilan
{
    public function __construct(
        public readonly string $classeNom,
        public readonly int $heuresDemandees,
        public readonly int $heuresPlacees,
        public readonly int $capaciteCreneaux,
    ) {
    }

    public function estComplet(): bool
    {
        return $this->heuresPlacees >= $this->heuresDemandees;
    }

    /** Heures qu'aucune génération ne pourra jamais placer : demande > créneaux disponibles. */
    public function excedentStructurel(): int
    {
        return max(0, $this->heuresDemandees - $this->capaciteCreneaux);
    }

    /** Heures demandées non placées par cette génération (hors excédent structurel). */
    public function heuresManquantes(): int
    {
        return max(0, $this->heuresDemandees - $this->heuresPlacees);
    }
}
