<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Enum\GroupeOptionnel;
use App\Academic\Enum\TypeSalle;
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

    #[ORM\Column(length: 7)]
    private string $couleur = '#4a90d9';

    #[ORM\Column(length: 20, nullable: true, enumType: GroupeOptionnel::class)]
    private ?GroupeOptionnel $groupeOptionnel = null;

    #[ORM\Column(length: 20, nullable: true, enumType: TypeSalle::class)]
    private ?TypeSalle $salleRequise = null;

    #[ORM\OneToMany(
        targetEntity: MatiereNiveau::class,
        mappedBy: 'matiere',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $matiereNiveaux;

    #[ORM\OneToMany(targetEntity: \App\Scheduling\Entity\Attribution::class, mappedBy: 'matiere')]
    private Collection $attributions;

    public function __construct()
    {
        $this->matiereNiveaux = new ArrayCollection();
        $this->attributions   = new ArrayCollection();
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

    public function getCouleur(): string { return $this->couleur; }

    public function setCouleur(string $couleur): static
    {
        $this->couleur = $couleur;
        return $this;
    }

    public function getGroupeOptionnel(): ?GroupeOptionnel { return $this->groupeOptionnel; }

    public function setGroupeOptionnel(?GroupeOptionnel $groupeOptionnel): static
    {
        $this->groupeOptionnel = $groupeOptionnel;
        return $this;
    }

    public function getSalleRequise(): ?TypeSalle { return $this->salleRequise; }

    public function setSalleRequise(?TypeSalle $salleRequise): static
    {
        $this->salleRequise = $salleRequise;
        return $this;
    }

    /** @return Collection<int, MatiereNiveau> */
    public function getMatiereNiveaux(): Collection { return $this->matiereNiveaux; }

    public function addMatiereNiveau(MatiereNiveau $mn): static
    {
        if (!$this->matiereNiveaux->contains($mn)) {
            $this->matiereNiveaux->add($mn);
            $mn->setMatiere($this);
        }
        return $this;
    }

    public function removeMatiereNiveau(MatiereNiveau $mn): static
    {
        $this->matiereNiveaux->removeElement($mn);
        return $this;
    }

    /** @return Collection<int, \App\Scheduling\Entity\Attribution> */
    public function getAttributions(): Collection { return $this->attributions; }

    public function __toString(): string { return "{$this->nom} ({$this->code})"; }
}
