<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

use App\Academic\Entity\Classe;
use App\Academic\Enum\GroupeOptionnel;

/** Signale une classe qui n'a choisi aucune matière d'un groupe optionnel pourtant enseigné à son niveau. */
final class ClasseSansOption
{
    public function __construct(
        public readonly Classe $classe,
        public readonly GroupeOptionnel $groupe,
    ) {
    }
}
