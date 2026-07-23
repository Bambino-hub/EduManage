<?php

declare(strict_types=1);

namespace App\Grading\Entity;

use App\Grading\Repository\MoyenneManuelleRepository;
use App\Scheduling\Entity\Attribution;
use App\Shared\Entity\TimestampableTrait;
use App\Student\Entity\Eleve;
use Doctrine\ORM\Mapping as ORM;

/**
 * Saisie manuelle de Moy Interro / Moy Devoir sur la fiche de notes en ligne, prioritaire
 * sur le calcul automatique à partir des notes d'Interrogation/Devoir (voir
 * MoyenneCalculator::calculerPourAttribution) — utile notamment pour l'import d'une fiche
 * papier scannée, où seules ces moyennes (et Compos) intéressent, pas le détail des notes.
 */
#[ORM\Entity(repositoryClass: MoyenneManuelleRepository::class)]
#[ORM\Table(name: 'moyenne_manuelle')]
#[ORM\UniqueConstraint(fields: ['attribution', 'trimestre', 'eleve'])]
#[ORM\HasLifecycleCallbacks]
class MoyenneManuelle
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Attribution::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Attribution $attribution = null;

    #[ORM\ManyToOne(targetEntity: Trimestre::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Trimestre $trimestre = null;

    #[ORM\ManyToOne(targetEntity: Eleve::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Eleve $eleve = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneInterrogation = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneDevoirs = null;

    public function getId(): ?int { return $this->id; }

    public function getAttribution(): ?Attribution { return $this->attribution; }

    public function setAttribution(?Attribution $attribution): static
    {
        $this->attribution = $attribution;
        return $this;
    }

    public function getTrimestre(): ?Trimestre { return $this->trimestre; }

    public function setTrimestre(?Trimestre $trimestre): static
    {
        $this->trimestre = $trimestre;
        return $this;
    }

    public function getEleve(): ?Eleve { return $this->eleve; }

    public function setEleve(?Eleve $eleve): static
    {
        $this->eleve = $eleve;
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
}
