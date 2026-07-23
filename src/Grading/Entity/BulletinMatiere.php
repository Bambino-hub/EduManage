<?php

declare(strict_types=1);

namespace App\Grading\Entity;

use App\Academic\Entity\Matiere;
use App\Grading\Repository\BulletinMatiereRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/** Ligne "matière" d'un Bulletin — valeurs entièrement copiées au moment de la génération. */
#[ORM\Entity(repositoryClass: BulletinMatiereRepository::class)]
#[ORM\Table(name: 'bulletin_matiere')]
#[ORM\HasLifecycleCallbacks]
class BulletinMatiere
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Bulletin::class, inversedBy: 'matieres')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Bulletin $bulletin = null;

    #[ORM\ManyToOne(targetEntity: Matiere::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Matiere $matiere = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2)]
    private string $coefficient = '1.00';

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneInterrogation = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneDevoirs = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneComposition = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenne = null;

    #[ORM\Column(length: 150)]
    private string $enseignantNom = '';

    #[ORM\Column(nullable: true)]
    private ?int $rang = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $appreciation = null;

    public function getId(): ?int { return $this->id; }

    public function getBulletin(): ?Bulletin { return $this->bulletin; }

    public function setBulletin(?Bulletin $bulletin): static
    {
        $this->bulletin = $bulletin;
        return $this;
    }

    public function getMatiere(): ?Matiere { return $this->matiere; }

    public function setMatiere(?Matiere $matiere): static
    {
        $this->matiere = $matiere;
        return $this;
    }

    public function getCoefficient(): string { return $this->coefficient; }

    public function setCoefficient(string $coefficient): static
    {
        $this->coefficient = $coefficient;
        return $this;
    }

    public function getMoyenneInterrogation(): ?string { return $this->moyenneInterrogation; }

    public function setMoyenneInterrogation(?string $moyenneInterrogation): static
    {
        $this->moyenneInterrogation = $moyenneInterrogation;
        return $this;
    }

    public function getMoyenneDevoirs(): ?string { return $this->moyenneDevoirs; }

    public function setMoyenneDevoirs(?string $moyenneDevoirs): static
    {
        $this->moyenneDevoirs = $moyenneDevoirs;
        return $this;
    }

    public function getMoyenneComposition(): ?string { return $this->moyenneComposition; }

    public function setMoyenneComposition(?string $moyenneComposition): static
    {
        $this->moyenneComposition = $moyenneComposition;
        return $this;
    }

    public function getMoyenne(): ?string { return $this->moyenne; }

    public function setMoyenne(?string $moyenne): static
    {
        $this->moyenne = $moyenne;
        return $this;
    }

    public function getEnseignantNom(): string { return $this->enseignantNom; }

    public function setEnseignantNom(string $enseignantNom): static
    {
        $this->enseignantNom = $enseignantNom;
        return $this;
    }

    public function getRang(): ?int { return $this->rang; }

    public function setRang(?int $rang): static
    {
        $this->rang = $rang;
        return $this;
    }

    public function getAppreciation(): ?string { return $this->appreciation; }

    public function setAppreciation(?string $appreciation): static
    {
        $this->appreciation = $appreciation;
        return $this;
    }
}
