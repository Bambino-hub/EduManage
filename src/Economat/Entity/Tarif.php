<?php

declare(strict_types=1);

namespace App\Economat\Entity;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Niveau;
use App\Economat\Repository\TarifRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TarifRepository::class)]
#[ORM\Table(name: 'tarif')]
#[ORM\UniqueConstraint(fields: ['niveau', 'anneeScolaire'])]
#[ORM\HasLifecycleCallbacks]
class Tarif
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Niveau::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Niveau $niveau = null;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?AnneeScolaire $anneeScolaire = null;

    /** Montant annuel dû, en FCFA (pas de décimales). */
    #[ORM\Column]
    private int $montantAnnuel = 0;

    public function getId(): ?int { return $this->id; }

    public function getNiveau(): ?Niveau { return $this->niveau; }

    public function setNiveau(?Niveau $niveau): static
    {
        $this->niveau = $niveau;
        return $this;
    }

    public function getAnneeScolaire(): ?AnneeScolaire { return $this->anneeScolaire; }

    public function setAnneeScolaire(?AnneeScolaire $anneeScolaire): static
    {
        $this->anneeScolaire = $anneeScolaire;
        return $this;
    }

    public function getMontantAnnuel(): int { return $this->montantAnnuel; }

    public function setMontantAnnuel(int $montantAnnuel): static
    {
        $this->montantAnnuel = $montantAnnuel;
        return $this;
    }
}
