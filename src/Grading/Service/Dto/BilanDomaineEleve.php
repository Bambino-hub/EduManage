<?php

declare(strict_types=1);

namespace App\Grading\Service\Dto;

use App\Academic\Enum\DomaineMatiere;

/** Moyenne d'un élève sur un domaine de matières (Scientifique/Littéraire/Autre), pour un trimestre. */
final class BilanDomaineEleve
{
    public function __construct(
        public readonly DomaineMatiere $domaine,
        public readonly ?string $moyenne,
    ) {
    }
}
