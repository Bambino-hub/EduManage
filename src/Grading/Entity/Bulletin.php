<?php

declare(strict_types=1);

namespace App\Grading\Entity;

use App\Academic\Entity\Classe;
use App\Grading\Enum\MentionConseil;
use App\Grading\Repository\BulletinRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Student\Entity\Eleve;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot figé de la moyenne et du rang d'un élève pour un trimestre (voir
 * Grading\Service\BulletinGenerator, qui le construit à partir de MoyenneCalculator).
 * Une fois généré, ne doit plus être modifié ni recalculé automatiquement — seule une
 * suppression explicite permet de régénérer, pour qu'un bulletin déjà remis à une
 * famille ne bouge jamais après coup.
 */
#[ORM\Entity(repositoryClass: BulletinRepository::class)]
#[ORM\Table(name: 'bulletin')]
#[ORM\UniqueConstraint(fields: ['eleve', 'trimestre'])]
#[ORM\HasLifecycleCallbacks]
class Bulletin
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Eleve::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Eleve $eleve = null;

    /** Classe au moment de la génération (snapshot — l'élève peut changer de classe ensuite). */
    #[ORM\ManyToOne(targetEntity: Classe::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Classe $classe = null;

    #[ORM\ManyToOne(targetEntity: Trimestre::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Trimestre $trimestre = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneGenerale = null;

    #[ORM\Column(nullable: true)]
    private ?int $rang = null;

    #[ORM\Column]
    private int $effectifClasse = 0;

    /** Moyenne Générale Annuelle : moyenne simple des moyennes de trimestre déjà générées cette année. */
    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneAnnuelle = null;

    #[ORM\Column(nullable: true)]
    private ?int $rangAnnuel = null;

    /** Bilan de classe (identique sur tous les bulletins de la même classe/trimestre, comme effectifClasse). */
    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneClasseFaible = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneClasseForte = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $moyenneClasseGenerale = null;

    /**
     * Annotations du conseil des professeurs, ajoutées après la génération — n'entrent
     * pas dans le verrouillage du snapshot (ce ne sont pas des valeurs recalculées).
     */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $decisionConseil = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $appreciationProfesseurPrincipal = null;

    /** @var string[]|null valeurs de MentionConseil, stockées telles quelles */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $mentions = null;

    #[ORM\OneToMany(targetEntity: BulletinMatiere::class, mappedBy: 'bulletin', cascade: ['persist', 'remove'])]
    private Collection $matieres;

    #[ORM\OneToMany(targetEntity: BulletinBilanDomaine::class, mappedBy: 'bulletin', cascade: ['persist', 'remove'])]
    private Collection $bilansDomaine;

    public function __construct()
    {
        $this->matieres      = new ArrayCollection();
        $this->bilansDomaine = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getEleve(): ?Eleve { return $this->eleve; }

    public function setEleve(?Eleve $eleve): static
    {
        $this->eleve = $eleve;
        return $this;
    }

    public function getClasse(): ?Classe { return $this->classe; }

    public function setClasse(?Classe $classe): static
    {
        $this->classe = $classe;
        return $this;
    }

    public function getTrimestre(): ?Trimestre { return $this->trimestre; }

    public function setTrimestre(?Trimestre $trimestre): static
    {
        $this->trimestre = $trimestre;
        return $this;
    }

    public function getMoyenneGenerale(): ?string { return $this->moyenneGenerale; }

    public function setMoyenneGenerale(?string $moyenneGenerale): static
    {
        $this->moyenneGenerale = $moyenneGenerale;
        return $this;
    }

    public function getRang(): ?int { return $this->rang; }

    public function setRang(?int $rang): static
    {
        $this->rang = $rang;
        return $this;
    }

    public function getEffectifClasse(): int { return $this->effectifClasse; }

    public function setEffectifClasse(int $effectifClasse): static
    {
        $this->effectifClasse = $effectifClasse;
        return $this;
    }

    public function getMoyenneAnnuelle(): ?string { return $this->moyenneAnnuelle; }

    public function setMoyenneAnnuelle(?string $moyenneAnnuelle): static
    {
        $this->moyenneAnnuelle = $moyenneAnnuelle;
        return $this;
    }

    public function getRangAnnuel(): ?int { return $this->rangAnnuel; }

    public function setRangAnnuel(?int $rangAnnuel): static
    {
        $this->rangAnnuel = $rangAnnuel;
        return $this;
    }

    public function getMoyenneClasseFaible(): ?string { return $this->moyenneClasseFaible; }

    public function setMoyenneClasseFaible(?string $moyenneClasseFaible): static
    {
        $this->moyenneClasseFaible = $moyenneClasseFaible;
        return $this;
    }

    public function getMoyenneClasseForte(): ?string { return $this->moyenneClasseForte; }

    public function setMoyenneClasseForte(?string $moyenneClasseForte): static
    {
        $this->moyenneClasseForte = $moyenneClasseForte;
        return $this;
    }

    public function getMoyenneClasseGenerale(): ?string { return $this->moyenneClasseGenerale; }

    public function setMoyenneClasseGenerale(?string $moyenneClasseGenerale): static
    {
        $this->moyenneClasseGenerale = $moyenneClasseGenerale;
        return $this;
    }

    public function getDecisionConseil(): ?string { return $this->decisionConseil; }

    public function setDecisionConseil(?string $decisionConseil): static
    {
        $this->decisionConseil = $decisionConseil;
        return $this;
    }

    public function getAppreciationProfesseurPrincipal(): ?string { return $this->appreciationProfesseurPrincipal; }

    public function setAppreciationProfesseurPrincipal(?string $appreciationProfesseurPrincipal): static
    {
        $this->appreciationProfesseurPrincipal = $appreciationProfesseurPrincipal;
        return $this;
    }

    /** @return MentionConseil[] */
    public function getMentions(): array
    {
        return array_map(
            static fn (string $valeur): MentionConseil => MentionConseil::from($valeur),
            $this->mentions ?? [],
        );
    }

    /** @param MentionConseil[] $mentions */
    public function setMentions(array $mentions): static
    {
        $this->mentions = array_map(static fn (MentionConseil $m): string => $m->value, $mentions);
        return $this;
    }

    /** @return Collection<int, BulletinMatiere> */
    public function getMatieres(): Collection { return $this->matieres; }

    /** @return Collection<int, BulletinBilanDomaine> */
    public function getBilansDomaine(): Collection { return $this->bilansDomaine; }

    /**
     * Somme des coefficients des matières notées (ligne TOTAUX du bulletin) — cohérent
     * avec le calcul de moyenneGenerale, qui exclut déjà les matières sans note.
     */
    public function getCoefficientTotal(): string
    {
        $total = 0.0;
        foreach ($this->matieres as $matiere) {
            if ($matiere->getMoyenne() !== null) {
                $total += (float) $matiere->getCoefficient();
            }
        }

        return number_format($total, 2, '.', '');
    }

    /** Somme des (moyenne × coefficient) des matières notées (ligne TOTAUX du bulletin). */
    public function getMoyenneCoefTotal(): string
    {
        $total = 0.0;
        foreach ($this->matieres as $matiere) {
            if ($matiere->getMoyenne() !== null) {
                $total += (float) $matiere->getMoyenne() * (float) $matiere->getCoefficient();
            }
        }

        return number_format($total, 2, '.', '');
    }
}
