<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

use App\Academic\Entity\Matiere;

/** Note d'un élève dans une matière, pour un examen blanc — une seule note (pas de sous-moyennes). */
final class MoyenneMatiereEleveBlanc
{
    public function __construct(
        public readonly Matiere $matiere,
        public readonly ?string $note,
        public readonly string $coefficient,
        public readonly string $enseignantNom,
    ) {
    }
}
