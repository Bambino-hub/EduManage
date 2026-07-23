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

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, options: ['default' => '1.00'])]
    private string $poidsInterrogation = '1.00';

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, options: ['default' => '1.00'])]
    private string $poidsDevoirs = '1.00';

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, options: ['default' => '1.00'])]
    private string $poidsComposition = '1.00';

    #[ORM\OneToMany(targetEntity: Classe::class, mappedBy: 'anneeScolaire')]
    private Collection $classes;

    #[ORM\OneToMany(targetEntity: \App\Grading\Entity\Trimestre::class, mappedBy: 'anneeScolaire')]
    #[ORM\OrderBy(['numero' => 'ASC'])]
    private Collection $trimestres;

    public function __construct()
    {
        $this->classes    = new ArrayCollection();
        $this->trimestres = new ArrayCollection();
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

    public function getPoidsInterrogation(): string { return $this->poidsInterrogation; }

    public function setPoidsInterrogation(string $poidsInterrogation): static
    {
        $this->poidsInterrogation = $poidsInterrogation;
        return $this;
    }

    public function getPoidsDevoirs(): string { return $this->poidsDevoirs; }

    public function setPoidsDevoirs(string $poidsDevoirs): static
    {
        $this->poidsDevoirs = $poidsDevoirs;
        return $this;
    }

    public function getPoidsComposition(): string { return $this->poidsComposition; }

    public function setPoidsComposition(string $poidsComposition): static
    {
        $this->poidsComposition = $poidsComposition;
        return $this;
    }

    /** @return Collection<int, Classe> */
    public function getClasses(): Collection { return $this->classes; }

    /** @return Collection<int, \App\Grading\Entity\Trimestre> */
    public function getTrimestres(): Collection { return $this->trimestres; }

    public function __toString(): string { return $this->libelle; }
}
