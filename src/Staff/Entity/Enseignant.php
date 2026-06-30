<?php

declare(strict_types=1);

namespace App\Staff\Entity;

use App\Shared\Entity\TimestampableTrait;
use App\Staff\Enum\TypePersonnel;
use App\Staff\Repository\EnseignantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EnseignantRepository::class)]
#[ORM\Table(name: 'enseignant')]
#[ORM\HasLifecycleCallbacks]
class Enseignant
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $nom = '';

    #[ORM\Column(length: 80)]
    private string $prenom = '';

    #[ORM\Column(length: 120, unique: true)]
    private string $email = '';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 20, enumType: TypePersonnel::class)]
    private TypePersonnel $type = TypePersonnel::INTERNE;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $specialite = null;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\OneToMany(targetEntity: \App\Scheduling\Entity\Attribution::class, mappedBy: 'enseignant')]
    private Collection $attributions;

    public function __construct()
    {
        $this->attributions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = strtoupper($nom);
        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = ucwords(strtolower($prenom));
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getType(): TypePersonnel
    {
        return $this->type;
    }

    public function setType(TypePersonnel $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getSpecialite(): ?string
    {
        return $this->specialite;
    }

    public function setSpecialite(?string $specialite): static
    {
        $this->specialite = $specialite;
        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;
        return $this;
    }

    /** @return Collection<int, \App\Scheduling\Entity\Attribution> */
    public function getAttributions(): Collection
    {
        return $this->attributions;
    }

    public function getNomComplet(): string
    {
        return "{$this->prenom} {$this->nom}";
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
