<?php

declare(strict_types=1);

namespace App\Student\Entity;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Niveau;
use App\Shared\Entity\TimestampableTrait;
use App\Student\Enum\MotifFinInscription;
use App\Student\Repository\InscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InscriptionRepository::class)]
#[ORM\Table(name: 'inscription')]
#[ORM\UniqueConstraint(fields: ['eleve', 'classe'])]
#[ORM\HasLifecycleCallbacks]
class Inscription
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Eleve::class, inversedBy: 'inscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Eleve $eleve = null;

    /** Niveau choisi à l'inscription, indépendamment de la classe : permet d'inscrire un
     * élève avant qu'une classe précise ne lui soit affectée (individuellement ou en lot). */
    #[ORM\ManyToOne(targetEntity: Niveau::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Niveau $niveau = null;

    #[ORM\ManyToOne(targetEntity: Classe::class, inversedBy: 'inscriptions')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Classe $classe = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateInscription = null;

    /** Null = inscription en cours ; renseignée = clôturée (voir motifFin). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(length: 20, nullable: true, enumType: MotifFinInscription::class)]
    private ?MotifFinInscription $motifFin = null;

    #[ORM\Column]
    private bool $redoublant = false;

    public function getId(): ?int { return $this->id; }

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

    public function getDateInscription(): ?\DateTimeImmutable { return $this->dateInscription; }

    public function setDateInscription(\DateTimeImmutable $dateInscription): static
    {
        $this->dateInscription = $dateInscription;
        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable { return $this->dateFin; }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;
        return $this;
    }

    public function getMotifFin(): ?MotifFinInscription { return $this->motifFin; }

    public function setMotifFin(?MotifFinInscription $motifFin): static
    {
        $this->motifFin = $motifFin;
        return $this;
    }

    public function isRedoublant(): bool { return $this->redoublant; }

    public function setRedoublant(bool $redoublant): static
    {
        $this->redoublant = $redoublant;
        return $this;
    }

    public function isEnCours(): bool
    {
        return $this->dateFin === null;
    }
}
