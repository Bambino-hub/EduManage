<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Enum\TypeSalle;
use App\Academic\Repository\SalleRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SalleRepository::class)]
#[ORM\Table(name: 'salle')]
#[ORM\HasLifecycleCallbacks]
class Salle
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $nom = '';

    #[ORM\Column]
    private int $capacite = 40;

    #[ORM\Column(length: 20, enumType: TypeSalle::class)]
    private TypeSalle $type = TypeSalle::STANDARD;

    #[ORM\OneToMany(targetEntity: \App\Scheduling\Entity\Seance::class, mappedBy: 'salle')]
    private Collection $seances;

    public function __construct()
    {
        $this->seances = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getCapacite(): int { return $this->capacite; }

    public function setCapacite(int $capacite): static
    {
        $this->capacite = $capacite;
        return $this;
    }

    public function getType(): TypeSalle { return $this->type; }

    public function setType(TypeSalle $type): static
    {
        $this->type = $type;
        return $this;
    }

    /** @return Collection<int, \App\Scheduling\Entity\Seance> */
    public function getSeances(): Collection { return $this->seances; }

    public function __toString(): string { return $this->nom; }
}
