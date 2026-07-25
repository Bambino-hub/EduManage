<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Entity\ExamenBlanc;

/**
 * Résout, pour un examen blanc et un niveau donnés, les matières réellement évaluées :
 * intersection entre les matières enseignées à ce niveau et celles cochées sur
 * ExamenBlanc::matieres (le formulaire ne propose déjà que des matières ni facultatives ni
 * EPS, voir ExamenBlancType). Utilisé à la fois par ExamenBlancMoyenneCalculator et
 * ExamenBlancCompletudeChecker pour ne jamais diverger sur la liste des matières attendues.
 *
 * "Enseignée à ce niveau" = `MatiereNiveau::heuresParSemaine > 0`, PAS la simple présence
 * d'une ligne `Niveau::matiereNiveaux` : une ligne existe pour CHAQUE couple matière×niveau
 * (heures à 0 servant de placeholder "non enseignée ici", ex. Philosophie a une ligne à 0h
 * pour la 3ème) — voir MatiereNiveauRepository::findToutesEnseignees(), même convention.
 * Ignorer les heures faisait apparaître Philosophie (cochée pour le cycle 2 de l'examen)
 * comme matière à évaluer en 3ème alors qu'elle n'y est pas enseignée.
 */
final class ExamenBlancMatieresResolver
{
    /** @return array<int, Matiere> indexé par Matiere::getId(), triées par nom */
    public function resoudre(ExamenBlanc $examenBlanc, Niveau $niveau): array
    {
        $selectionnees = [];
        foreach ($examenBlanc->getMatieres() as $matiere) {
            $selectionnees[$matiere->getId()] = true;
        }

        $matieres = [];
        foreach ($niveau->getMatiereNiveaux() as $matiereNiveau) {
            if ((float) $matiereNiveau->getHeuresParSemaine() <= 0.0) {
                continue;
            }

            $matiere = $matiereNiveau->getMatiere();
            if (isset($selectionnees[$matiere->getId()])) {
                $matieres[$matiere->getId()] = $matiere;
            }
        }

        uasort($matieres, static fn (Matiere $a, Matiere $b): int => $a->getNom() <=> $b->getNom());

        return $matieres;
    }
}
