<?php

declare(strict_types=1);

namespace App\Scheduling\Entity;

use App\Academic\Entity\AnneeScolaire;
use App\Scheduling\Enum\OrigineVersionEdt;
use App\Scheduling\Repository\EmploiDuTempsVersionRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instantané complet d'un emploi du temps d'une année scolaire à un instant donné.
 *
 * Chaque génération automatique (et chaque enregistrement manuel) dépose une entrée
 * ici : la liste de toutes les séances est sérialisée en JSON sous la forme
 * `[[attributionId, creneauId, salleId], ...]`. On peut ainsi revenir sur une version
 * antérieure pour la retravailler sans avoir relancé la génération.
 *
 * L'historique est borné (EmploiDuTempsHistorique::MAX_VERSIONS par année) : les
 * entrées les plus anciennes sont supprimées automatiquement.
 */
#[ORM\Entity(repositoryClass: EmploiDuTempsVersionRepository::class)]
#[ORM\Table(name: 'emploi_du_temps_version')]
#[ORM\HasLifecycleCallbacks]
class EmploiDuTempsVersion
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnneeScolaire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AnneeScolaire $anneeScolaire = null;

    #[ORM\Column(length: 255)]
    private string $libelle = '';

    #[ORM\Column(length: 20, enumType: OrigineVersionEdt::class)]
    private OrigineVersionEdt $origine = OrigineVersionEdt::Manuel;

    #[ORM\Column]
    private int $nbSeances = 0;

    #[ORM\Column]
    private int $heuresPlacees = 0;

    #[ORM\Column(nullable: true)]
    private ?int $heuresNonPlacees = null;

    /**
     * Liste des séances sérialisées : chaque élément est `[attributionId, creneauId, salleId]`.
     *
     * @var array<int, array{0: int, 1: int, 2: int}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $donnees = [];

    public function getId(): ?int { return $this->id; }

    public function getAnneeScolaire(): ?AnneeScolaire { return $this->anneeScolaire; }

    public function setAnneeScolaire(?AnneeScolaire $anneeScolaire): static
    {
        $this->anneeScolaire = $anneeScolaire;
        return $this;
    }

    public function getLibelle(): string { return $this->libelle; }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
        return $this;
    }

    public function getOrigine(): OrigineVersionEdt { return $this->origine; }

    public function setOrigine(OrigineVersionEdt $origine): static
    {
        $this->origine = $origine;
        return $this;
    }

    public function getNbSeances(): int { return $this->nbSeances; }

    public function setNbSeances(int $nbSeances): static
    {
        $this->nbSeances = $nbSeances;
        return $this;
    }

    public function getHeuresPlacees(): int { return $this->heuresPlacees; }

    public function setHeuresPlacees(int $heuresPlacees): static
    {
        $this->heuresPlacees = $heuresPlacees;
        return $this;
    }

    public function getHeuresNonPlacees(): ?int { return $this->heuresNonPlacees; }

    public function setHeuresNonPlacees(?int $heuresNonPlacees): static
    {
        $this->heuresNonPlacees = $heuresNonPlacees;
        return $this;
    }

    /** @return array<int, array{0: int, 1: int, 2: int}> */
    public function getDonnees(): array { return $this->donnees; }

    /** @param array<int, array{0: int, 1: int, 2: int}> $donnees */
    public function setDonnees(array $donnees): static
    {
        $this->donnees = $donnees;
        return $this;
    }
}
