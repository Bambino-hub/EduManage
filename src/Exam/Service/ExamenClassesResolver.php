<?php

declare(strict_types=1);

namespace App\Exam\Service;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\Exam\Entity\Examen;

/**
 * Détermine quelles classes doivent recevoir un surveillant pour quel examen — seule source de
 * vérité partagée par le générateur, la permutation manuelle et l'affichage du tableau.
 *
 * Deux règles s'ajoutent au simple "classe d'un niveau concerné" :
 *
 *  1. **Matière à choix** (`Matiere::groupeOptionnel`, ex. ALL/ESP) : un examen de cette
 *     matière ne concerne que les classes qui l'ont réellement choisie
 *     (`Classe::matieresOptionnelles`). Ex. 2026-2027 : un examen d'ALL sur le niveau 1ère A4
 *     concerne 1ère A42 mais pas 1ère A41 (qui ne fait qu'ESP). Une classe qui n'a encore
 *     choisi AUCUNE matière de ce groupe reste concernée par prudence (option non configurée :
 *     mieux vaut un surveillant de trop qu'une classe oubliée).
 *
 *  2. **Examens parallèles** : quand une même classe passe au même moment deux examens du
 *     même groupe optionnel (ex. 2nde A4 : ALL et ESP à la même heure, chaque élève compose
 *     dans sa langue, dans la même salle), un seul jeu de surveillants suffit. La classe est
 *     alors rattachée à un seul de ces examens (le plus petit id, pour un choix stable), dit
 *     "porteur" ; les autres examens parallèles ne génèrent aucun poste pour elle.
 */
final class ExamenClassesResolver
{
    public function concerne(Examen $examen, Classe $classe): bool
    {
        $niveauIds = array_map(static fn(Niveau $n) => $n->getId(), $examen->getNiveaux()->toArray());
        if (!in_array($classe->getNiveau()?->getId(), $niveauIds, true)) {
            return false;
        }

        $groupe = $examen->getMatiere()?->getGroupeOptionnel();
        if ($groupe === null) {
            return true;
        }

        $optionsDuGroupe = array_filter(
            $classe->getMatieresOptionnelles()->toArray(),
            static fn(Matiere $m) => $m->getGroupeOptionnel() === $groupe,
        );

        return $optionsDuGroupe === []
            || in_array($examen->getMatiere()->getId(), array_map(static fn(Matiere $m) => $m->getId(), $optionsDuGroupe), true);
    }

    /**
     * Répartit les classes entre les examens : pour chaque examen, les classes dont il est le
     * porteur, avec pour chacune la liste des examens parallèles qu'elle passe réellement à ce
     * moment (lui-même inclus, triés par id — sert au libellé "ALL + ESP").
     *
     * Les examens parallèles d'une classe doivent figurer dans `$examens` (toujours le cas :
     * ils partagent un niveau, donc un cycle et une année).
     *
     * @param Examen[] $examens
     * @param Classe[] $classes
     * @return array<int, array<int, array{classe: Classe, examens: Examen[]}>> examenId => classeId => …
     */
    public function repartir(array $examens, array $classes): array
    {
        $examensParClasse = [];
        foreach ($classes as $classe) {
            foreach ($examens as $examen) {
                if ($this->concerne($examen, $classe)) {
                    $examensParClasse[$classe->getId()][] = $examen;
                }
            }
        }

        $repartition = [];
        foreach ($classes as $classe) {
            $siens = $examensParClasse[$classe->getId()] ?? [];

            foreach ($siens as $examen) {
                $paralleles = array_values(array_filter(
                    $siens,
                    fn(Examen $autre) => $autre === $examen || $this->sontParalleles($examen, $autre),
                ));
                usort($paralleles, static fn(Examen $a, Examen $b) => $a->getId() <=> $b->getId());

                if ($paralleles[0] !== $examen) {
                    continue; // un autre examen parallèle porte déjà cette classe
                }

                $repartition[$examen->getId()][$classe->getId()] = ['classe' => $classe, 'examens' => $paralleles];
            }
        }

        return $repartition;
    }

    private function sontParalleles(Examen $a, Examen $b): bool
    {
        $groupe = $a->getMatiere()?->getGroupeOptionnel();

        return $groupe !== null
            && $a->getMatiere() !== $b->getMatiere()
            && $b->getMatiere()?->getGroupeOptionnel() === $groupe
            && $a->chevauche($b);
    }
}
