<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Entity;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Repository\ExamenBlancReleveRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Student\Entity\Eleve;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot figé du relevé de notes d'un élève pour un examen blanc — sur le modèle de
 * Grading\Entity\Bulletin : une fois généré (voir ExamenBlancReleveGenerator), ne doit plus
 * être recalculé automatiquement, seule une suppression explicite permet de régénérer.
 * Le rang/effectif/bilan portent sur le NIVEAU entier (toutes classes confondues), pas sur
 * la seule classe de l'élève — c'est la différence structurante avec Bulletin.
 */
#[ORM\Entity(repositoryClass: ExamenBlancReleveRepository::class)]
#[ORM\Table(name: 'examen_blanc_releve')]
#[ORM\UniqueConstraint(fields: ['examenBlanc', 'eleve'])]
#[ORM\HasLifecycleCallbacks]
class ExamenBlancReleve
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ExamenBlanc::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?ExamenBlanc $examenBlanc = null;

    #[ORM\ManyToOne(targetEntity: Eleve::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Eleve $eleve = null;

    /** Niveau et classe au moment de la génération (snapshot — l'élève peut changer de classe ensuite). */
    #[ORM\ManyToOne(targetEntity: Niveau::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Niveau $niveau = null;

    #[ORM\ManyToOne(targetEntity: Classe::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Classe $classe = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneGenerale = null;

    #[ORM\Column(nullable: true)]
    private ?int $rangNiveau = null;

    #[ORM\Column]
    private int $effectifNiveau = 0;

    /** Bilan du niveau entier (identique sur tous les relevés du même examen blanc/niveau). */
    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneNiveauFaible = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneNiveauForte = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneNiveauGenerale = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneLitteraire = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneScientifique = null;

    #[ORM\OneToMany(targetEntity: ExamenBlancReleveMatiere::class, mappedBy: 'releve', cascade: ['persist', 'remove'])]
    private Collection $matieres;

    public function __construct()
    {
        $this->matieres = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getExamenBlanc(): ?ExamenBlanc { return $this->examenBlanc; }

    public function setExamenBlanc(?ExamenBlanc $examenBlanc): static
    {
        $this->examenBlanc = $examenBlanc;
        return $this;
    }

    public function getEleve(): ?Eleve { return $this->eleve; }

    public function setEleve(?Eleve $eleve): static
    {
        $this->eleve = $eleve;
        return $this;
    }

    public function getNiveau(): ?Niveau { return $this->niveau; }

    public function setNiveau(?Niveau $niveau): static
    {
        $this->niveau = $niveau;
        return $this;
    }

    public function getClasse(): ?Classe { return $this->classe; }

    public function setClasse(?Classe $classe): static
    {
        $this->classe = $classe;
        return $this;
    }

    public function getMoyenneGenerale(): ?string { return $this->moyenneGenerale; }

    public function setMoyenneGenerale(?string $moyenneGenerale): static
    {
        $this->moyenneGenerale = $moyenneGenerale;
        return $this;
    }

    public function getRangNiveau(): ?int { return $this->rangNiveau; }

    public function setRangNiveau(?int $rangNiveau): static
    {
        $this->rangNiveau = $rangNiveau;
        return $this;
    }

    public function getEffectifNiveau(): int { return $this->effectifNiveau; }

    public function setEffectifNiveau(int $effectifNiveau): static
    {
        $this->effectifNiveau = $effectifNiveau;
        return $this;
    }

    public function getMoyenneNiveauFaible(): ?string { return $this->moyenneNiveauFaible; }

    public function setMoyenneNiveauFaible(?string $moyenneNiveauFaible): static
    {
        $this->moyenneNiveauFaible = $moyenneNiveauFaible;
        return $this;
    }

    public function getMoyenneNiveauForte(): ?string { return $this->moyenneNiveauForte; }

    public function setMoyenneNiveauForte(?string $moyenneNiveauForte): static
    {
        $this->moyenneNiveauForte = $moyenneNiveauForte;
        return $this;
    }

    public function getMoyenneNiveauGenerale(): ?string { return $this->moyenneNiveauGenerale; }

    public function setMoyenneNiveauGenerale(?string $moyenneNiveauGenerale): static
    {
        $this->moyenneNiveauGenerale = $moyenneNiveauGenerale;
        return $this;
    }

    public function getMoyenneLitteraire(): ?string { return $this->moyenneLitteraire; }

    public function setMoyenneLitteraire(?string $moyenneLitteraire): static
    {
        $this->moyenneLitteraire = $moyenneLitteraire;
        return $this;
    }

    public function getMoyenneScientifique(): ?string { return $this->moyenneScientifique; }

    public function setMoyenneScientifique(?string $moyenneScientifique): static
    {
        $this->moyenneScientifique = $moyenneScientifique;
        return $this;
    }

    /** @return Collection<int, ExamenBlancReleveMatiere> */
    public function getMatieres(): Collection { return $this->matieres; }

    /** Somme des coefficients des matières notées (ligne TOTAUX du relevé). */
    public function getCoefficientTotal(): string
    {
        $total = 0.0;
        foreach ($this->matieres as $matiere) {
            if ($matiere->getNote() !== null) {
                $total += (float) $matiere->getCoefficient();
            }
        }

        return number_format($total, 2, '.', '');
    }

    /** Somme des (note × coefficient) des matières notées (ligne TOTAUX du relevé). */
    public function getNoteCoefTotal(): string
    {
        $total = 0.0;
        foreach ($this->matieres as $matiere) {
            if ($matiere->getNote() !== null) {
                $total += (float) $matiere->getNote() * (float) $matiere->getCoefficient();
            }
        }

        return number_format($total, 2, '.', '');
    }
}
