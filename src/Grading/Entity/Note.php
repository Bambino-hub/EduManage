<?php

declare(strict_types=1);

namespace App\Grading\Entity;

use App\Grading\Repository\NoteRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Student\Entity\Eleve;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'note')]
#[ORM\UniqueConstraint(fields: ['evaluation', 'eleve'])]
#[ORM\HasLifecycleCallbacks]
class Note
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Evaluation::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Evaluation $evaluation = null;

    #[ORM\ManyToOne(targetEntity: Eleve::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Eleve $eleve = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $valeur = null;

    #[ORM\Column]
    private bool $absent = false;

    public function getId(): ?int { return $this->id; }

    public function getEvaluation(): ?Evaluation { return $this->evaluation; }

    public function setEvaluation(?Evaluation $evaluation): static
    {
        $this->evaluation = $evaluation;
        return $this;
    }

    public function getEleve(): ?Eleve { return $this->eleve; }

    public function setEleve(?Eleve $eleve): static
    {
        $this->eleve = $eleve;
        return $this;
    }

    public function getValeur(): ?string { return $this->valeur; }

    public function setValeur(?string $valeur): static
    {
        $this->valeur = $valeur;
        return $this;
    }

    public function isAbsent(): bool { return $this->absent; }

    public function setAbsent(bool $absent): static
    {
        $this->absent = $absent;
        return $this;
    }
}
