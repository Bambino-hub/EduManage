<?php

declare(strict_types=1);

namespace App\Scheduling\Enum;

/**
 * D'où provient une entrée de l'historique des emplois du temps
 * (voir EmploiDuTempsVersion / EmploiDuTempsHistorique).
 */
enum OrigineVersionEdt: string
{
    /** Instantané pris juste après une génération automatique. */
    case Generation = 'generation';

    /** Instantané enregistré manuellement par l'utilisateur ("Enregistrer l'état actuel"). */
    case Manuel = 'manuel';

    /** Instantané automatique de l'emploi du temps existant, pris juste avant une nouvelle génération (filet de sécurité si on a oublié la sauvegarde manuelle). */
    case PreGeneration = 'pre_generation';

    /** Instantané automatique de l'état courant, pris juste avant une restauration (permet de revenir en arrière). */
    case PreRestauration = 'pre_restauration';

    /**
     * Instantané automatique pris juste avant un échange ciblé de 2 attributions
     * (AttributionEchangeService), avant toute écriture. Valeur volontairement
     * raccourcie ('echange_attribution', pas 'avant_echange_attribution') pour
     * tenir dans la colonne `origine` (VARCHAR(20)).
     */
    case AvantEchangeAttribution = 'echange_attribution';

    public function libelle(): string
    {
        return match ($this) {
            self::Generation             => 'Génération automatique',
            self::Manuel                 => 'Enregistrement manuel',
            self::PreGeneration          => 'Sauvegarde avant génération',
            self::PreRestauration        => 'Sauvegarde avant restauration',
            self::AvantEchangeAttribution => 'Sauvegarde avant échange d\'attribution',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Generation             => 'text-bg-primary',
            self::Manuel                 => 'text-bg-success',
            self::PreGeneration          => 'text-bg-warning text-dark',
            self::PreRestauration        => 'text-bg-secondary',
            self::AvantEchangeAttribution => 'text-bg-info',
        };
    }
}
