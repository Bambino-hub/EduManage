<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Repository\MatiereRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MatiereRepository::class)]
#[ORM\Table(name: 'matiere')]
#[ORM\HasLifecycleCallbacks]
class Matiere
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $nom = '';

    #[ORM\Column(length: 10, unique: true)]
    private string $code = '';

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2)]
    private string $coefficient = '1.00';

    #[ORM\Column(length: 7)]
    private string $couleur = '#4a90d9';

    #[ORM\OneToMany(targetEntity: \App\Scheduling\Entity\Attribution::class, mappedBy: 'matiere')]
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

    public function getCode(): string { return $this->code; }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);
        return $this;
    }

    public function getCoefficient(): string { return $this->coefficient; }

    public function setCoefficient(string $coefficient): static
    {
        $this->coefficient = $coefficient;
        return $this;
    }

    public function getCouleur(): string { return $this->couleur; }

    public function setCouleur(string $couleur): static
    {
        $this->couleur = $couleur;
        return $this;
    }

    /** @return Collection<int, \App\Scheduling\Entity\Attribution> */
    public function getAttributions(): Collection { return $this->attributions; }

    public function __toString(): string { return "{$this->nom} ({$this->code})"; }
}
