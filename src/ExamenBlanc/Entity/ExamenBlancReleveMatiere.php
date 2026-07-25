<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Entity;

use App\Academic\Entity\Matiere;
use App\ExamenBlanc\Repository\ExamenBlancReleveMatiereRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne "matière" d'un ExamenBlancReleve — valeurs entièrement copiées au moment de la
 * génération. Une seule note par matière (pas de détail interro/devoir/compos), sur le
 * modèle simplifié de Grading\Entity\BulletinMatiere.
 */
#[ORM\Entity(repositoryClass: ExamenBlancReleveMatiereRepository::class)]
#[ORM\Table(name: 'examen_blanc_releve_matiere')]
#[ORM\HasLifecycleCallbacks]
class ExamenBlancReleveMatiere
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ExamenBlancReleve::class, inversedBy: 'matieres')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ExamenBlancReleve $releve = null;

    #[ORM\ManyToOne(targetEntity: Matiere::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Matiere $matiere = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2)]
    private string $coefficient = '1.00';

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 150)]
    private string $enseignantNom = '';

    /** Calculée à la génération via Grading\Service\AppreciationScale::pour($note) — même échelle que le bulletin. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $appreciation = null;

    public function getId(): ?int { return $this->id; }

    public function getReleve(): ?ExamenBlancReleve { return $this->releve; }

    public function setReleve(?ExamenBlancReleve $releve): static
    {
        $this->releve = $releve;
        return $this;
    }

    public function getMatiere(): ?Matiere { return $this->matiere; }

    public function setMatiere(?Matiere $matiere): static
    {
        $this->matiere = $matiere;
        return $this;
    }

    public function getCoefficient(): string { return $this->coefficient; }

    public function setCoefficient(string $coefficient): static
    {
        $this->coefficient = $coefficient;
        return $this;
    }

    public function getNote(): ?string { return $this->note; }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getEnseignantNom(): string { return $this->enseignantNom; }

    public function setEnseignantNom(string $enseignantNom): static
    {
        $this->enseignantNom = $enseignantNom;
        return $this;
    }

    public function getAppreciation(): ?string { return $this->appreciation; }

    public function setAppreciation(?string $appreciation): static
    {
        $this->appreciation = $appreciation;
        return $this;
    }
}
