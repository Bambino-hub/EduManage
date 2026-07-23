<?php

declare(strict_types=1);

namespace App\Grading\Service;

/**
 * Échelle d'appréciation automatique à partir d'une moyenne sur 20, reconstituée à
 * partir d'un bulletin réel du Collège Adèle (seuils confirmés sur 12 lignes).
 * Utilisée uniquement à la génération d'un bulletin (Grading\Service\BulletinGenerator)
 * — /admin/moyennes reste purement chiffré, sans appréciation.
 */
final class AppreciationScale
{
    public static function pour(?string $moyenne): ?string
    {
        if ($moyenne === null) {
            return null;
        }

        $valeur = (float) $moyenne;

        return match (true) {
            $valeur >= 18 => 'Excellent',
            $valeur >= 16 => 'Très-bien',
            $valeur >= 14 => 'Bien',
            $valeur >= 13 => 'Satisfaisant',
            $valeur >= 12 => 'Assez-bien',
            $valeur >= 10 => 'Passable',
            default => 'Insuffisant',
        };
    }
}
