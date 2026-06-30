<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Repository\ClasseRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClasseRepository::class)]
#[ORM\Table(name: 'classe')]
#[ORM\UniqueConstraint(fields: ['nom', 'anneeScolaire'])]
#[ORM\HasLifecycleCallbacks]
class Classe
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $nom = '';

    #[ORM\Column]
    private int $effectifMax = 40;

    #[ORM\ManyToOne(targetEntity: Niveau::class, inversedBy: 'classes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Niveau $niveau = null;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class, inversedBy: 'classes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?AnneeScolaire $anneeScolaire = null;

    #[ORM\OneToMany(targetEntity: \App\Scheduling\Entity\Attribution::class, mappedBy: 'classe')]
    private Collection $attributions;

    public function __construct()
    {
        $this->attributions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getEffectifMax(): int { return $this->effectifMax; }

    public function setEffectifMax(int $effectifMax): static
    {
        $this->effectifMax = $effectifMax;
        return $this;
    }

    public function getNiveau(): ?Niveau { return $this->niveau; }

    public function setNiveau(?Niveau $niveau): static
    {
        $this->niveau = $niveau;
        return $this;
    }

    public function getAnneeScolaire(): ?AnneeScolaire { return $this->anneeScolaire; }

    public function setAnneeScolaire(?AnneeScolaire $anneeScolaire): static
    {
        $this->anneeScolaire = $anneeScolaire;
        return $this;
    }

    /** @return Collection<int, \App\Scheduling\Entity\Attribution> */
    public function getAttributions(): Collection { return $this->attributions; }

    public function __toString(): string { return $this->nom; }
}
