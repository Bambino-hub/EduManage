<?php

declare(strict_types=1);

namespace App\Grading\Service\Dto;

use App\Academic\Entity\Matiere;

/** Moyenne d'un élève dans une matière, pour un trimestre donné. */
final class MoyenneMatiereEleve
{
    public function __construct(
        public readonly Matiere $matiere,
        public readonly ?string $moyenneInterrogation,
        public readonly ?string $moyenneDevoirs,
        public readonly ?string $moyenneComposition,
        public readonly ?string $moyenne,
        public readonly string $coefficient,
        public readonly ?int $rang,
        public readonly string $enseignantNom,
    ) {
    }

    public function estNotee(): bool
    {
        return $this->moyenne !== null;
    }
}
