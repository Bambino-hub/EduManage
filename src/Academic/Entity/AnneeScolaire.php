<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnneeScolaireRepository::class)]
#[ORM\Table(name: 'annee_scolaire')]
#[ORM\HasLifecycleCallbacks]
class AnneeScolaire
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $libelle = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column]
    private bool $active = false;

    #[ORM\OneToMany(targetEntity: Classe::class, mappedBy: 'anneeScolaire')]
    private Collection $classes;

    public function __construct()
    {
        $this->classes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getLibelle(): string { return $this->libelle; }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable { return $this->dateDebut; }

    public function setDateDebut(\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable { return $this->dateFin; }

    public function setDateFin(\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;
        return $this;
    }

    public function isActive(): bool { return $this->active; }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    /** @return Collection<int, Classe> */
    public function getClasses(): Collection { return $this->classes; }

    public function __toString(): string { return $this->libelle; }
}
