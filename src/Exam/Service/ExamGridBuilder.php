<?php

declare(strict_types=1);

namespace App\Exam\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Cycle;
use App\Exam\Repository\ExamenRepository;
use App\Exam\Service\Dto\GrilleLigne;
use App\Scheduling\Enum\JourSemaine;

/**
 * Construit la structure de grille (lignes = couples date/heure uniques, colonnes = niveaux du
 * cycle) commune aux 4 vues du module (écran examens, écran surveillance, export PDF des deux) —
 * évite de dupliquer la logique de regroupement dans chaque contrôleur/template.
 */
class ExamGridBuilder
{
    public function __construct(private readonly ExamenRepository $examenRepo)
    {
    }

    /** @return GrilleLigne[] */
    public function construireLignes(Cycle $cycle, AnneeScolaire $annee): array
    {
        $examens = $this->examenRepo->findByCycle($cycle, $annee);

        $groupes = [];
        foreach ($examens as $examen) {
            $cle = $examen->getDate()->format('Y-m-d').'|'
                .$examen->getHeureDebut()->format('H:i').'|'
                .$examen->getHeureFin()->format('H:i');

            $groupes[$cle] ??= [
                'date'             => $examen->getDate(),
                'heureDebut'       => $examen->getHeureDebut(),
                'heureFin'         => $examen->getHeureFin(),
                'examensParNiveau' => [],
            ];

            foreach ($examen->getNiveaux() as $niveau) {
                $groupes[$cle]['examensParNiveau'][$niveau->getId()][] = $examen;
            }
        }

        // $examens arrive déjà trié (date, heureDebut) par ExamenRepository::findByCycle() ;
        // PHP conserve l'ordre d'insertion des clés associatives, donc $groupes l'est aussi.
        return array_map(
            static fn(array $g) => new GrilleLigne(
                $g['date'],
                $g['heureDebut'],
                $g['heureFin'],
                JourSemaine::depuisDate($g['date']),
                $g['examensParNiveau'],
            ),
            array_values($groupes),
        );
    }
}
