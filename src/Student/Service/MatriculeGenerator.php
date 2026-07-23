<?php

declare(strict_types=1);

namespace App\Student\Service;

use App\Student\Repository\EleveRepository;

/**
 * Génère le matricule d'un nouvel élève : 5 caractères alphanumériques majuscules,
 * attribué une seule fois à la création et jamais régénéré ensuite (contrairement à
 * l'import Excel où le matricule est la numérotation officielle du dossier papier —
 * voir EleveImporter). Alphabet réduit pour éviter les confusions visuelles (0/O, 1/I).
 */
class MatriculeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LONGUEUR = 5;

    public function __construct(private readonly EleveRepository $eleveRepo)
    {
    }

    public function generer(): string
    {
        do {
            $matricule = $this->tirer();
        } while ($this->eleveRepo->findOneBy(['matricule' => $matricule]) !== null);

        return $matricule;
    }

    private function tirer(): string
    {
        $matricule = '';
        for ($i = 0; $i < self::LONGUEUR; $i++) {
            $matricule .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $matricule;
    }
}
