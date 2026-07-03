<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Dto;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Scheduling\Entity\Attribution;

/**
 * Couverture d'une matière sur les classes d'un niveau qui la suivent réellement.
 *
 * Pour une matière sans groupeOptionnel, toutes les classes du niveau sont concernées.
 * Pour une matière à choix (ex. Allemand), seules les classes ayant explicitement
 * choisi cette matière (Classe::$matieresOptionnelles) sont concernées — une classe qui
 * n'a pas choisi Allemand n'a pas de trou "enseignant manquant" sur cette matière (voir
 * plutôt NiveauCompletude::$classesSansOption pour le cas où elle n'a rien choisi du tout).
 */
final class MatiereCompletude
{
    /**
     * @param array<int, ?Attribution> $attributionsParClasseId indexé par Classe::getId(), uniquement pour les classes concernées
     * @param array<int, bool> $concerneeParClasseId indexé par Classe::getId()
     * @param Classe[] $classesManquantes
     */
    public function __construct(
        public readonly Matiere $matiere,
        public readonly array $attributionsParClasseId,
        public readonly array $concerneeParClasseId,
        public readonly array $classesManquantes,
    ) {
    }

    public function estComplet(): bool
    {
        return $this->classesManquantes === [];
    }
}
