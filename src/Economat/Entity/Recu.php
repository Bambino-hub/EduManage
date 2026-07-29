<?php

declare(strict_types=1);

namespace App\Economat\Entity;

use App\Academic\Entity\AnneeScolaire;
use App\Economat\Repository\RecuRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Student\Entity\Eleve;
use App\Student\Entity\Inscription;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecuRepository::class)]
#[ORM\Table(name: 'recu')]
#[ORM\HasLifecycleCallbacks]
class Recu
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Numéro métier séquentiel (propre à l'appli, commence à 1) — distinct de $id. */
    #[ORM\Column(unique: true)]
    private int $numero = 0;

    #[ORM\ManyToOne(targetEntity: Eleve::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Eleve $eleve = null;

    /** Inscription courante au moment de l'émission — sert à imprimer le niveau/la classe
     * sur le reçu, mais ne détermine PAS l'année scolaire concernée (voir anneeScolaire). */
    #[ORM\ManyToOne(targetEntity: Inscription::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Inscription $inscription = null;

    /** Année scolaire à laquelle ce versement se rapporte. Champ indépendant de
     * inscription.classe.anneeScolaire : la classe peut être nulle (élève pas encore
     * affecté), et un règlement d'arriéré peut concerner une année antérieure à
     * l'inscription en cours de l'élève. */
    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?AnneeScolaire $anneeScolaire = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateEmission = null;

    /** Qui a physiquement payé (tuteur, élève...), facultatif. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nomPayeur = null;

    #[ORM\OneToMany(targetEntity: LigneRecu::class, mappedBy: 'recu', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getEleve(): ?Eleve { return $this->eleve; }

    public function setEleve(?Eleve $eleve): static
    {
        $this->eleve = $eleve;
        return $this;
    }

    public function getInscription(): ?Inscription { return $this->inscription; }

    public function setInscription(?Inscription $inscription): static
    {
        $this->inscription = $inscription;
        return $this;
    }

    public function getAnneeScolaire(): ?AnneeScolaire { return $this->anneeScolaire; }

    public function setAnneeScolaire(?AnneeScolaire $anneeScolaire): static
    {
        $this->anneeScolaire = $anneeScolaire;
        return $this;
    }

    public function getDateEmission(): ?\DateTimeImmutable { return $this->dateEmission; }

    public function setDateEmission(\DateTimeImmutable $dateEmission): static
    {
        $this->dateEmission = $dateEmission;
        return $this;
    }

    public function getNomPayeur(): ?string { return $this->nomPayeur; }

    public function setNomPayeur(?string $nomPayeur): static
    {
        $this->nomPayeur = $nomPayeur;
        return $this;
    }

    /** @return Collection<int, LigneRecu> */
    public function getLignes(): Collection { return $this->lignes; }

    public function addLigne(LigneRecu $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setRecu($this);
        }
        return $this;
    }

    public function removeLigne(LigneRecu $ligne): static
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
