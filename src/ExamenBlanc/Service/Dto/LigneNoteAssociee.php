<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

use App\Student\Entity\Eleve;

/** Une ligne extraite, rapprochée (ou non) d'un élève réel de la classe — à valider par un humain. */
final class LigneNoteAssociee
{
    public function __construct(
        public readonly string $nomExtrait,
        public readonly ?Eleve $eleve,
        public readonly int $score,
        public readonly ?float $note,
    ) {
    }
}
