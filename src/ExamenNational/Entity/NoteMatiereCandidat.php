<?php

declare(strict_types=1);

namespace App\ExamenNational\Entity;

use App\ExamenNational\Enum\TypeEpreuveExamenNational;
use App\ExamenNational\Repository\NoteMatiereCandidatRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne du tableau de notes d'un candidat (une matière). `matiereLibelle` est le texte
 * tel que lu sur le relevé — pas de lien vers Matiere, le regroupement pour les statistiques
 * se fait par normalisation de texte (voir StatistiqueReleveCalculator). `note` null =
 * candidat non concerné par cette matière (case "-" sur le relevé), pas une note de 0.
 */
#[ORM\Entity(repositoryClass: NoteMatiereCandidatRepository::class)]
#[ORM\Table(name: 'note_matiere_candidat')]
class NoteMatiereCandidat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CandidatExamenNational::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CandidatExamenNational $candidat = null;

    #[ORM\Column(length: 20, enumType: TypeEpreuveExamenNational::class)]
    private TypeEpreuveExamenNational $typeEpreuve;

    #[ORM\Column(length: 100)]
    private string $matiereLibelle = '';

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $coefficient = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $pointsObtenus = null;

    public function getId(): ?int { return $this->id; }

    public function getCandidat(): ?CandidatExamenNational { return $this->candidat; }

    public function setCandidat(?CandidatExamenNational $candidat): static
    {
        $this->candidat = $candidat;
        return $this;
    }

    public function getTypeEpreuve(): TypeEpreuveExamenNational { return $this->typeEpreuve; }

    public function setTypeEpreuve(TypeEpreuveExamenNational $typeEpreuve): static
    {
        $this->typeEpreuve = $typeEpreuve;
        return $this;
    }

    public function getMatiereLibelle(): string { return $this->matiereLibelle; }

    public function setMatiereLibelle(string $matiereLibelle): static
    {
        $this->matiereLibelle = $matiereLibelle;
        return $this;
    }

    public function getNote(): ?string { return $this->note; }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getCoefficient(): ?string { return $this->coefficient; }

    public function setCoefficient(?string $coefficient): static
    {
        $this->coefficient = $coefficient;
        return $this;
    }

    public function getPointsObtenus(): ?string { return $this->pointsObtenus; }

    public function setPointsObtenus(?string $pointsObtenus): static
    {
        $this->pointsObtenus = $pointsObtenus;
        return $this;
    }
}
