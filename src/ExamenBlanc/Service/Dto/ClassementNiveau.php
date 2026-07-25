<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Entity\ExamenBlanc;

/** Classement d'un niveau entier pour un examen blanc : les matières (colonnes) et les élèves classés, toutes classes du niveau confondues. */
final class ClassementNiveau
{
    /**
     * @param Matiere[] $matieres colonnes du tableau, ordre stable
     * @param ClassementEleveBlanc[] $classement élèves notés triés par rang croissant, puis non-notés
     */
    public function __construct(
        public readonly Niveau $niveau,
        public readonly ExamenBlanc $examenBlanc,
        public readonly array $matieres,
        public readonly array $classement,
        public readonly BilanNiveau $bilanNiveau,
    ) {
    }
}
