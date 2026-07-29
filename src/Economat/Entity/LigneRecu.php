<?php

declare(strict_types=1);

namespace App\Economat\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ligne_recu')]
#[ORM\HasLifecycleCallbacks]
class LigneRecu
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Recu::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Recu $recu = null;

    /** Nature de la recette, saisie librement par le caissier : "Tranche 1", "Arriéré", "Frais d'examen"... */
    #[ORM\Column(length: 150)]
    private string $libelle = '';

    /** Montant versé pour cette ligne, en FCFA. */
    #[ORM\Column]
    private int $montant = 0;

    public function getId(): ?int { return $this->id; }

    public function getRecu(): ?Recu { return $this->recu; }

    public function setRecu(?Recu $recu): static
    {
        $this->recu = $recu;
        return $this;
    }

    public function getLibelle(): string { return $this->libelle; }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
        return $this;
    }

    public function getMontant(): int { return $this->montant; }

    public function setMontant(int $montant): static
    {
        $this->montant = $montant;
        return $this;
    }
}
