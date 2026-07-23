<?php

declare(strict_types=1);

namespace App\Academic\Entity;

use App\Academic\Repository\ClasseRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Staff\Entity\Enseignant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClasseRepository::class)]
#[ORM\Table(name: 'classe')]
#[ORM\UniqueConstraint(fields: ['nom', 'anneeScolaire'])]
#[ORM\HasLifecycleCallbacks]
class Classe
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $nom = '';

    #[ORM\Column]
    private int $effectifMax = 40;

    /**
     * Certains niveaux n'ont pas de cohorte tous les ans (ex. la série C alterne :
     * une année Tle C sans 1ère C, l'année suivante l'inverse). Plutôt que de
     * supprimer la classe, on la désactive : elle disparaît des emplois du temps,
     * du tableau de vérification des attributions et de la génération auto, sans
     * perdre les données déjà saisies si elle redevient active.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: Niveau::class, inversedBy: 'classes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Niveau $niveau = null;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class, inversedBy: 'classes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?AnneeScolaire $anneeScolaire = null;

    /** Titulaire (professeur principal) de la classe — imprimé sur le bulletin sous la case "Appréciation du professeur principal". */
    #[ORM\ManyToOne(targetEntity: Enseignant::class)]
    #[ORM\JoinColumn(name: 'titulaire_id', nullable: true, onDelete: 'SET NULL')]
    private ?Enseignant $titulaire = null;

    #[ORM\OneToMany(targetEntity: \App\Scheduling\Entity\Attribution::class, mappedBy: 'classe')]
    private Collection $attributions;

    /**
     * Matières à choix (groupeOptionnel non nul, ex. Allemand/Espagnol) réellement suivies
     * par cette classe précise. Nécessaire car MatiereNiveau dit seulement "cette matière se
     * donne à ce niveau" : il ne dit pas laquelle une classe donnée a choisie, et ce choix
     * varie d'une classe à l'autre et d'une année sur l'autre (ex. 2026-2027 : 1ère A41 fait
     * Allemand, 1ère A42 fait Espagnol ; une autre année, une seule classe peut faire les deux
     * en groupes parallèles). Classe étant déjà rattachée à une AnneeScolaire, ce choix est de
     * fait "par année" sans dimension supplémentaire à modéliser.
     */
    #[ORM\ManyToMany(targetEntity: Matiere::class)]
    #[ORM\JoinTable(name: 'classe_matiere_optionnelle')]
    private Collection $matieresOptionnelles;

    #[ORM\OneToMany(targetEntity: \App\Student\Entity\Inscription::class, mappedBy: 'classe')]
    private Collection $inscriptions;

    public function __construct()
    {
        $this->attributions         = new ArrayCollection();
        $this->matieresOptionnelles = new ArrayCollection();
        $this->inscriptions         = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getEffectifMax(): int { return $this->effectifMax; }

    public function setEffectifMax(int $effectifMax): static
    {
        $this->effectifMax = $effectifMax;
        return $this;
    }

    public function isActive(): bool { return $this->active; }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    public function getNiveau(): ?Niveau { return $this->niveau; }

    public function setNiveau(?Niveau $niveau): static
    {
        $this->niveau = $niveau;
        return $this;
    }

    public function getAnneeScolaire(): ?AnneeScolaire { return $this->anneeScolaire; }

    public function setAnneeScolaire(?AnneeScolaire $anneeScolaire): static
    {
        $this->anneeScolaire = $anneeScolaire;
        return $this;
    }

    public function getTitulaire(): ?Enseignant { return $this->titulaire; }

    public function setTitulaire(?Enseignant $titulaire): static
    {
        $this->titulaire = $titulaire;
        return $this;
    }

    /** @return Collection<int, \App\Scheduling\Entity\Attribution> */
    public function getAttributions(): Collection { return $this->attributions; }

    /** @return Collection<int, Matiere> */
    public function getMatieresOptionnelles(): Collection { return $this->matieresOptionnelles; }

    /** @return Collection<int, \App\Student\Entity\Inscription> */
    public function getInscriptions(): Collection { return $this->inscriptions; }

    /**
     * Remplace l'intégralité du choix de matières optionnelles (utilisé par le formulaire
     * classe, en "by_reference: false" — Doctrine recalcule le diff sur la table de jointure
     * à l'écriture, pas besoin d'ajouter/retirer manuellement élément par élément).
     */
    public function setMatieresOptionnelles(Collection $matieresOptionnelles): static
    {
        $this->matieresOptionnelles = $matieresOptionnelles;
        return $this;
    }

    public function __toString(): string { return $this->nom; }
}
