<?php

declare(strict_types=1);

namespace App\ExamenNational\Service\Dto;

/** Un candidat (une page du relevé) tel que lu par l'extraction vision. */
final class CandidatExtrait
{
    /** @param NoteExtraite[] $notes */
    public function __construct(
        public readonly string $nom,
        public readonly string $prenoms,
        public readonly ?string $sexe,
        public readonly ?string $dateNaissance,
        public readonly ?string $lieuNaissance,
        public readonly ?string $numeroJury,
        public readonly ?string $numeroTable,
        public readonly ?string $serie,
        public readonly ?string $libelleSerie,
        public readonly ?string $session,
        public readonly ?string $centreExamen,
        public readonly ?string $decisionJury,
        public readonly ?float $moyenneGlobaleAffichee,
        public readonly ?float $totalPointsEcritesAffiche,
        public readonly array $notes,
    ) {
    }
}
