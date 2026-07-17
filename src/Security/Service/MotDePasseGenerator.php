<?php

declare(strict_types=1);

namespace App\Security\Service;

/**
 * Génère un mot de passe temporaire lisible (ex. "vaillant-42-cactus"), plus facile à
 * transmettre à l'oral ou par SMS qu'une chaîne aléatoire opaque, tout en restant assez
 * long pour résister au brute-force le temps que l'utilisateur le change.
 */
class MotDePasseGenerator
{
    private const MOTS = [
        'orange', 'papaye', 'cactus', 'baobab', 'savane', 'kara', 'togo', 'craie',
        'stylo', 'cahier', 'tableau', 'crayon', 'classe', 'lecon', 'examen', 'reussite',
        'vaillant', 'brillant', 'rigueur', 'talent', 'sagesse', 'courage', 'mercure', 'safran',
    ];

    public function generer(): string
    {
        $mot1 = self::MOTS[array_rand(self::MOTS)];
        $mot2 = self::MOTS[array_rand(self::MOTS)];

        return sprintf('%s-%d-%s', $mot1, random_int(10, 99), $mot2);
    }
}
