<?php

declare(strict_types=1);

namespace App\Student\Service\Import;

use App\Staff\Enum\Sexe;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lit la première feuille d'un classeur Excel (.xlsx) listant les élèves à importer.
 * Colonnes attendues (n'importe quel ordre, détectées par en-tête) : Matricule, Nom,
 * Prénom(s), Sexe, Date de naissance, Classe. Un seul format pris en charge (Excel) —
 * contrairement à l'import enseignants, pas de lecteur Word/PDF ici : les fichiers
 * d'inscription élèves proviennent en pratique d'un tableur, pas d'un document imprimé.
 */
class XlsxEleveReader
{
    /**
     * @return list<array{matricule: ?string, nom: string, prenom: string, sexe: ?Sexe,
     *     dateNaissance: ?string, classe: ?string}>
     */
    public function lire(string $cheminFichier): array
    {
        $spreadsheet = IOFactory::load($cheminFichier);
        $grille      = $spreadsheet->getActiveSheet()->toArray();

        if ($grille === []) {
            return [];
        }

        $enTetes = array_map(
            static fn (mixed $v) => $v !== null ? trim((string) $v) : null,
            $grille[0],
        );

        $iMatricule = $this->trouverColonne($enTetes, 'MATRICULE');
        $iNom       = $this->trouverColonne($enTetes, 'NOM');
        $iPrenom    = $this->trouverColonne($enTetes, 'PRENOM');
        $iSexe      = $this->trouverColonne($enTetes, 'SEXE');
        $iNaissance = $this->trouverColonne($enTetes, 'NAISSANCE');
        $iClasse    = $this->trouverColonne($enTetes, 'CLASSE');

        $lignes = [];
        for ($r = 1, $n = count($grille); $r < $n; $r++) {
            $cellules = array_map(
                static fn (mixed $v) => $v !== null ? trim((string) $v) : '',
                $grille[$r],
            );

            $nom = $iNom !== null ? ($cellules[$iNom] ?? '') : '';
            if ($nom === '') {
                continue; // ligne vide
            }

            $lignes[] = [
                'matricule'     => $iMatricule !== null ? $this->videSiVide($cellules[$iMatricule] ?? '') : null,
                'nom'           => $nom,
                'prenom'        => $iPrenom !== null ? ($cellules[$iPrenom] ?? '') : '',
                'sexe'          => $iSexe !== null ? Sexe::tryFrom(strtoupper($cellules[$iSexe] ?? '')) : null,
                'dateNaissance' => $iNaissance !== null ? $this->videSiVide($cellules[$iNaissance] ?? '') : null,
                'classe'        => $iClasse !== null ? $this->videSiVide($cellules[$iClasse] ?? '') : null,
            ];
        }

        return $lignes;
    }

    /** @param (string|null)[] $enTetes */
    private function trouverColonne(array $enTetes, string $motif): ?int
    {
        foreach ($enTetes as $i => $entete) {
            if ($entete !== null && str_contains($this->normaliser($entete), $motif)) {
                return $i;
            }
        }
        return null;
    }

    private function normaliser(string $valeur): string
    {
        $valeur = iconv('UTF-8', 'ASCII//TRANSLIT', $valeur) ?: $valeur;
        return strtoupper(trim($valeur));
    }

    private function videSiVide(string $valeur): ?string
    {
        return $valeur !== '' ? $valeur : null;
    }
}
