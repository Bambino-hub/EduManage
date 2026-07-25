<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

use App\Academic\Entity\Classe;
use App\Student\Entity\Eleve;

/** Moyenne générale d'un élève pour un examen blanc (toutes classes du niveau confondues), et le détail par matière. */
final class MoyenneEleveBlanc
{
    /** @param array<int, MoyenneMatiereEleveBlanc> $moyennesParMatiere indexé par Matiere::getId() */
    public function __construct(
        public readonly Eleve $eleve,
        public readonly Classe $classe,
        public readonly array $moyennesParMatiere,
        public readonly ?string $moyenneGenerale,
        public readonly ?string $moyenneLitteraire,
        public readonly ?string $moyenneScientifique,
    ) {
    }

    public function estNotee(): bool
    {
        return $this->moyenneGenerale !== null;
    }
}
