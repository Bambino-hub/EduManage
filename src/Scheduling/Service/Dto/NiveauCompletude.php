<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Niveau;

/** Complétude des attributions pour un niveau : ses classes et, matière par matière, qui les couvre. */
final class NiveauCompletude
{
    /**
     * @param Classe[] $classes
     * @param MatiereCompletude[] $matieres
     * @param ClasseSansOption[] $classesSansOption classes n'ayant choisi aucune matière d'un groupe optionnel attendu
     */
    public function __construct(
        public readonly Niveau $niveau,
        public readonly array $classes,
        public readonly array $matieres,
        public readonly array $classesSansOption,
    ) {
    }

    public function estComplet(): bool
    {
        if ($this->classesSansOption !== []) {
            return false;
        }

        foreach ($this->matieres as $matiere) {
            if (!$matiere->estComplet()) {
                return false;
            }
        }

        return true;
    }

    public function nombreManquants(): int
    {
        return array_sum(array_map(
            static fn (MatiereCompletude $m): int => count($m->classesManquantes),
            $this->matieres,
        )) + count($this->classesSansOption);
    }
}
