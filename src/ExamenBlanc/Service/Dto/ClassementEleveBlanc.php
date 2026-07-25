<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service\Dto;

/** Une ligne du classement d'un niveau pour un examen blanc : la moyenne d'un élève, et son rang (null = non classé). */
final class ClassementEleveBlanc
{
    public function __construct(
        public readonly MoyenneEleveBlanc $moyenneEleve,
        public readonly ?int $rang,
    ) {
    }
}
