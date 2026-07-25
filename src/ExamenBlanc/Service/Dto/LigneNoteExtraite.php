<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

/** Une ligne (un élève) telle que lue par l'extraction vision sur une fiche d'examen blanc. */
final class LigneNoteExtraite
{
    public function __construct(
        public readonly string $nomExtrait,
        public readonly ?float $note,
    ) {
    }
}
