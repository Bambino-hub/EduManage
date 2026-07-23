<?php

declare(strict_types=1);

namespace App\Grading\Service\Dto;

/** Une ligne (un élève) telle que lue par l'extraction vision sur une fiche de notes papier. */
final class LigneEleveExtraite
{
    public function __construct(
        public readonly string $nomExtrait,
        public readonly ?float $moyInterro,
        public readonly ?float $moyDevoir,
        public readonly ?float $compos,
    ) {
    }
}
