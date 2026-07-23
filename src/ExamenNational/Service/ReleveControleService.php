<?php

declare(strict_types=1);

namespace App\ExamenNational\Service;

use App\ExamenNational\Service\Dto\CandidatExtrait;

/**
 * Contrôle arithmétique d'un candidat extrait : Σ(note × coefficient) sur les épreuves
 * écrites/obligatoires comparée au total de points imprimé sur le relevé
 * (totalPointsEcritesAffiche). Un écart signale une valeur probablement mal lue par l'IA —
 * même principe que le "78/78 relevés validés par contrôle arithmétique" de l'outil de
 * référence ayant inspiré cette fonctionnalité.
 *
 * Deux mises en page officielles coexistent et ce total imprimé n'a pas toujours le même
 * périmètre : sur BAC1/BEPC, les épreuves facultatives ont leur propre tableau avec leur
 * propre total, donc "totalPointsEcritesAffiche" ne couvre que les écrites ; sur BAC2, tout
 * est dans un seul tableau avec un seul total, qui inclut donc aussi les points des
 * facultatives (vérifié sur les relevés réels : les points des facultatives s'additionnent
 * au total écrites pour retomber juste). On teste les deux hypothèses et on retient la
 * meilleure plutôt que de deviner la mise en page — pas besoin de modéliser le barème des
 * points bonus des facultatives pour ça, on réutilise leurs pointsObtenus déjà imprimés.
 */
class ReleveControleService
{
    private const TOLERANCE = 1.0;

    /** @return array{ok: bool, ecart: ?float} */
    public function controler(CandidatExtrait $candidat): array
    {
        if ($candidat->totalPointsEcritesAffiche === null) {
            return ['ok' => false, 'ecart' => null];
        }

        $sommeEcrites = 0.0;
        $sommeFacultatives = 0.0;
        $auMoinsUneLigneComplete = false;

        foreach ($candidat->notes as $note) {
            if ($note->typeEpreuve === 'ecrite' && $note->note !== null && $note->coefficient !== null) {
                $sommeEcrites += $note->note * $note->coefficient;
                $auMoinsUneLigneComplete = true;
            } elseif ($note->typeEpreuve === 'facultative' && $note->pointsObtenus !== null) {
                $sommeFacultatives += $note->pointsObtenus;
            }
        }

        if (!$auMoinsUneLigneComplete) {
            return ['ok' => false, 'ecart' => null];
        }

        $ecartEcritesSeules      = abs($sommeEcrites - $candidat->totalPointsEcritesAffiche);
        $ecartEcritesPlusFacult  = abs($sommeEcrites + $sommeFacultatives - $candidat->totalPointsEcritesAffiche);
        $ecart                   = min($ecartEcritesSeules, $ecartEcritesPlusFacult);

        return ['ok' => $ecart <= self::TOLERANCE, 'ecart' => round($ecart, 2)];
    }
}
