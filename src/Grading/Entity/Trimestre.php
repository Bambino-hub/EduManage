<?php

declare(strict_types=1);

namespace App\Grading\Entity;

use App\Academic\Entity\AnneeScolaire;
use App\Grading\Repository\TrimestreRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrimestreRepository::class)]
#[ORM\Table(name: 'trimestre')]
#[ORM\UniqueConstraint(fields: ['anneeScolaire', 'numero'])]
#[ORM\HasLifecycleCallbacks]
class Trimestre
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class, inversedBy: 'trimestres')]
    #[ORM\JoinColumn(nullable: false)]
    private ?AnneeScolaire $anneeScolaire = null;

    #[ORM\Column]
    private int $numero = 1;

    #[ORM\Column(length: 30)]
    private string $libelle = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column]
    private bool $active = false;

    /**
     * Structure de la fiche de notes en ligne, commune à toutes les matières : nombre de
     * colonnes Interrogation/Devoir générées automatiquement pour chaque attribution.
     * Seul l'admin modifie ces valeurs (ici) — la colonne Composition, elle, est toujours
     * unique (une seule note de composition par trimestre, jamais configurable).
     */
    #[ORM\Column]
    private int $nbInterrogations = 3;

    #[ORM\Column]
    private int $nbDevoirs = 2;

    public function getId(): ?int { return $this->id; }

    public function getAnneeScolaire(): ?AnneeScolaire { return $this->anneeScolaire; }

    public function setAnneeScolaire(?AnneeScolaire $anneeScolaire): static
    {
        $this->anneeScolaire = $anneeScolaire;
        return $this;
    }

    public function getNumero(): int { return $this->numero; }

    public function setNumero(int $numero): static
    {
        $this->numero = $numero;
        return $this;
    }

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

    public function getNbInterrogations(): int { return $this->nbInterrogations; }

    public function setNbInterrogations(int $nbInterrogations): static
    {
        $this->nbInterrogations = $nbInterrogations;
        return $this;
    }

    public function getNbDevoirs(): int { return $this->nbDevoirs; }

    public function setNbDevoirs(int $nbDevoirs): static
    {
        $this->nbDevoirs = $nbDevoirs;
        return $this;
    }

    public function __toString(): string { return $this->libelle; }
}
