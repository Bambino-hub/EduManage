<?php

declare(strict_types=1);

namespace App\Staff\Service\Import;

use App\Staff\Enum\TypePersonnel;

/**
 * Interprète une grille 2D de cellules texte (1ère ligne = en-têtes) au format
 * "liste du personnel" (N°, Nom&Prénoms, Sexe, Matricule, Statut, Disciplines×2,
 * Cycle, Contact) en lignes prêtes pour EnseignantImporter::importerLigne().
 *
 * Utilisé par les 3 lecteurs (Docx/Xlsx/Pdf) pour partager la même logique de
 * correspondance de colonnes et de détection des sections du document.
 */
class EnseignantTableInterpreter
{
    public function __construct(
        private readonly EnseignantValeurNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<list<string|null>> $grille
     * @return list<array{nom: string, prenom: string, sexe: ?\App\Staff\Enum\Sexe, matricule: ?string,
     *     poste: ?string, specialite: ?string, cycle: ?string, telephone: string, type: TypePersonnel}>
     */
    public function interpreter(array $grille): array
    {
        if ($grille === []) {
            return [];
        }

        $enTetes = array_map(
            static fn (mixed $v) => $v !== null ? trim((string) $v) : null,
            $grille[0],
        );

        $iNom        = $this->trouverColonne($enTetes, 'NOM');
        $iSexe       = $this->trouverColonne($enTetes, 'SEXE');
        $iMatricule  = $this->trouverColonne($enTetes, 'MATRICULE');
        $iStatut     = $this->trouverColonne($enTetes, 'STATUT');
        $iDisciplines = $this->trouverColonnes($enTetes, 'DISCIPLIN');
        $iCycle      = $this->trouverColonne($enTetes, 'CYCLE');
        $iContact    = $this->trouverColonne($enTetes, 'CONTACT');

        $lignes      = [];
        $typeCourant = TypePersonnel::INTERNE;

        for ($r = 1, $n = count($grille); $r < $n; $r++) {
            $cellules = array_map(
                static fn (mixed $v) => $v !== null ? trim((string) $v) : '',
                $grille[$r],
            );

            if ($this->estLigneMarqueurSection($cellules)) {
                $texte = mb_strtoupper(implode(' ', $cellules), 'UTF-8');
                if (str_contains($texte, 'EXTERNE')) {
                    $typeCourant = TypePersonnel::EXTERNE;
                } elseif (str_contains($texte, 'AUTRE')) {
                    $typeCourant = TypePersonnel::AUTRE;
                }
                continue;
            }

            $nomPrenoms = $iNom !== null ? ($cellules[$iNom] ?? '') : '';
            if ($nomPrenoms === '' || str_contains(mb_strtoupper($nomPrenoms, 'UTF-8'), 'PRENOM')) {
                // Ligne vide ou en-tête "NOM & PRENOMS" dupliqué (artefact fréquent des tableaux Word).
                continue;
            }

            $split = $this->normalizer->splitNomPrenom($nomPrenoms);

            $disciplines = [];
            foreach ($iDisciplines as $iDisc) {
                $valeur = $this->nettoyerTexte($cellules[$iDisc] ?? '');
                if ($valeur !== null) {
                    $disciplines[] = $valeur;
                }
            }

            $lignes[] = [
                'nom'        => $split['nom'],
                'prenom'     => $split['prenom'],
                'sexe'       => $iSexe !== null ? $this->normalizer->normaliserSexe($cellules[$iSexe] ?? null) : null,
                'matricule'  => $iMatricule !== null ? $this->normalizer->normaliserMatricule($cellules[$iMatricule] ?? null) : null,
                'poste'      => $iStatut !== null ? $this->nettoyerTexte($cellules[$iStatut] ?? '') : null,
                'specialite' => $disciplines !== [] ? implode(', ', $disciplines) : null,
                'cycle'      => $iCycle !== null ? $this->normalizer->normaliserCycle($cellules[$iCycle] ?? null) : null,
                'telephone'  => $iContact !== null ? ($cellules[$iContact] ?? '') : '',
                'type'       => $typeCourant,
            ];
        }

        return $lignes;
    }

    /** @param string[] $enTetes */
    private function trouverColonne(array $enTetes, string $motif): ?int
    {
        $indices = $this->trouverColonnes($enTetes, $motif);

        return $indices[0] ?? null;
    }

    /** @param string[] $enTetes @return int[] */
    private function trouverColonnes(array $enTetes, string $motif): array
    {
        $indices = [];
        foreach ($enTetes as $i => $entete) {
            if ($entete !== null && str_contains($this->normaliserPourComparaison($entete), $motif)) {
                $indices[] = $i;
            }
        }

        return $indices;
    }

    private function normaliserPourComparaison(string $valeur): string
    {
        $valeur = iconv('UTF-8', 'ASCII//TRANSLIT', $valeur) ?: $valeur;

        return strtoupper(trim($valeur));
    }

    /**
     * Détecte une ligne "marqueur de section" (ex. "AUTRES", "LISTE DES ENSEIGNANTS EXTERNES")
     * plutôt qu'une ligne-personne : soit toutes les cellules non vides sont identiques
     * (cellule fusionnée), soit au moins 2 cellules distinctes contiennent le mot-clé.
     *
     * @param string[] $cellules
     */
    private function estLigneMarqueurSection(array $cellules): bool
    {
        $nonVides = array_values(array_filter($cellules, static fn (string $v) => $v !== ''));
        if ($nonVides === []) {
            return false;
        }

        // Cellule unique (ligne entièrement fusionnée) ou toutes les cellules identiques :
        // les deux cas se traitent pareil, un seul texte à comparer aux mots-clés.
        if (count(array_unique($nonVides)) === 1) {
            $texte = mb_strtoupper($nonVides[0], 'UTF-8');

            return str_contains($texte, 'AUTRE') || str_contains($texte, 'EXTERNE') || str_contains($texte, 'LISTE');
        }

        $motsCles = 0;
        foreach ($nonVides as $v) {
            $texte = mb_strtoupper($v, 'UTF-8');
            if (str_contains($texte, 'AUTRE') || str_contains($texte, 'EXTERNE')) {
                $motsCles++;
            }
        }

        return $motsCles >= 2;
    }

    private function nettoyerTexte(string $valeur): ?string
    {
        $valeur = trim(preg_replace('/\s+/u', ' ', $valeur) ?? $valeur);

        return $valeur !== '' && $valeur !== '-' ? $valeur : null;
    }
}
