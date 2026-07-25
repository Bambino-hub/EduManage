<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Entity;

use App\Academic\Entity\Matiere;
use App\ExamenBlanc\Repository\ExamenBlancNoteRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Student\Entity\Inscription;
use Doctrine\ORM\Mapping as ORM;

/**
 * Note d'un élève à une matière d'un examen blanc — une seule valeur sur 20 (comme une
 * composition), pas de détail interro/devoir. Rattachée directement à Matiere (et non à une
 * Attribution classe+matière+enseignant précise) : un examen blanc note tout un NIVEAU à la
 * fois, une seule fiche fusionnant toutes les classes du niveau — il n'y a donc pas UNE
 * attribution responsable, potentiellement plusieurs (une par classe, parfois des
 * correcteurs externes à la charge normale). Voir ExamenBlanc\Service\ExamenBlancMoyenneCalculator,
 * qui retrouve l'enseignant à afficher sur le relevé via la classe de l'élève au moment du calcul.
 */
#[ORM\Entity(repositoryClass: ExamenBlancNoteRepository::class)]
#[ORM\Table(name: 'examen_blanc_note')]
#[ORM\UniqueConstraint(fields: ['examenBlanc', 'matiere', 'inscription'])]
#[ORM\HasLifecycleCallbacks]
class ExamenBlancNote
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ExamenBlanc::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?ExamenBlanc $examenBlanc = null;

    #[ORM\ManyToOne(targetEntity: Matiere::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Matiere $matiere = null;

    #[ORM\ManyToOne(targetEntity: Inscription::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Inscription $inscription = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $valeur = null;

    #[ORM\Column]
    private bool $absent = false;

    public function getId(): ?int { return $this->id; }

    public function getExamenBlanc(): ?ExamenBlanc { return $this->examenBlanc; }

    public function setExamenBlanc(?ExamenBlanc $examenBlanc): static
    {
        $this->examenBlanc = $examenBlanc;
        return $this;
    }

    public function getMatiere(): ?Matiere { return $this->matiere; }

    public function setMatiere(?Matiere $matiere): static
    {
        $this->matiere = $matiere;
        return $this;
    }

    public function getInscription(): ?Inscription { return $this->inscription; }

    public function setInscription(?Inscription $inscription): static
    {
        $this->inscription = $inscription;
        return $this;
    }

    public function getValeur(): ?string { return $this->valeur; }

    public function setValeur(?string $valeur): static
    {
        $this->valeur = $valeur;
        return $this;
    }

    public function isAbsent(): bool { return $this->absent; }

    public function setAbsent(bool $absent): static
    {
        $this->absent = $absent;
        return $this;
    }
}
