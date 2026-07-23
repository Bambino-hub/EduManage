<?php

declare(strict_types=1);

namespace App\Scheduling\Entity;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Scheduling\Repository\AttributionRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Staff\Entity\Enseignant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lie un enseignant à une matière et une classe pour une année scolaire donnée.
 * Porte aussi le volume horaire hebdomadaire attendu (ex: 3h de Maths/semaine).
 *
 * Contrainte métier : une classe ne peut avoir qu'un seul enseignant pour une
 * matière donnée (pas de partage d'une même matière entre deux enseignants dans
 * la même classe). D'où l'unicité sur (matière, classe) et non (enseignant,
 * matière, classe) — cette dernière aurait laissé passer deux enseignants
 * différents sur le même couple matière/classe.
 */
#[ORM\Entity(repositoryClass: AttributionRepository::class)]
#[ORM\Table(name: 'attribution')]
#[ORM\UniqueConstraint(fields: ['matiere', 'classe'])]
#[ORM\HasLifecycleCallbacks]
class Attribution
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Enseignant::class, inversedBy: 'attributions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Enseignant $enseignant = null;

    #[ORM\ManyToOne(targetEntity: Matiere::class, inversedBy: 'attributions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Matiere $matiere = null;

    #[ORM\ManyToOne(targetEntity: Classe::class, inversedBy: 'attributions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Classe $classe = null;

    #[ORM\Column]
    private int $volumeHoraireHebdo = 1;

    #[ORM\OneToMany(targetEntity: Seance::class, mappedBy: 'attribution', cascade: ['remove'])]
    private Collection $seances;

    #[ORM\OneToMany(targetEntity: \App\Grading\Entity\Evaluation::class, mappedBy: 'attribution', cascade: ['remove'])]
    private Collection $evaluations;

    public function __construct()
    {
        $this->seances     = new ArrayCollection();
        $this->evaluations = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getEnseignant(): ?Enseignant { return $this->enseignant; }

    public function setEnseignant(?Enseignant $enseignant): static
    {
        $this->enseignant = $enseignant;
        return $this;
    }

    public function getMatiere(): ?Matiere { return $this->matiere; }

    public function setMatiere(?Matiere $matiere): static
    {
        $this->matiere = $matiere;
        return $this;
    }

    public function getClasse(): ?Classe { return $this->classe; }

    public function setClasse(?Classe $classe): static
    {
        $this->classe = $classe;
        return $this;
    }

    public function getVolumeHoraireHebdo(): int { return $this->volumeHoraireHebdo; }

    public function setVolumeHoraireHebdo(int $volumeHoraireHebdo): static
    {
        $this->volumeHoraireHebdo = $volumeHoraireHebdo;
        return $this;
    }

    /** @return Collection<int, Seance> */
    public function getSeances(): Collection { return $this->seances; }

    /** @return Collection<int, \App\Grading\Entity\Evaluation> */
    public function getEvaluations(): Collection { return $this->evaluations; }

    public function __toString(): string
    {
        return sprintf(
            '%s — %s — %s',
            $this->enseignant?->getNomComplet() ?? '?',
            $this->matiere?->getNom() ?? '?',
            $this->classe?->getNom() ?? '?',
        );
    }
}
