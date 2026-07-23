<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Grading\Entity\Evaluation;
use App\Grading\Entity\Trimestre;
use App\Grading\Enum\TypeEvaluation;
use App\Grading\Repository\EvaluationRepository;
use App\Scheduling\Entity\Attribution;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Garantit la structure de la fiche de notes en ligne d'une attribution/trimestre : le
 * nombre de colonnes Interrogation/Devoir est fixé une fois pour toutes par l'admin sur
 * le Trimestre (Trimestre::nbInterrogations/nbDevoirs, voir TrimestreType) — applicable
 * d'un coup à toutes les matières, pas de gestion colonne par colonne par attribution.
 * La colonne Composition reste toujours unique (une seule note de composition par
 * trimestre en pratique).
 *
 * Complète les colonnes manquantes (jamais n'en supprime : réduire le nombre configuré
 * ne détruit pas les colonnes déjà en place ailleurs, par sécurité pour les notes déjà
 * saisies). Appelé aussi bien par la fiche admin que par la fiche enseignant — l'un ou
 * l'autre peut être le premier à ouvrir une attribution donnée ce trimestre.
 */
class FicheNotesService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EvaluationRepository $evaluationRepo,
    ) {
    }

    public function assurerColonnes(Attribution $attribution, Trimestre $trimestre): void
    {
        $existantes = $this->evaluationRepo->findByAttributionEtTrimestre($attribution, $trimestre);

        $this->completer($attribution, $trimestre, $existantes, TypeEvaluation::INTERROGATION, $trimestre->getNbInterrogations());
        $this->completer($attribution, $trimestre, $existantes, TypeEvaluation::DEVOIR, $trimestre->getNbDevoirs());
        $this->completer($attribution, $trimestre, $existantes, TypeEvaluation::COMPOSITION, 1);
    }

    /** @param Evaluation[] $existantes */
    private function completer(Attribution $attribution, Trimestre $trimestre, array $existantes, TypeEvaluation $type, int $cible): void
    {
        $count = count(array_filter($existantes, static fn (Evaluation $e): bool => $e->getType() === $type));

        for ($numero = $count + 1; $numero <= $cible; $numero++) {
            $evaluation = new Evaluation();
            $evaluation->setAttribution($attribution);
            $evaluation->setTrimestre($trimestre);
            $evaluation->setType($type);
            $evaluation->setTitre($type->label().' '.$numero);
            $evaluation->setDate(new \DateTimeImmutable());
            $this->em->persist($evaluation);
        }
    }
}
