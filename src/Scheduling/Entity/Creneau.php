<?php

declare(strict_types=1);

namespace App\Scheduling\Entity;

use App\Scheduling\Enum\JourSemaine;
use App\Scheduling\Repository\CreneauRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreneauRepository::class)]
#[ORM\Table(name: 'creneau')]
#[ORM\UniqueConstraint(fields: ['jourSemaine', 'heureDebut', 'heureFin'])]
#[ORM\HasLifecycleCallbacks]
class Creneau
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 15, enumType: JourSemaine::class)]
    private JourSemaine $jourSemaine = JourSemaine::LUNDI;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private ?\DateTimeImmutable $heureDebut = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private ?\DateTimeImmutable $heureFin = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $libelleReserve = null;

    #[ORM\OneToMany(targetEntity: Seance::class, mappedBy: 'creneau')]
    private Collection $seances;

    public function __construct()
    {
        $this->seances = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getJourSemaine(): JourSemaine { return $this->jourSemaine; }

    public function setJourSemaine(JourSemaine $jourSemaine): static
    {
        $this->jourSemaine = $jourSemaine;
        return $this;
    }

    public function getHeureDebut(): ?\DateTimeImmutable { return $this->heureDebut; }

    public function setHeureDebut(\DateTimeImmutable $heureDebut): static
    {
        $this->heureDebut = $heureDebut;
        return $this;
    }

    public function getHeureFin(): ?\DateTimeImmutable { return $this->heureFin; }

    public function setHeureFin(\DateTimeImmutable $heureFin): static
    {
        $this->heureFin = $heureFin;
        return $this;
    }

    public function getOrdre(): int { return $this->ordre; }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;
        return $this;
    }

    public function getLibelleReserve(): ?string { return $this->libelleReserve; }

    public function setLibelleReserve(?string $libelleReserve): static
    {
        $this->libelleReserve = $libelleReserve;
        return $this;
    }

    public function isReserve(): bool { return $this->libelleReserve !== null; }

    /** @return Collection<int, Seance> */
    public function getSeances(): Collection { return $this->seances; }

    public function getLabel(): string
    {
        $debut = $this->heureDebut?->format('H:i') ?? '';
        $fin   = $this->heureFin?->format('H:i') ?? '';
        $base  = "{$this->jourSemaine->label()} {$debut}–{$fin}";
        return $this->libelleReserve ? "{$base} ({$this->libelleReserve})" : $base;
    }

    public function getDureeMinutes(): int
    {
        if (!$this->heureDebut || !$this->heureFin) {
            return 0;
        }
        return (int) (($this->heureFin->getTimestamp() - $this->heureDebut->getTimestamp()) / 60);
    }

    public function __toString(): string { return $this->getLabel(); }
}
