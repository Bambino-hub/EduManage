<?php

declare(strict_types=1);

namespace App\Exam\Entity;

use App\Academic\Entity\Classe;
use App\Exam\Repository\RegroupementSurveillanceRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Classes qui partagent physiquement la même salle pendant les examens et doivent donc recevoir
 * le(s) même(s) surveillant(s) — ex. 1ère C + 1ère D1, déjà réunies pour l'emploi du temps.
 * Indépendant de la matière (contrairement à RegroupementClasse, propre au module Scheduling
 * et utilisé pour forcer un même créneau d'EDT) : ici on regroupe pour TOUS les examens.
 */
#[ORM\Entity(repositoryClass: RegroupementSurveillanceRepository::class)]
#[ORM\Table(name: 'regroupement_surveillance')]
#[ORM\HasLifecycleCallbacks]
class RegroupementSurveillance
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $nom = '';

    #[ORM\ManyToMany(targetEntity: Classe::class)]
    #[ORM\JoinTable(name: 'regroupement_surveillance_classes')]
    private Collection $classes;

    public function __construct()
    {
        $this->classes = new ArrayCollection();
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

    public function __toString(): string { return $this->nom; }
}
