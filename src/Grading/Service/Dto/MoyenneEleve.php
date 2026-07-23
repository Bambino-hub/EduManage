<?php

declare(strict_types=1);

namespace App\Grading\Service\Dto;

use App\Student\Entity\Eleve;

/** Moyenne générale d'un élève pour un trimestre, et le détail par matière et par domaine. */
final class MoyenneEleve
{
    /**
     * @param array<int, MoyenneMatiereEleve> $moyennesParMatiere indexé par Matiere::getId()
     * @param BilanDomaineEleve[] $bilansDomaine toujours une entrée par DomaineMatiere::cases()
     */
    public function __construct(
        public readonly Eleve $eleve,
        public readonly array $moyennesParMatiere,
        public readonly ?string $moyenneGenerale,
        public readonly array $bilansDomaine,
    ) {
    }

    public function estNotee(): bool
    {
        return $this->moyenneGenerale !== null;
    }
}
