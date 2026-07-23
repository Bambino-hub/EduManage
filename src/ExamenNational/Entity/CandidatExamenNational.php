<?php

declare(strict_types=1);

namespace App\ExamenNational\Entity;

use App\ExamenNational\Repository\CandidatExamenNationalRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Staff\Enum\Sexe;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un candidat tel que lu sur une page du relevé (une page = un candidat). Pas de lien vers
 * Eleve : les statistiques sont agrégées, sans rapprochement au dossier élève (voir
 * [[saisie-automatique-notes]] pour le principe équivalent côté fiche de notes de classe).
 */
#[ORM\Entity(repositoryClass: CandidatExamenNationalRepository::class)]
#[ORM\Table(name: 'candidat_examen_national')]
#[ORM\HasLifecycleCallbacks]
class CandidatExamenNational
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SessionExamenNational::class, inversedBy: 'candidats')]
    #[ORM\JoinColumn(nullable: false)]
    private ?SessionExamenNational $session = null;

    #[ORM\Column(length: 80)]
    private string $nom = '';

    #[ORM\Column(length: 120)]
    private string $prenoms = '';

    #[ORM\Column(length: 1, nullable: true, enumType: Sexe::class)]
    private ?Sexe $sexe = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lieuNaissance = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numeroJury = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numeroTable = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $decisionJury = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $moyenneGlobaleAffichee = null;

    /** Total de points imprimé en bas de la table "Epreuves Écrites" — sert au contrôle arithmétique. */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $totalPointsEcritesAffiche = null;

    /** Position dans le PDF source (1-indexé) — retrouver une page en cas d'anomalie. */
    #[ORM\Column]
    private int $pageNumero = 0;

    #[ORM\Column]
    private bool $controleArithmetiqueOk = false;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $ecartControle = null;

    /** @var Collection<int, NoteMatiereCandidat> */
    #[ORM\OneToMany(targetEntity: NoteMatiereCandidat::class, mappedBy: 'candidat', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $notes;

    public function __construct()
    {
        $this->notes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getSession(): ?SessionExamenNational { return $this->session; }

    public function setSession(?SessionExamenNational $session): static
    {
        $this->session = $session;
        return $this;
    }

    public function getNom(): string { return $this->nom; }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getPrenoms(): string { return $this->prenoms; }

    public function setPrenoms(string $prenoms): static
    {
        $this->prenoms = $prenoms;
        return $this;
    }

    public function getSexe(): ?Sexe { return $this->sexe; }

    public function setSexe(?Sexe $sexe): static
    {
        $this->sexe = $sexe;
        return $this;
    }

    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;
        return $this;
    }

    public function getLieuNaissance(): ?string { return $this->lieuNaissance; }

    public function setLieuNaissance(?string $lieuNaissance): static
    {
        $this->lieuNaissance = $lieuNaissance;
        return $this;
    }

    public function getNumeroJury(): ?string { return $this->numeroJury; }

    public function setNumeroJury(?string $numeroJury): static
    {
        $this->numeroJury = $numeroJury;
        return $this;
    }

    public function getNumeroTable(): ?string { return $this->numeroTable; }

    public function setNumeroTable(?string $numeroTable): static
    {
        $this->numeroTable = $numeroTable;
        return $this;
    }

    public function getDecisionJury(): ?string { return $this->decisionJury; }

    public function setDecisionJury(?string $decisionJury): static
    {
        $this->decisionJury = $decisionJury;
        return $this;
    }

    public function getMoyenneGlobaleAffichee(): ?string { return $this->moyenneGlobaleAffichee; }

    public function setMoyenneGlobaleAffichee(?string $moyenneGlobaleAffichee): static
    {
        $this->moyenneGlobaleAffichee = $moyenneGlobaleAffichee;
        return $this;
    }

    public function getTotalPointsEcritesAffiche(): ?string { return $this->totalPointsEcritesAffiche; }

    public function setTotalPointsEcritesAffiche(?string $totalPointsEcritesAffiche): static
    {
        $this->totalPointsEcritesAffiche = $totalPointsEcritesAffiche;
        return $this;
    }

    public function getPageNumero(): int { return $this->pageNumero; }

    public function setPageNumero(int $pageNumero): static
    {
        $this->pageNumero = $pageNumero;
        return $this;
    }

    public function isControleArithmetiqueOk(): bool { return $this->controleArithmetiqueOk; }

    public function setControleArithmetiqueOk(bool $controleArithmetiqueOk): static
    {
        $this->controleArithmetiqueOk = $controleArithmetiqueOk;
        return $this;
    }

    public function getEcartControle(): ?string { return $this->ecartControle; }

    public function setEcartControle(?string $ecartControle): static
    {
        $this->ecartControle = $ecartControle;
        return $this;
    }

    /** @return Collection<int, NoteMatiereCandidat> */
    public function getNotes(): Collection { return $this->notes; }

    public function getNomComplet(): string
    {
        return "{$this->nom} {$this->prenoms}";
    }

    public function __toString(): string { return $this->getNomComplet(); }
}
