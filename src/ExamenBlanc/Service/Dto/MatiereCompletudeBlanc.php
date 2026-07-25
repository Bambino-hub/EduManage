<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

use App\Academic\Entity\Matiere;

/**
 * Une ligne de la grille de complétude d'un examen blanc : pour chaque niveau de l'examen où
 * cette matière est évaluée, son statut (remplie ou non) — absence d'entrée pour un niveau
 * donné = matière non enseignée à ce niveau (ou non sélectionnée dans ExamenBlanc::matieres).
 */
final class MatiereCompletudeBlanc
{
    /** @param array<int, StatutFicheBlanc> $statutParNiveauId indexé par Niveau::getId() */
    public function __construct(
        public readonly Matiere $matiere,
        public readonly array $statutParNiveauId,
    ) {
    }
}
