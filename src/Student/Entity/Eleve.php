<?php

declare(strict_types=1);

namespace App\Student\Entity;

use App\Shared\Entity\TimestampableTrait;
use App\Staff\Enum\Sexe;
use App\Student\Enum\StatutEleve;
use App\Student\Repository\EleveRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EleveRepository::class)]
#[ORM\Table(name: 'eleve')]
#[ORM\HasLifecycleCallbacks]
class Eleve
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $matricule = '';

    #[ORM\Column(length: 80)]
    private string $nom = '';

    #[ORM\Column(length: 80)]
    private string $prenom = '';

    #[ORM\Column(length: 1, nullable: true, enumType: Sexe::class)]
    private ?Sexe $sexe = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lieuNaissance = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 100)]
    private string $nomTuteur = '';

    #[ORM\Column(length: 20)]
    private string $telephoneTuteur = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $emailTuteur = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $lienTuteur = null;

    #[ORM\Column(length: 20, enumType: StatutEleve::class)]
    private StatutEleve $statut = StatutEleve::ACTIF;

    /** Nom de fichier dans public/uploads/eleves/ (voir Admin\Controller\EleveController::traiterPhoto()). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    /**
     * cascade remove : supprimer un élève (erreur de saisie, jamais réellement inscrit)
     * doit supprimer son historique d'inscriptions avec lui plutôt qu'échouer sur la
     * contrainte de clé étrangère. Un départ réel (transfert, exclusion) ne doit PAS passer
     * par une suppression : on clôture l'inscription et on change le statut de l'élève.
     */
    #[ORM\OneToMany(targetEntity: Inscription::class, mappedBy: 'eleve', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['dateInscription' => 'DESC'])]
    private Collection $inscriptions;

    public function __construct()
    {
        $this->inscriptions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getMatricule(): string { return $this->matricule; }

    public function setMatricule(string $matricule): static
    {
        $this->matricule = $matricule;
        return $this;
    }

    public function getNom(): string { return $this->nom; }

    public function setNom(string $nom): static
    {
        $this->nom = strtoupper($nom);
        return $this;
    }

    public function getPrenom(): string { return $this->prenom; }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;
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

    public function getAdresse(): ?string { return $this->adresse; }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;
        return $this;
    }

    public function getNomTuteur(): string { return $this->nomTuteur; }

    public function setNomTuteur(string $nomTuteur): static
    {
        $this->nomTuteur = $nomTuteur;
        return $this;
    }

    public function getTelephoneTuteur(): string { return $this->telephoneTuteur; }

    public function setTelephoneTuteur(string $telephoneTuteur): static
    {
        $this->telephoneTuteur = $telephoneTuteur;
        return $this;
    }

    public function getEmailTuteur(): ?string { return $this->emailTuteur; }

    public function setEmailTuteur(?string $emailTuteur): static
    {
        $this->emailTuteur = $emailTuteur;
        return $this;
    }

    public function getLienTuteur(): ?string { return $this->lienTuteur; }

    public function setLienTuteur(?string $lienTuteur): static
    {
        $this->lienTuteur = $lienTuteur;
        return $this;
    }

    public function getStatut(): StatutEleve { return $this->statut; }

    public function setStatut(StatutEleve $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getPhoto(): ?string { return $this->photo; }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;
        return $this;
    }

    /** @return Collection<int, Inscription> */
    public function getInscriptions(): Collection { return $this->inscriptions; }

    /** Inscription active (non clôturée) de l'élève, s'il en a une. */
    public function getInscriptionEnCours(): ?Inscription
    {
        foreach ($this->inscriptions as $inscription) {
            if ($inscription->getDateFin() === null) {
                return $inscription;
            }
        }
        return null;
    }

    public function getNomComplet(): string
    {
        return "{$this->nom} {$this->prenom}";
    }

    public function __toString(): string { return $this->getNomComplet(); }
}
