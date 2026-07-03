<?php

declare(strict_types=1);

namespace App\Staff\Service\Import;

use App\Staff\Enum\Sexe;

/**
 * Règles de nettoyage/interprétation des valeurs brutes extraites d'un document
 * (Word/Excel/PDF) de type "liste du personnel" — reprend l'heuristique validée
 * manuellement sur le document réel "Liste des enseignants 2025-2026".
 */
class EnseignantValeurNormalizer
{
    /**
     * Sépare une colonne unique "NOM & PRENOMS" en nom/prénom(s).
     * Convention observée dans les documents source : le(s) mot(s) écrit(s)
     * ENTIÈREMENT EN MAJUSCULES portent le nom de famille, le reste est le(s) prénom(s),
     * dans l'ordre d'origine (le nom peut être composé de plusieurs mots consécutifs,
     * ex. "TAPE DALLE Gbati"). Cas particuliers gérés :
     * - Un seul mot fourni (ex. "YAO") → tout est le nom, prénom vide.
     * - Aucun mot en majuscules (ex. "Séraphine") → tout est le nom (faute de mieux).
     * - Tous les mots en majuscules (ex. "N'WENA PYABALO") → le premier mot est le nom,
     *   le reste le(s) prénom(s), par convention.
     *
     * @return array{nom: string, prenom: string}
     */
    public function splitNomPrenom(string $nomPrenoms): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($nomPrenoms)) ?: []));
        if ($tokens === []) {
            return ['nom' => '', 'prenom' => ''];
        }

        $indexMajuscules = [];
        foreach ($tokens as $i => $mot) {
            if ($this->estMotMajuscule($mot)) {
                $indexMajuscules[] = $i;
            }
        }

        if ($indexMajuscules === []) {
            return ['nom' => implode(' ', $tokens), 'prenom' => ''];
        }

        if (count($indexMajuscules) === count($tokens)) {
            return [
                'nom'    => $tokens[0],
                'prenom' => implode(' ', array_slice($tokens, 1)),
            ];
        }

        $nomMots    = array_filter($tokens, static fn (int $i) => in_array($i, $indexMajuscules, true), ARRAY_FILTER_USE_KEY);
        $prenomMots = array_filter($tokens, static fn (int $i) => !in_array($i, $indexMajuscules, true), ARRAY_FILTER_USE_KEY);

        return [
            'nom'    => implode(' ', $nomMots),
            'prenom' => implode(' ', $prenomMots),
        ];
    }

    /** Un mot est considéré "en majuscules" s'il contient au moins 2 lettres, toutes capitales. */
    private function estMotMajuscule(string $mot): bool
    {
        $lettres = preg_replace('/[^\p{L}]/u', '', $mot) ?? '';

        return mb_strlen($lettres, 'UTF-8') >= 2 && mb_strtoupper($lettres, 'UTF-8') === $lettres;
    }

    /** "1", "2", "1/2", "1 / 2" → "1", "2", "1/2" ; "-"/vide → null. */
    public function normaliserCycle(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '' || $valeur === '-') {
            return null;
        }

        return preg_replace('/\s*\/\s*/', '/', $valeur) ?? $valeur;
    }

    /** "Privé", "Fonctionnaire externes" (texte de statut, pas un vrai matricule) → null. */
    public function normaliserMatricule(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '' || $valeur === '-') {
            return null;
        }

        $minuscule = mb_strtolower($valeur, 'UTF-8');
        if (str_contains($minuscule, 'privé') || str_contains($minuscule, 'prive') || str_contains($minuscule, 'fonctionnaire')) {
            return null;
        }

        return $valeur;
    }

    public function normaliserSexe(?string $valeur): ?Sexe
    {
        $valeur = strtoupper(trim((string) $valeur));

        return Sexe::tryFrom($valeur);
    }
}
