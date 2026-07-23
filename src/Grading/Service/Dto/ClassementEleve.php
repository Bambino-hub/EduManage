<?php

declare(strict_types=1);

namespace App\Grading\Service\Dto;

/** Une ligne du classement d'une classe : la moyenne d'un élève, et son rang (null = non classé, aucune note). */
final class ClassementEleve
{
    public function __construct(
        public readonly MoyenneEleve $moyenneEleve,
        public readonly ?int $rang,
    ) {
    }
}
