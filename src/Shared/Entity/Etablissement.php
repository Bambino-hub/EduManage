<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\EtablissementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages globaux de l'établissement (une seule ligne en base — voir
 * EtablissementRepository::getOuCreer) : identité du chef d'établissement, cachet et
 * signature apposés automatiquement sur les documents officiels (bulletins…).
 */
#[ORM\Entity(repositoryClass: EtablissementRepository::class)]
#[ORM\Table(name: 'etablissement')]
#[ORM\HasLifecycleCallbacks]
class Etablissement
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $nomChefEtablissement = '';

    #[ORM\Column(length: 80)]
    private string $titreChefEtablissement = 'Directeur/Directrice';

    /** Nom de fichier (public/uploads/etablissement/) — PNG à fond transparent. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cachet = null;

    /** Nom de fichier (public/uploads/etablissement/) — PNG à fond transparent. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureChefEtablissement = null;

    public function getId(): ?int { return $this->id; }

    public function getNomChefEtablissement(): string { return $this->nomChefEtablissement; }

    public function setNomChefEtablissement(string $nomChefEtablissement): static
    {
        $this->nomChefEtablissement = $nomChefEtablissement;
        return $this;
    }

    public function getTitreChefEtablissement(): string { return $this->titreChefEtablissement; }

    public function setTitreChefEtablissement(string $titreChefEtablissement): static
    {
        $this->titreChefEtablissement = $titreChefEtablissement;
        return $this;
    }

    public function getCachet(): ?string { return $this->cachet; }

    public function setCachet(?string $cachet): static
    {
        $this->cachet = $cachet;
        return $this;
    }

    public function getSignatureChefEtablissement(): ?string { return $this->signatureChefEtablissement; }

    public function setSignatureChefEtablissement(?string $signatureChefEtablissement): static
    {
        $this->signatureChefEtablissement = $signatureChefEtablissement;
        return $this;
    }
}
