<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Scheduling\Entity\Creneau;
use App\Scheduling\Entity\Seance;
use App\Scheduling\Enum\JourSemaine;
use App\Scheduling\Repository\CreneauRepository;

/**
 * Construit la grille hebdomadaire (jour x créneau) affichée par toutes les vues
 * d'emploi du temps — admin (par classe/enseignant, impressions, vue globale) et
 * espace enseignant. Extrait d'EmploiDuTempsController pour être réutilisé sans
 * dupliquer la logique de regroupement.
 */
class GrilleEmploiDuTempsBuilder
{
    /**
     * Regroupe une liste de séances en grille[jour][ordre] = Seance[] — plusieurs
     * séances peuvent partager un même créneau pour une même classe (matières
     * "parallèles" comme Allemand/Espagnol), d'où une liste et pas une séance unique.
     *
     * @param Seance[] $seances
     * @return array<string, array<int, Seance[]>>
     */
    public function regrouperParCreneau(array $seances): array
    {
        $grille = [];
        foreach ($seances as $seance) {
            $creneau = $seance->getCreneau();
            $grille[$creneau->getJourSemaine()->value][$creneau->getOrdre()][] = $seance;
        }

        return $grille;
    }

    /**
     * Structure des créneaux (indépendante de la classe/l'enseignant affiché) partagée
     * par toutes les vues EDT : créneaux indexés par jour puis ordre, jours réellement
     * utilisés triés, et l'ordre maximum (nombre de lignes de la grille).
     *
     * @return array{0: array<string, array<int, Creneau>>, 1: JourSemaine[], 2: int}
     */
    public function construireStructureCreneaux(CreneauRepository $creneauRepo): array
    {
        $creneauxParJour = [];
        $ordreMax        = 0;
        foreach ($creneauRepo->findOrdonnes() as $creneau) {
            $creneauxParJour[$creneau->getJourSemaine()->value][$creneau->getOrdre()] = $creneau;
            $ordreMax = max($ordreMax, $creneau->getOrdre());
        }

        $joursAffiches = array_values(array_filter(
            JourSemaine::cases(),
            static fn (JourSemaine $j) => isset($creneauxParJour[$j->value]),
        ));
        usort($joursAffiches, static fn (JourSemaine $a, JourSemaine $b) => $a->ordre() <=> $b->ordre());

        return [$creneauxParJour, $joursAffiches, $ordreMax];
    }
}
