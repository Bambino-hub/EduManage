<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Repository\MatiereNiveauRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MatiereNiveauRepository::class)]
#[ORM\Table(name: 'matiere_niveau')]
#[ORM\UniqueConstraint(name: 'UNIQ_matiere_niveau', columns: ['matiere_id', 'niveau_id'])]
#[ORM\HasLifecycleCallbacks]
class MatiereNiveau
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Matiere::class, inversedBy: 'matiereNiveaux')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Matiere $matiere = null;

    #[ORM\ManyToOne(targetEntity: Niveau::class, inversedBy: 'matiereNiveaux')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Niveau $niveau = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2)]
    private string $coefficient = '1.00';

    public function getId(): ?int { return $this->id; }

    public function getMatiere(): ?Matiere { return $this->matiere; }

    public function setMatiere(?Matiere $matiere): static
    {
        $this->matiere = $matiere;
        return $this;
    }

    public function getNiveau(): ?Niveau { return $this->niveau; }

    public function setNiveau(?Niveau $niveau): static
    {
        $this->niveau = $niveau;
        return $this;
    }

    public function getCoefficient(): string { return $this->coefficient; }

    public function setCoefficient(string $coefficient): static
    {
        $this->coefficient = $coefficient;
        return $this;
    }
}
