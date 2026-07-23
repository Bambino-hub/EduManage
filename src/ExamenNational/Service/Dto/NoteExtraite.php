<?php

declare(strict_types=1);

namespace App\ExamenNational\Service\Dto;

/** Une ligne (une matière) du tableau de notes d'un candidat, telle que lue par l'IA. */
final class NoteExtraite
{
    public function __construct(
        public readonly string $typeEpreuve, // 'ecrite' | 'facultative'
        public readonly string $matiere,
        public readonly ?float $note,
        public readonly ?float $coefficient,
        public readonly ?float $pointsObtenus,
    ) {
    }
}
