<?php

declare(strict_types=1);

namespace App\Staff\Service\Import;

use App\Staff\Enum\Sexe;
use App\Staff\Enum\TypePersonnel;

interface EnseignantFileReaderInterface
{
    /**
     * @return list<array{nom: string, prenom: string, sexe: ?Sexe, matricule: ?string,
     *     poste: ?string, specialite: ?string, cycle: ?string, telephone: string, type: TypePersonnel}>
     */
    public function lire(string $cheminFichier): array;
}
