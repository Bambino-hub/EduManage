<?php

declare(strict_types=1);

namespace App\Exam\Service\Dto;

use App\Exam\Entity\Examen;
use App\Scheduling\Enum\JourSemaine;

/**
 * Une ligne de la grille d'un cycle : un couple (date, heure) unique, et pour chaque niveau
 * concerné les examens programmés à ce moment (généralement un seul, potentiellement plusieurs
 * si deux matières différentes tombent au même horaire pour des niveaux différents).
 */
final class GrilleLigne
{
    /** @param array<int, Examen[]> $examensParNiveau id niveau => Examen[] */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly \DateTimeImmutable $heureDebut,
        public readonly \DateTimeImmutable $heureFin,
        public readonly ?JourSemaine $jourSemaine,
        public readonly array $examensParNiveau,
    ) {
    }
}
