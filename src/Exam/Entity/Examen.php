<?php

declare(strict_types=1);

namespace App\Exam\Entity;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\Exam\Repository\ExamenRepository;
use App\Scheduling\Enum\JourSemaine;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une matière évaluée à une date/heure précise pour un ensemble de niveaux (ex. Anglais pour
 * 6ème+5ème le même jour à la même heure). Les classes concernées se déduisent des niveaux
 * (toutes les classes actives de l'année de ces niveaux) — pas de salle : chaque classe passe
 * l'examen dans la sienne, "nombreSurveillantsParClasse" remplace la notion de salle d'examen.
 */
#[ORM\Entity(repositoryClass: ExamenRepository::class)]
#[ORM\Table(name: 'examen')]
#[ORM\HasLifecycleCallbacks]
class Examen
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Matiere::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Matiere $matiere = null;

    #[ORM\ManyToMany(targetEntity: Niveau::class)]
    #[ORM\JoinTable(name: 'examen_niveau')]
    private Collection $niveaux;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private ?\DateTimeImmutable $heureDebut = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private ?\DateTimeImmutable $heureFin = null;

    #[ORM\Column]
    private int $nombreSurveillantsParClasse = 1;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?AnneeScolaire $anneeScolaire = null;

    /** Visible ou non sur le calendrier public du site vitrine — décision de l'administrateur. */
    #[ORM\Column]
    private bool $publie = false;

    #[ORM\OneToMany(targetEntity: Surveillance::class, mappedBy: 'examen', cascade: ['remove'], orphanRemoval: true)]
    private Collection $surveillances;

    public function __construct()
    {
        $this->niveaux       = new ArrayCollection();
        $this->surveillances = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getMatiere(): ?Matiere { return $this->matiere; }

    public function setMatiere(?Matiere $matiere): static
    {
        $this->matiere = $matiere;
        return $this;
    }

    /** @return Collection<int, Niveau> */
    public function getNiveaux(): Collection { return $this->niveaux; }

    public function setNiveaux(Collection $niveaux): static
    {
        $this->niveaux = $niveaux;
        return $this;
    }

    public function getDate(): ?\DateTimeImmutable { return $this->date; }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;
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

    public function getNombreSurveillantsParClasse(): int { return $this->nombreSurveillantsParClasse; }

    public function setNombreSurveillantsParClasse(int $nombreSurveillantsParClasse): static
    {
        $this->nombreSurveillantsParClasse = $nombreSurveillantsParClasse;
        return $this;
    }

    public function getAnneeScolaire(): ?AnneeScolaire { return $this->anneeScolaire; }

    public function setAnneeScolaire(?AnneeScolaire $anneeScolaire): static
    {
        $this->anneeScolaire = $anneeScolaire;
        return $this;
    }

    /** @return Collection<int, Surveillance> */
    public function getSurveillances(): Collection { return $this->surveillances; }

    public function isPublie(): bool { return $this->publie; }

    public function setPublie(bool $publie): static
    {
        $this->publie = $publie;
        return $this;
    }

    /**
     * Deux examens se chevauchent s'ils tombent le même jour avec des horaires qui se
     * recoupent — un enseignant ne peut alors surveiller les deux, même dans des classes
     * différentes (utilisé par ExamenSurveillanceGenerator, seule source de vérité).
     */
    public function chevauche(self $autre): bool
    {
        if ($this->date === null || $autre->date === null || $this->heureDebut === null
            || $this->heureFin === null || $autre->heureDebut === null || $autre->heureFin === null
        ) {
            return false;
        }

        if ($this->date->format('Y-m-d') !== $autre->date->format('Y-m-d')) {
            return false;
        }

        return $this->heureDebut < $autre->heureFin && $this->heureFin > $autre->heureDebut;
    }

    /**
     * Jour de la semaine correspondant à la date de l'examen, pour croiser avec la grille
     * hebdomadaire Creneau (null le dimanche : jamais de cours normal, donc jamais de conflit).
     */
    public function getJourSemaine(): ?JourSemaine
    {
        return $this->date !== null ? JourSemaine::depuisDate($this->date) : null;
    }

    public function getDureeMinutes(): int
    {
        if (!$this->heureDebut || !$this->heureFin) {
            return 0;
        }
        return (int) (($this->heureFin->getTimestamp() - $this->heureDebut->getTimestamp()) / 60);
    }

    public function getLabel(): string
    {
        $niveaux = implode(', ', array_map(static fn(Niveau $n) => $n->getNomComplet(), $this->niveaux->toArray()));
        $date    = $this->date?->format('d/m/Y') ?? '';
        $debut   = $this->heureDebut?->format('H:i') ?? '';
        $fin     = $this->heureFin?->format('H:i') ?? '';

        return sprintf('%s — %s — %s %s–%s', $this->matiere?->getNom() ?? '?', $niveaux, $date, $debut, $fin);
    }

    public function __toString(): string { return $this->getLabel(); }
}
