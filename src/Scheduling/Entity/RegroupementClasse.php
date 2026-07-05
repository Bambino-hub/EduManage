<?php

declare(strict_types=1);

namespace App\Scheduling\Entity;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Scheduling\Repository\RegroupementClasseRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fusionne plusieurs classes pour certaines matières : leurs séances doivent tomber
 * exactement au même créneau (ex. 1ère C, petit effectif, fusionnée avec 1ère D1 pour
 * HG/FR/ECM/PHILO/ANG/EPS où les volumes horaires coïncident). Ne contraint que le
 * créneau — chaque classe garde sa propre Attribution (enseignant et salle peuvent
 * différer), le générateur d'emploi du temps se charge de synchroniser les horaires.
 */
#[ORM\Entity(repositoryClass: RegroupementClasseRepository::class)]
#[ORM\Table(name: 'regroupement_classe')]
#[ORM\HasLifecycleCallbacks]
class RegroupementClasse
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $nom = '';

    #[ORM\ManyToMany(targetEntity: Classe::class)]
    #[ORM\JoinTable(name: 'regroupement_classe_classes')]
    private Collection $classes;

    #[ORM\ManyToMany(targetEntity: Matiere::class)]
    #[ORM\JoinTable(name: 'regroupement_classe_matieres')]
    private Collection $matieres;

    public function __construct()
    {
        $this->classes  = new ArrayCollection();
        $this->matieres = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    /** @return Collection<int, Classe> */
    public function getClasses(): Collection { return $this->classes; }

    public function setClasses(Collection $classes): static
    {
        $this->classes = $classes;
        return $this;
    }

    /** @return Collection<int, Matiere> */
    public function getMatieres(): Collection { return $this->matieres; }

    public function setMatieres(Collection $matieres): static
    {
        $this->matieres = $matieres;
        return $this;
    }

    public function __toString(): string { return $this->nom; }
}
