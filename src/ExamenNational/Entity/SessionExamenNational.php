<?php

declare(strict_types=1);

namespace App\ExamenNational\Entity;

use App\ExamenNational\Enum\StatutSessionExamenNational;
use App\ExamenNational\Enum\TypeExamenNational;
use App\ExamenNational\Repository\SessionExamenNationalRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un import = une session : le relevé scanné d'une série pour un examen donné (BEPC/BAC1/BAC2).
 * Reste BROUILLON (invisible dans les statistiques) le temps du traitement par lots et de la
 * vérification par l'admin — voir StatutSessionExamenNational et ExamenNationalImportController.
 */
#[ORM\Entity(repositoryClass: SessionExamenNationalRepository::class)]
#[ORM\Table(name: 'session_examen_national')]
#[ORM\HasLifecycleCallbacks]
class SessionExamenNational
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: TypeExamenNational::class)]
    private TypeExamenNational $type;

    #[ORM\Column(length: 20)]
    private string $serie = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $libelleSerie = null;

    #[ORM\Column(nullable: true)]
    private ?int $anneeSession = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $centreExamen = null;

    #[ORM\Column(length: 20, enumType: StatutSessionExamenNational::class)]
    private StatutSessionExamenNational $statut = StatutSessionExamenNational::BROUILLON;

    #[ORM\Column]
    private int $totalPages = 0;

    #[ORM\Column]
    private int $pagesTraitees = 0;

    /** Chemin (relatif à var/) du PDF uploadé, le temps du traitement par lots — nettoyé après confirmation. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cheminFichierTemporaire = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taillePagesLot = null;

    /** @var Collection<int, CandidatExamenNational> */
    #[ORM\OneToMany(targetEntity: CandidatExamenNational::class, mappedBy: 'session', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['pageNumero' => 'ASC'])]
    private Collection $candidats;

    public function __construct()
    {
        $this->candidats = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getType(): TypeExamenNational { return $this->type; }

    public function setType(TypeExamenNational $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getSerie(): string { return $this->serie; }

    public function setSerie(string $serie): static
    {
        $this->serie = $serie;
        return $this;
    }

    public function getLibelleSerie(): ?string { return $this->libelleSerie; }

    public function setLibelleSerie(?string $libelleSerie): static
    {
        $this->libelleSerie = $libelleSerie;
        return $this;
    }

    public function getAnneeSession(): ?int { return $this->anneeSession; }

    public function setAnneeSession(?int $anneeSession): static
    {
        $this->anneeSession = $anneeSession;
        return $this;
    }

    public function getCentreExamen(): ?string { return $this->centreExamen; }

    public function setCentreExamen(?string $centreExamen): static
    {
        $this->centreExamen = $centreExamen;
        return $this;
    }

    public function getStatut(): StatutSessionExamenNational { return $this->statut; }

    public function setStatut(StatutSessionExamenNational $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getTotalPages(): int { return $this->totalPages; }

    public function setTotalPages(int $totalPages): static
    {
        $this->totalPages = $totalPages;
        return $this;
    }

    public function getPagesTraitees(): int { return $this->pagesTraitees; }

    public function setPagesTraitees(int $pagesTraitees): static
    {
        $this->pagesTraitees = $pagesTraitees;
        return $this;
    }

    public function estTermine(): bool
    {
        return $this->totalPages > 0 && $this->pagesTraitees >= $this->totalPages;
    }

    public function getCheminFichierTemporaire(): ?string { return $this->cheminFichierTemporaire; }

    public function setCheminFichierTemporaire(?string $cheminFichierTemporaire): static
    {
        $this->cheminFichierTemporaire = $cheminFichierTemporaire;
        return $this;
    }

    public function getTaillePagesLot(): ?int
    {
        return $this->taillePagesLot !== null ? (int) $this->taillePagesLot : null;
    }

    public function setTaillePagesLot(?int $taillePagesLot): static
    {
        $this->taillePagesLot = $taillePagesLot !== null ? (string) $taillePagesLot : null;
        return $this;
    }

    /** @return Collection<int, CandidatExamenNational> */
    public function getCandidats(): Collection { return $this->candidats; }

    public function getLibelleComplet(): string
    {
        $libelle = $this->type->label().' — Série '.$this->serie;
        if ($this->libelleSerie !== null) {
            $libelle .= ' ('.$this->libelleSerie.')';
        }
        if ($this->anneeSession !== null) {
            $libelle .= ' — '.$this->anneeSession;
        }
        return $libelle;
    }

    public function __toString(): string { return $this->getLibelleComplet(); }
}
