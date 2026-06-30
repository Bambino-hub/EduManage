<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Enum\TypeCycle;
use App\Academic\Repository\CycleRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CycleRepository::class)]
#[ORM\Table(name: 'cycle')]
#[ORM\HasLifecycleCallbacks]
class Cycle
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $nom = '';

    #[ORM\Column(length: 20, enumType: TypeCycle::class)]
    private TypeCycle $type = TypeCycle::COLLEGE;

    #[ORM\OneToMany(targetEntity: Niveau::class, mappedBy: 'cycle', cascade: ['persist'])]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $niveaux;

    public function __construct()
    {
        $this->niveaux = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getType(): TypeCycle { return $this->type; }

    public function setType(TypeCycle $type): static
    {
        $this->type = $type;
        return $this;
    }

    /** @return Collection<int, Niveau> */
    public function getNiveaux(): Collection { return $this->niveaux; }

    public function __toString(): string { return $this->nom; }
}
