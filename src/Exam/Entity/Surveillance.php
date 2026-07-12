<?php

declare(strict_types=1);

namespace App\Exam\Entity;

use App\Academic\Entity\Classe;
use App\Exam\Repository\SurveillanceRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Staff\Entity\Enseignant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un enseignant affecté à la surveillance d'une classe pour un examen — généré automatiquement
 * par ExamenSurveillanceGenerator (une classe passe l'examen dans sa propre salle, pas de
 * sélection de salle distincte).
 */
#[ORM\Entity(repositoryClass: SurveillanceRepository::class)]
#[ORM\Table(name: 'surveillance')]
#[ORM\UniqueConstraint(fields: ['examen', 'classe', 'enseignant'])]
#[ORM\HasLifecycleCallbacks]
class Surveillance
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Examen::class, inversedBy: 'surveillances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Examen $examen = null;

    #[ORM\ManyToOne(targetEntity: Classe::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Classe $classe = null;

    #[ORM\ManyToOne(targetEntity: Enseignant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Enseignant $enseignant = null;

    public function getId(): ?int { return $this->id; }

    public function getExamen(): ?Examen { return $this->examen; }

    public function setExamen(?Examen $examen): static
    {
        $this->examen = $examen;
        return $this;
    }

    public function getClasse(): ?Classe { return $this->classe; }

    public function setClasse(?Classe $classe): static
    {
        $this->classe = $classe;
        return $this;
    }

    public function getEnseignant(): ?Enseignant { return $this->enseignant; }

    public function setEnseignant(?Enseignant $enseignant): static
    {
        $this->enseignant = $enseignant;
        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s — %s — %s',
            $this->examen?->getLabel() ?? '?',
            $this->classe?->getNom() ?? '?',
            $this->enseignant?->getNomComplet() ?? '?',
        );
    }
}
