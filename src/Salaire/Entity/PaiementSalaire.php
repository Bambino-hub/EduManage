<?php

declare(strict_types=1);

namespace App\Salaire\Entity;

use App\Salaire\Repository\PaiementSalaireRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Staff\Entity\Enseignant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaiementSalaireRepository::class)]
#[ORM\Table(name: 'paiement_salaire')]
#[ORM\HasLifecycleCallbacks]
class PaiementSalaire
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Numéro métier séquentiel — séquence propre, indépendante de celle des reçus d'écolage. */
    #[ORM\Column(unique: true)]
    private int $numero = 0;

    #[ORM\ManyToOne(targetEntity: Enseignant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Enseignant $enseignant = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateEmission = null;

    #[ORM\OneToMany(targetEntity: LignePaiementSalaire::class, mappedBy: 'paiementSalaire', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lignes;

    public function __construct()
    {
        $this->lignes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNumero(): int { return $this->numero; }

    public function setNumero(int $numero): static
    {
        $this->numero = $numero;
        return $this;
    }

    public function getEnseignant(): ?Enseignant { return $this->enseignant; }

    public function setEnseignant(?Enseignant $enseignant): static
    {
        $this->enseignant = $enseignant;
        return $this;
    }

    public function getDateEmission(): ?\DateTimeImmutable { return $this->dateEmission; }

    public function setDateEmission(\DateTimeImmutable $dateEmission): static
    {
        $this->dateEmission = $dateEmission;
        return $this;
    }

    /** @return Collection<int, LignePaiementSalaire> */
    public function getLignes(): Collection { return $this->lignes; }

    public function addLigne(LignePaiementSalaire $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setPaiementSalaire($this);
        }
        return $this;
    }

    public function removeLigne(LignePaiementSalaire $ligne): static
    {
        $this->lignes->removeElement($ligne);
        return $this;
    }

    public function getMontantTotal(): int
    {
        $total = 0;
        foreach ($this->lignes as $ligne) {
            $total += $ligne->getMontant();
        }
        return $total;
    }
}
