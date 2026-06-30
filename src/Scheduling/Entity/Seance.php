<?php

declare(strict_types=1);

namespace App\Scheduling\Entity;

use App\Academic\Entity\Salle;
use App\Scheduling\Repository\SeanceRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Représente un créneau récurrent dans l'emploi du temps :
 * "chaque semaine, cette attribution a lieu dans cette salle à ce créneau".
 * La détection de conflits se fait sur (creneau + salle) et (creneau + enseignant)
 * et (creneau + classe).
 */
#[ORM\Entity(repositoryClass: SeanceRepository::class)]
#[ORM\Table(name: 'seance')]
#[ORM\HasLifecycleCallbacks]
class Seance
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Attribution::class, inversedBy: 'seances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Attribution $attribution = null;

    #[ORM\ManyToOne(targetEntity: Salle::class, inversedBy: 'seances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Salle $salle = null;

    #[ORM\ManyToOne(targetEntity: Creneau::class, inversedBy: 'seances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Creneau $creneau = null;

    public function getId(): ?int { return $this->id; }

    public function getAttribution(): ?Attribution { return $this->attribution; }

    public function setAttribution(?Attribution $attribution): static
    {
        $this->attribution = $attribution;
        return $this;
    }

    public function getSalle(): ?Salle { return $this->salle; }

    public function setSalle(?Salle $salle): static
    {
        $this->salle = $salle;
        return $this;
    }

    public function getCreneau(): ?Creneau { return $this->creneau; }

    public function setCreneau(?Creneau $creneau): static
    {
        $this->creneau = $creneau;
        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s | %s | %s',
            $this->attribution ?? '?',
            $this->salle?->getNom() ?? '?',
            $this->creneau?->getLabel() ?? '?',
        );
    }
}
