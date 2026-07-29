<?php

declare(strict_types=1);

namespace App\Salaire\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ligne_paiement_salaire')]
#[ORM\HasLifecycleCallbacks]
class LignePaiementSalaire
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PaiementSalaire::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PaiementSalaire $paiementSalaire = null;

    /** Nature du versement, saisie librement par le caissier : "Salaire juin", "Avance", "Prime"... */
    #[ORM\Column(length: 150)]
    private string $libelle = '';

    /** Montant versé pour cette ligne, en FCFA. */
    #[ORM\Column]
    private int $montant = 0;

    public function getId(): ?int { return $this->id; }

    public function getPaiementSalaire(): ?PaiementSalaire { return $this->paiementSalaire; }

    public function setPaiementSalaire(?PaiementSalaire $paiementSalaire): static
    {
        $this->paiementSalaire = $paiementSalaire;
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
