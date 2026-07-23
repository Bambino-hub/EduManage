<?php

declare(strict_types=1);

namespace App\Grading\Entity;

use App\Grading\Enum\TypeEvaluation;
use App\Grading\Repository\EvaluationRepository;
use App\Scheduling\Entity\Attribution;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\Table(name: 'evaluation')]
#[ORM\HasLifecycleCallbacks]
class Evaluation
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Attribution::class, inversedBy: 'evaluations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Attribution $attribution = null;

    #[ORM\ManyToOne(targetEntity: Trimestre::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Trimestre $trimestre = null;

    #[ORM\Column(length: 20, enumType: TypeEvaluation::class)]
    private TypeEvaluation $type = TypeEvaluation::DEVOIR;

    #[ORM\Column(length: 100)]
    private string $titre = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2)]
    private string $coefficient = '1.00';

    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'evaluation', cascade: ['persist', 'remove'])]
    private Collection $notes;

    public function __construct()
    {
        $this->notes = new ArrayCollection();
    }

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

    public function getType(): TypeEvaluation { return $this->type; }

    public function setType(TypeEvaluation $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTitre(): string { return $this->titre; }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;
        return $this;
    }

    public function getDate(): ?\DateTimeImmutable { return $this->date; }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getCoefficient(): string { return $this->coefficient; }

    public function setCoefficient(string $coefficient): static
    {
        $this->coefficient = $coefficient;
        return $this;
    }

    /** @return Collection<int, Note> */
    public function getNotes(): Collection { return $this->notes; }

    public function __toString(): string
    {
        return "{$this->titre} ({$this->type->label()})";
    }
}
