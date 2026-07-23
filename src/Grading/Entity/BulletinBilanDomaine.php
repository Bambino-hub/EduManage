<?php

declare(strict_types=1);

namespace App\Grading\Entity;

use App\Academic\Enum\DomaineMatiere;
use App\Grading\Repository\BulletinBilanDomaineRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/** Ligne "bilan de domaine" (Scientifique/Littéraire/Autre) d'un Bulletin — valeur copiée à la génération. */
#[ORM\Entity(repositoryClass: BulletinBilanDomaineRepository::class)]
#[ORM\Table(name: 'bulletin_bilan_domaine')]
#[ORM\HasLifecycleCallbacks]
class BulletinBilanDomaine
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Bulletin::class, inversedBy: 'bilansDomaine')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Bulletin $bulletin = null;

    #[ORM\Column(length: 20, enumType: DomaineMatiere::class)]
    private DomaineMatiere $domaine = DomaineMatiere::AUTRE;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenne = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $appreciation = null;

    public function getId(): ?int { return $this->id; }

    public function getBulletin(): ?Bulletin { return $this->bulletin; }

    public function setBulletin(?Bulletin $bulletin): static
    {
        $this->bulletin = $bulletin;
        return $this;
    }

    public function getDomaine(): DomaineMatiere { return $this->domaine; }

    public function setDomaine(DomaineMatiere $domaine): static
    {
        $this->domaine = $domaine;
        return $this;
    }

    public function getMoyenne(): ?string { return $this->moyenne; }

    public function setMoyenne(?string $moyenne): static
    {
        $this->moyenne = $moyenne;
        return $this;
    }

    public function getAppreciation(): ?string { return $this->appreciation; }

    public function setAppreciation(?string $appreciation): static
    {
        $this->appreciation = $appreciation;
        return $this;
    }
}
