<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Repository\SalleRepository;
use App\Scheduling\Entity\EmploiDuTempsVersion;
use App\Scheduling\Entity\Seance;
use App\Scheduling\Enum\OrigineVersionEdt;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Repository\EmploiDuTempsVersionRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Scheduling\Service\Dto\GenerationResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gère l'historique des emplois du temps : à chaque génération automatique (ou sur
 * demande explicite), on prend un instantané complet des séances de l'année, qu'on
 * pourra restaurer plus tard pour retravailler une version antérieure.
 *
 * L'historique est volontairement borné à MAX_VERSIONS entrées par année — au-delà,
 * les plus anciennes sont supprimées : c'est un filet de sécurité "revenir en
 * arrière", pas une archive exhaustive.
 */
class EmploiDuTempsHistorique
{
    /**
     * Nombre d'entrées conservées par année scolaire. Le besoin exprimé était « au
     * moins 5 » ; on en garde 10 pour absorber les instantanés automatiques pris
     * avant chaque restauration sans écraser trop vite les vraies générations.
     */
    public const MAX_VERSIONS = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeanceRepository $seanceRepo,
        private readonly EmploiDuTempsVersionRepository $versionRepo,
        private readonly AttributionRepository $attributionRepo,
        private readonly CreneauRepository $creneauRepo,
        private readonly SalleRepository $salleRepo,
    ) {
    }

    /**
     * Enregistre l'état actuel de l'emploi du temps de l'année dans l'historique.
     *
     * Retourne null si rien n'a été enregistré (aucune séance à sauvegarder pour une
     * origine autre qu'une génération — inutile de garder une version vide).
     */
    public function capturer(
        AnneeScolaire $annee,
        OrigineVersionEdt $origine,
        string $libelle,
        ?GenerationResult $resultat = null,
    ): ?EmploiDuTempsVersion {
        $seances = $this->seanceRepo->findByAnneeScolaire((int) $annee->getId());

        if ($seances === [] && $origine !== OrigineVersionEdt::Generation) {
            return null;
        }

        $donnees = [];
        foreach ($seances as $seance) {
            $donnees[] = [
                $seance->getAttribution()->getId(),
                $seance->getCreneau()->getId(),
                $seance->getSalle()->getId(),
                $seance->isVerrouille(),
            ];
        }

        $version = (new EmploiDuTempsVersion())
            ->setAnneeScolaire($annee)
            ->setOrigine($origine)
            ->setLibelle($libelle)
            ->setNbSeances(count($donnees))
            ->setHeuresPlacees($resultat?->heuresPlacees ?? count($donnees))
            ->setHeuresNonPlacees($resultat?->heuresNonPlacees);

        $version->setDonnees($donnees);

        $this->em->persist($version);
        $this->em->flush();

        $this->elaguer($annee);

        return $version;
    }

    /**
     * Restaure une version : l'état courant est d'abord sauvegardé (pour pouvoir
     * revenir en arrière), puis toutes les séances de l'année sont remplacées par
     * celles de la version choisie.
     *
     * Les séances dont l'attribution ou la salle a été supprimée depuis l'instantané
     * sont ignorées silencieusement (comptées dans le retour : nb effectivement
     * recréées).
     */
    public function restaurer(EmploiDuTempsVersion $version): int
    {
        $annee = $version->getAnneeScolaire();
        if ($annee === null) {
            return 0;
        }

        $this->capturer(
            $annee,
            OrigineVersionEdt::PreRestauration,
            'État avant restauration de « '.$version->getLibelle().' »',
        );

        $attributions = $this->attributionRepo->findByAnneeScolaire((int) $annee->getId());
        $attributionParId = [];
        foreach ($attributions as $attribution) {
            $attributionParId[$attribution->getId()] = $attribution;
        }

        $ids = array_keys($attributionParId);
        if ($ids !== []) {
            $this->em->createQueryBuilder()
                ->delete(Seance::class, 's')
                ->where('s.attribution IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->execute();
        }

        $creneauParId = [];
        foreach ($this->creneauRepo->findOrdonnes() as $creneau) {
            $creneauParId[$creneau->getId()] = $creneau;
        }

        $salleParId = [];
        foreach ($this->salleRepo->findAll() as $salle) {
            $salleParId[$salle->getId()] = $salle;
        }

        $recreees = 0;
        foreach ($version->getDonnees() as $tuple) {
            // array_pad : les instantanés pris avant l'ajout du verrouillage (2026-09-11)
            // n'ont que 3 éléments — on les restaure sans verrou plutôt que de planter.
            [$attributionId, $creneauId, $salleId, $verrouille] = array_pad($tuple, 4, false);

            $attribution = $attributionParId[$attributionId] ?? null;
            $creneau     = $creneauParId[$creneauId] ?? null;
            $salle       = $salleParId[$salleId] ?? null;

            if ($attribution === null || $creneau === null || $salle === null) {
                continue;
            }

            $seance = (new Seance())
                ->setAttribution($attribution)
                ->setCreneau($creneau)
                ->setSalle($salle)
                ->setVerrouille((bool) $verrouille);

            $this->em->persist($seance);
            $recreees++;
        }

        $this->em->flush();

        return $recreees;
    }

    public function supprimer(EmploiDuTempsVersion $version): void
    {
        $this->em->remove($version);
        $this->em->flush();
    }

    /** Ne conserve que les MAX_VERSIONS entrées les plus récentes de l'année. */
    private function elaguer(AnneeScolaire $annee): void
    {
        $versions = $this->versionRepo->findByAnnee((int) $annee->getId());
        $surplus  = array_slice($versions, self::MAX_VERSIONS);

        if ($surplus === []) {
            return;
        }

        foreach ($surplus as $vieille) {
            $this->em->remove($vieille);
        }
        $this->em->flush();
    }
}
