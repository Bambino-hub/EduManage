<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Entity;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Repository\ExamenBlancRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une session d'examen blanc (ex. "Examen Blanc N°1"), organisée par niveau entier — les
 * classes concernées se déduisent des niveaux choisis (toutes les classes actives de l'année),
 * exactement comme Exam\Entity\Examen. Contrairement au bulletin, il n'y a pas de champ
 * "verrouillé" explicite : le verrou vient de ExamenBlancReleve (snapshot), généré à part.
 */
#[ORM\Entity(repositoryClass: ExamenBlancRepository::class)]
#[ORM\Table(name: 'examen_blanc')]
#[ORM\HasLifecycleCallbacks]
class ExamenBlanc
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?AnneeScolaire $anneeScolaire = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\ManyToMany(targetEntity: Niveau::class)]
    #[ORM\JoinTable(name: 'examen_blanc_niveau')]
    private Collection $niveaux;

    /**
     * Matières réellement évaluées dans cet examen — certaines matières "core" (ni
     * facultatives ni EPS) ne sont pas testées lors d'un examen blanc (ex. Dessin, Musique,
     * Bibliothèque). Choisi explicitement par niveau∩matières enseignées
     * (Niveau::matiereNiveaux) au moment du calcul — voir ExamenBlancMatieresResolver.
     * Pré-rempli à la création avec toutes les matières évaluables par défaut
     * (MatiereRepository::findEvaluablesParDefaut), l'admin décoche ce qui ne s'applique pas.
     */
    #[ORM\ManyToMany(targetEntity: Matiere::class)]
    #[ORM\JoinTable(name: 'examen_blanc_matiere')]
    private Collection $matieres;

    /** Cascade remove — supprimer une session emporte ses notes et relevés, comme Exam\Entity\Examen::surveillances. */
    #[ORM\OneToMany(targetEntity: ExamenBlancNote::class, mappedBy: 'examenBlanc', cascade: ['remove'], orphanRemoval: true)]
    private Collection $notes;

    #[ORM\OneToMany(targetEntity: ExamenBlancReleve::class, mappedBy: 'examenBlanc', cascade: ['remove'], orphanRemoval: true)]
    private Collection $releves;

    public function __construct()
    {
        $this->niveaux  = new ArrayCollection();
        $this->matieres = new ArrayCollection();
        $this->notes    = new ArrayCollection();
        $this->releves  = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getLibelle(): string { return $this->libelle; }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
        return $this;
    }

    public function getAnneeScolaire(): ?AnneeScolaire { return $this->anneeScolaire; }

    public function setAnneeScolaire(?AnneeScolaire $anneeScolaire): static
    {
        $this->anneeScolaire = $anneeScolaire;
        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable { return $this->dateDebut; }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable { return $this->dateFin; }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;
        return $this;
    }

    /** @return Collection<int, Niveau> */
    public function getNiveaux(): Collection { return $this->niveaux; }

    public function setNiveaux(Collection $niveaux): static
    {
        $this->niveaux = $niveaux;
        return $this;
    }

    /** @return Collection<int, Matiere> */
    public function getMatieres(): Collection { return $this->matieres; }

    public function setMatieres(Collection $matieres): static
    {
        $this->matieres = $matieres;
        return $this;
    }

    public function __toString(): string
    {
        return $this->libelle;
    }
}
