<?php

declare(strict_types=1);

namespace App\Website\Entity;

use App\Academic\Enum\TypeCycle;
use App\Shared\Entity\TimestampableTrait;
use App\Website\Repository\ActualiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Annonce publiée sur le site public (page "Actualités") : texte simple + image optionnelle.
 * `cycleConcerne` à null signifie "toute l'école" (pas de filtrage par cycle).
 */
#[ORM\Entity(repositoryClass: ActualiteRepository::class)]
#[ORM\Table(name: 'actualite')]
#[ORM\HasLifecycleCallbacks]
class Actualite
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $titre = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $contenu = '';

    /** Nom du fichier image (dans public/uploads/actualites/), pas le chemin complet. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(length: 20, nullable: true, enumType: TypeCycle::class)]
    private ?TypeCycle $cycleConcerne = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $datePublication = null;

    #[ORM\Column]
    private bool $publie = false;

    public function __construct()
    {
        $this->datePublication = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;
        return $this;
    }

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;
        return $this;
    }

    public function getCycleConcerne(): ?TypeCycle
    {
        return $this->cycleConcerne;
    }

    public function setCycleConcerne(?TypeCycle $cycleConcerne): static
    {
        $this->cycleConcerne = $cycleConcerne;
        return $this;
    }

    public function getDatePublication(): ?\DateTimeImmutable
    {
        return $this->datePublication;
    }

    public function setDatePublication(?\DateTimeImmutable $datePublication): static
    {
        $this->datePublication = $datePublication;
        return $this;
    }

    public function isPublie(): bool
    {
        return $this->publie;
    }

    public function setPublie(bool $publie): static
    {
        $this->publie = $publie;
        return $this;
    }

    public function __toString(): string
    {
        return $this->titre;
    }
}
