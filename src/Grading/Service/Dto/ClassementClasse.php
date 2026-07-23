<?php

declare(strict_types=1);

namespace App\Grading\Service\Dto;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Grading\Entity\Trimestre;

/** Classement d'une classe pour un trimestre : les matières (colonnes) et les élèves classés. */
final class ClassementClasse
{
    /**
     * @param Matiere[] $matieres colonnes du tableau, ordre stable
     * @param ClassementEleve[] $classement élèves notés triés par rang croissant, puis non-notés
     */
    public function __construct(
        public readonly Classe $classe,
        public readonly Trimestre $trimestre,
        public readonly array $matieres,
        public readonly array $classement,
        public readonly BilanClasse $bilanClasse,
    ) {
    }

    public function nombreClasses(): int
    {
        return count(array_filter(
            $this->classement,
            static fn (ClassementEleve $c): bool => $c->rang !== null,
        ));
    }
}
