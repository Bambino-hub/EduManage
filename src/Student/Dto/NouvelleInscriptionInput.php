<?php

declare(strict_types=1);

namespace App\Student\Dto;

use App\Academic\Entity\Niveau;
use App\Student\Entity\Eleve;

/**
 * Support du formulaire de première inscription : crée l'élève et son inscription
 * (niveau seul, sans classe) en une seule soumission.
 */
class NouvelleInscriptionInput
{
    public Eleve $eleve;
    public ?Niveau $niveau = null;
    public ?\DateTimeImmutable $dateInscription = null;
    public bool $redoublant = false;

    public function __construct()
    {
        $this->eleve = new Eleve();
    }
}
