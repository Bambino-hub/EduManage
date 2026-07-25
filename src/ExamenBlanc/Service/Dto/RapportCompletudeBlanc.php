<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

use App\Academic\Entity\Niveau;

final class RapportCompletudeBlanc
{
    /**
     * @param Niveau[] $niveaux colonnes du tableau
     * @param MatiereCompletudeBlanc[] $matieres lignes du tableau
     */
    public function __construct(
        public readonly array $niveaux,
        public readonly array $matieres,
    ) {
    }
}
