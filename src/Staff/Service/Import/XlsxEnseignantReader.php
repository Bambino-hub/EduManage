<?php

declare(strict_types=1);

namespace App\Staff\Service\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Lit la première feuille d'un classeur Excel (.xlsx) au format "liste du personnel". */
class XlsxEnseignantReader implements EnseignantFileReaderInterface
{
    public function __construct(
        private readonly EnseignantTableInterpreter $interpreter,
    ) {
    }

    public function lire(string $cheminFichier): array
    {
        $spreadsheet = IOFactory::load($cheminFichier);
        $sheet       = $spreadsheet->getActiveSheet();
        $grille      = $sheet->toArray();

        $grille = $this->completerCellulesFusionnees($grille, $sheet->getMergeCells());

        return $this->interpreter->interpreter($grille);
    }

    /**
     * `toArray()` ne remplit que la cellule en haut-à-gauche d'une plage fusionnée (ex. l'en-tête
     * "DISCIPLINES" fusionné sur 2 colonnes, ou une ligne "AUTRES" fusionnée sur toute sa largeur) —
     * les autres cellules de la plage reviennent `null`. On propage la valeur sur toute la plage
     * pour que EnseignantTableInterpreter (correspondance de colonnes, détection des marqueurs
     * de section) voie une grille cohérente.
     *
     * @param list<list<mixed>> $grille
     * @param string[] $plagesFusionnees
     * @return list<list<string|null>>
     */
    private function completerCellulesFusionnees(array $grille, array $plagesFusionnees): array
    {
        foreach ($plagesFusionnees as $plage) {
            [$debut, $fin] = explode(':', $plage);
            [$colDebut, $ligneDebut] = Coordinate::indexesFromString($debut);
            [$colFin, $ligneFin]     = Coordinate::indexesFromString($fin);

            $valeur = $grille[$ligneDebut - 1][$colDebut - 1] ?? null;

            for ($r = $ligneDebut; $r <= $ligneFin; $r++) {
                for ($c = $colDebut; $c <= $colFin; $c++) {
                    $grille[$r - 1][$c - 1] = $valeur;
                }
            }
        }

        return $grille;
    }
}
