<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Academic\Entity\Matiere;

/**
 * Devine la matière enseignée par un enseignant à partir de son champ "spécialité"
 * (texte libre saisi/importé, ex: "PC", "FR, ECM", "ESPAGNOL", "PHILLO"). Sert
 * uniquement à pré-remplir le formulaire d'attribution — un rapprochement qui
 * échoue n'empêche rien, l'utilisateur choisit alors la matière manuellement.
 */
class SpecialiteMatiereMatcher
{
    /** @param Matiere[] $matieres */
    public function deviner(?string $specialite, array $matieres): ?Matiere
    {
        if ($specialite === null || trim($specialite) === '') {
            return null;
        }

        foreach (preg_split('/[,\/]/', $specialite) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $match = $this->matcherToken($token, $matieres);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** @param Matiere[] $matieres */
    private function matcherToken(string $token, array $matieres): ?Matiere
    {
        $normToken = $this->normaliser($token);
        if ($normToken === '') {
            return null;
        }

        foreach ($matieres as $matiere) {
            if ($this->normaliser($matiere->getCode()) === $normToken) {
                return $matiere;
            }
        }

        foreach ($matieres as $matiere) {
            if ($this->normaliser($matiere->getNom()) === $normToken) {
                return $matiere;
            }
        }

        // Abréviations/troncatures courantes : "MUSIQUE" pour le code "MUSIQ", etc.
        foreach ($matieres as $matiere) {
            $normCode = $this->normaliser($matiere->getCode());
            if (mb_strlen($normCode) >= 3 && (str_starts_with($normToken, $normCode) || str_starts_with($normCode, $normToken))) {
                return $matiere;
            }
        }

        // Fautes de frappe légères (ex: "PHILLO" pour "PHILO").
        if (mb_strlen($normToken) >= 4) {
            foreach ($matieres as $matiere) {
                if (levenshtein($normToken, $this->normaliser($matiere->getCode())) <= 1) {
                    return $matiere;
                }
                if (levenshtein($normToken, $this->normaliser($matiere->getNom())) <= 1) {
                    return $matiere;
                }
            }
        }

        return null;
    }

    private function normaliser(string $valeur): string
    {
        $translitere = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valeur);

        return strtoupper(preg_replace('/[^A-Za-z]/', '', $translitere ?: $valeur) ?? '');
    }
}
