<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Repository\NiveauRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NiveauRepository::class)]
#[ORM\Table(name: 'niveau')]
#[ORM\HasLifecycleCallbacks]
class Niveau
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $nom = '';

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $serie = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\ManyToOne(targetEntity: Cycle::class, inversedBy: 'niveaux')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Cycle $cycle = null;

    #[ORM\OneToMany(targetEntity: Classe::class, mappedBy: 'niveau')]
    private Collection $classes;

    public function __construct()
    {
        $this->classes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getSerie(): ?string { return $this->serie; }

    public function setSerie(?string $serie): static
    {
        $this->serie = $serie;
        return $this;
    }

    public function getOrdre(): int { return $this->ordre; }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;
        return $this;
    }

    public function getCycle(): ?Cycle { return $this->cycle; }

    public function setCycle(?Cycle $cycle): static
    {
        $this->cycle = $cycle;
        return $this;
    }

    /** @return Collection<int, Classe> */
    public function getClasses(): Collection { return $this->classes; }

    public function getNomComplet(): string
    {
        return $this->serie ? "{$this->nom} {$this->serie}" : $this->nom;
    }

    public function __toString(): string { return $this->getNomComplet(); }
}
