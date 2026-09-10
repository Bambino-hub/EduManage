<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\Matiere;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Scheduling\Entity\Attribution;
use App\Scheduling\Repository\AttributionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maintient `Attribution.volumeHoraireHebdo` aligné sur la grille horaire
 * `MatiereNiveau` (heures/semaine d'une matière à un niveau) — la seule source de
 * vérité. Le volume n'est jamais saisi à la main sur l'attribution (cf.
 * AttributionController::resoudreVolumeHoraire) ; il doit donc être répercuté
 * automatiquement quand la grille change, sinon les attributions déjà créées
 * gardent l'ancienne valeur.
 */
class AttributionVolumeHoraireSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttributionRepository $attributionRepo,
        private readonly MatiereNiveauRepository $matiereNiveauRepo,
    ) {
    }

    /**
     * Réaligne toutes les attributions d'une matière sur son volume horaire par
     * niveau. Ne modifie que les lignes réellement désynchronisées, et laisse
     * intactes (en les signalant) celles dont le niveau n'a pas de volume défini
     * (> 0) — matière plus enseignée à ce niveau : à traiter à la main.
     *
     * @return array{misAJour: int, ignorees: list<Attribution>}
     */
    public function synchroniserMatiere(Matiere $matiere): array
    {
        $heuresParNiveauId = [];
        foreach ($this->matiereNiveauRepo->findBy(['matiere' => $matiere]) as $mn) {
            $heuresParNiveauId[$mn->getNiveau()->getId()] = (float) $mn->getHeuresParSemaine();
        }

        $misAJour = 0;
        $ignorees = [];

        foreach ($this->attributionRepo->findBy(['matiere' => $matiere]) as $attribution) {
            $niveauId = $attribution->getClasse()?->getNiveau()?->getId();
            $heures   = $niveauId !== null ? ($heuresParNiveauId[$niveauId] ?? 0.0) : 0.0;

            if ($heures <= 0) {
                $ignorees[] = $attribution;
                continue;
            }

            $volume = (int) round($heures);
            if ($attribution->getVolumeHoraireHebdo() !== $volume) {
                $attribution->setVolumeHoraireHebdo($volume);
                $misAJour++;
            }
        }

        if ($misAJour > 0) {
            $this->em->flush();
        }

        return ['misAJour' => $misAJour, 'ignorees' => $ignorees];
    }
}
