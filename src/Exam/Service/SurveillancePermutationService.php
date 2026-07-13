<?php

declare(strict_types=1);

namespace App\Exam\Service;

use App\Academic\Entity\Niveau;
use App\Academic\Repository\ClasseRepository;
use App\Exam\Entity\Examen;
use App\Exam\Entity\Surveillance;
use App\Exam\Repository\ExamenRepository;
use App\Exam\Repository\RegroupementSurveillanceRepository;
use App\Exam\Repository\SurveillanceRepository;
use App\Exam\Service\Dto\PermutationResultSurveillance;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique un lot de permutations manuelles (réaffecter une surveillance existante vers une
 * autre classe, éventuellement d'un AUTRE examen) proposées depuis le tableau de surveillance —
 * glisser-déposer côté client, cette classe est la seule source de vérité pour valider le lot
 * avant écriture.
 *
 * Contrairement à un déplacement au sein du même examen (où les deux classes passent l'examen
 * exactement au même horaire, donc aucun nouveau conflit n'est possible), un déplacement vers un
 * autre examen peut introduire un chevauchement inédit avec une AUTRE surveillance déjà
 * existante. Cette disponibilité est donc revérifiée ici exactement comme le fait
 * ExamenSurveillanceGenerator (`Examen::chevauche()`), en plus de la cohérence
 * classe/regroupement/examen. Les cours normaux sont suspendus pendant les examens (confirmé
 * par l'utilisateur le 2026-07-13) — aucune vérification de grille Creneau n'est donc nécessaire
 * ici, comme dans le générateur automatique.
 */
final class SurveillancePermutationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurveillanceRepository $surveillanceRepo,
        private readonly ExamenRepository $examenRepo,
        private readonly ClasseRepository $classeRepo,
        private readonly RegroupementSurveillanceRepository $regroupementRepo,
    ) {
    }

    /**
     * @param array<int, array{examenId: int, classeId: int}> $cibleParSurveillanceId nouvelle cible (examen, classe), par id de surveillance
     */
    public function appliquer(array $cibleParSurveillanceId): PermutationResultSurveillance
    {
        if ($cibleParSurveillanceId === []) {
            return new PermutationResultSurveillance(false, ['Aucune modification à enregistrer.']);
        }

        $surveillancesParId = [];
        foreach ($this->surveillanceRepo->findByIds(array_keys($cibleParSurveillanceId)) as $surveillance) {
            $surveillancesParId[$surveillance->getId()] = $surveillance;
        }

        $classesParId = [];
        foreach ($this->classeRepo->findAll() as $classe) {
            $classesParId[$classe->getId()] = $classe;
        }

        $examenIds = array_unique(array_merge(
            array_map(static fn(array $c) => $c['examenId'], $cibleParSurveillanceId),
            array_map(static fn(Surveillance $s) => $s->getExamen()->getId(), $surveillancesParId),
        ));
        $examensParId = [];
        foreach ($this->examenRepo->findByIds($examenIds) as $examen) {
            $examensParId[$examen->getId()] = $examen;
        }

        $groupeParClasseId = $this->regroupementRepo->findGroupeParClasseId();

        $erreurs = $this->validerReferences($cibleParSurveillanceId, $surveillancesParId, $classesParId, $examensParId, $groupeParClasseId);
        if ($erreurs !== []) {
            return new PermutationResultSurveillance(false, $erreurs);
        }

        $erreurs = $this->validerEtatFinal($cibleParSurveillanceId, $surveillancesParId, $examenIds, $groupeParClasseId);
        if ($erreurs !== []) {
            return new PermutationResultSurveillance(false, $erreurs);
        }

        $erreurs = $this->validerDisponibilite($cibleParSurveillanceId, $surveillancesParId, $examensParId);
        if ($erreurs !== []) {
            return new PermutationResultSurveillance(false, $erreurs);
        }

        foreach ($cibleParSurveillanceId as $surveillanceId => $cible) {
            $surveillancesParId[$surveillanceId]->setExamen($examensParId[$cible['examenId']]);
            $surveillancesParId[$surveillanceId]->setClasse($classesParId[$cible['classeId']]);
        }
        $this->em->flush();

        return new PermutationResultSurveillance(true);
    }

    /**
     * Erreurs de forme : identifiants inconnus, classe cible hors du périmètre de l'examen
     * cible, ou classe d'origine/de destination faisant partie d'un `RegroupementSurveillance`
     * (déplacer une seule des classes réunies casserait leur appariement — refusé dans les deux
     * sens).
     *
     * @param array<int, array{examenId: int, classeId: int}> $cibleParSurveillanceId
     * @param array<int, Surveillance> $surveillancesParId
     * @param array<int, \App\Academic\Entity\Classe> $classesParId
     * @param array<int, Examen> $examensParId
     * @param array<int, int> $groupeParClasseId
     * @return string[]
     */
    private function validerReferences(array $cibleParSurveillanceId, array $surveillancesParId, array $classesParId, array $examensParId, array $groupeParClasseId): array
    {
        $erreurs = [];

        foreach ($cibleParSurveillanceId as $surveillanceId => $cible) {
            if (!isset($surveillancesParId[$surveillanceId])) {
                $erreurs[] = "Surveillance #{$surveillanceId} introuvable.";
                continue;
            }
            if (!isset($classesParId[$cible['classeId']])) {
                $erreurs[] = "Classe #{$cible['classeId']} introuvable.";
                continue;
            }
            if (!isset($examensParId[$cible['examenId']])) {
                $erreurs[] = "Examen #{$cible['examenId']} introuvable.";
                continue;
            }

            $surveillance  = $surveillancesParId[$surveillanceId];
            $classeOrigine = $surveillance->getClasse();
            $classeCible   = $classesParId[$cible['classeId']];
            $examenCible   = $examensParId[$cible['examenId']];

            if (isset($groupeParClasseId[$classeOrigine->getId()])) {
                $erreurs[] = sprintf(
                    '%s (%s) concerne des classes réunies pour la surveillance : elle ne peut pas être déplacée seule.',
                    $surveillance->getEnseignant()->getNomComplet(),
                    $classeOrigine->getNom(),
                );
                continue;
            }

            if (isset($groupeParClasseId[$classeCible->getId()])) {
                $erreurs[] = sprintf(
                    '%s concerne des classes réunies pour la surveillance : impossible d\'y déplacer un seul surveillant.',
                    $classeCible->getNom(),
                );
                continue;
            }

            $niveauIds = array_map(static fn(Niveau $n) => $n->getId(), $examenCible->getNiveaux()->toArray());
            if (!in_array($classeCible->getNiveau()->getId(), $niveauIds, true)) {
                $erreurs[] = sprintf(
                    '%s ne fait pas partie des classes concernées par « %s ».',
                    $classeCible->getNom(),
                    $examenCible->getLabel(),
                );
            }
        }

        return $erreurs;
    }

    /**
     * Valide l'état obtenu une fois TOUT le lot appliqué, examen par examen (d'origine ou de
     * destination) : un même enseignant ne doit jamais se retrouver deux fois sur le même
     * examen sur des classes NON réunies (déjà garanti par le générateur, mais un
     * glisser-déposer maladroit doit être refusé plutôt que silencieusement accepté). Les
     * classes d'un même `RegroupementSurveillance` (ex. 1ère C + 1ère D1) partagent
     * intentionnellement le même surveillant — normalisées via `$groupeParClasseId` pour ne pas
     * remonter de faux positif sur des affectations préexistantes et légitimes.
     *
     * @param array<int, array{examenId: int, classeId: int}> $cibleParSurveillanceId
     * @param array<int, Surveillance> $surveillancesParId
     * @param int[] $examenIds
     * @param array<int, int> $groupeParClasseId
     * @return string[]
     */
    private function validerEtatFinal(array $cibleParSurveillanceId, array $surveillancesParId, array $examenIds, array $groupeParClasseId): array
    {
        $erreurs = [];

        $slot = static fn(int $classeId): string => isset($groupeParClasseId[$classeId]) ? 'groupe:'.$groupeParClasseId[$classeId] : 'classe:'.$classeId;

        // État final de chaque surveillance concernée (touchée par le lot, ou simple témoin
        // déjà présent sur un examen d'origine/de destination touché par le lot).
        $finales = [];
        foreach ($this->surveillanceRepo->findByExamens($examenIds) as $surveillance) {
            $id    = $surveillance->getId();
            $cible = $cibleParSurveillanceId[$id] ?? null;
            $finales[$id] = [
                'examenId'     => $cible['examenId'] ?? $surveillance->getExamen()->getId(),
                'classeId'     => $cible['classeId'] ?? $surveillance->getClasse()->getId(),
                'enseignantId' => $surveillance->getEnseignant()->getId(),
                'nomEnseignant'=> $surveillance->getEnseignant()->getNomComplet(),
            ];
        }
        foreach ($cibleParSurveillanceId as $id => $cible) {
            if (isset($finales[$id]) || !isset($surveillancesParId[$id])) {
                continue;
            }
            $finales[$id] = [
                'examenId'      => $cible['examenId'],
                'classeId'      => $cible['classeId'],
                'enseignantId'  => $surveillancesParId[$id]->getEnseignant()->getId(),
                'nomEnseignant' => $surveillancesParId[$id]->getEnseignant()->getNomComplet(),
            ];
        }

        $parExamen = [];
        foreach ($finales as $f) {
            $parExamen[$f['examenId']][] = $f;
        }

        foreach ($parExamen as $lignes) {
            $slotFinalParEnseignant = [];
            foreach ($lignes as $ligne) {
                $enseignantId = $ligne['enseignantId'];
                $slotFinal    = $slot($ligne['classeId']);
                if (isset($slotFinalParEnseignant[$enseignantId]) && $slotFinalParEnseignant[$enseignantId] !== $slotFinal) {
                    $erreurs[] = sprintf('%s se retrouverait sur deux classes différentes pour le même examen.', $ligne['nomEnseignant']);
                }
                $slotFinalParEnseignant[$enseignantId] = $slotFinal;
            }
        }

        return array_values(array_unique($erreurs));
    }

    /**
     * Pour toute surveillance déplacée vers un AUTRE examen que le sien (donc potentiellement un
     * horaire différent), revérifie que l'enseignant n'a pas déjà une autre surveillance
     * (existante ou déplacée dans le même lot) qui chevauche ce nouvel horaire. Un déplacement au
     * sein du même examen n'est jamais concerné (même horaire par construction, comme avant).
     *
     * @param array<int, array{examenId: int, classeId: int}> $cibleParSurveillanceId
     * @param array<int, Surveillance> $surveillancesParId
     * @param array<int, Examen> $examensParId
     * @return string[]
     */
    private function validerDisponibilite(array $cibleParSurveillanceId, array $surveillancesParId, array $examensParId): array
    {
        $erreurs = [];

        $deplacements = array_filter(
            $cibleParSurveillanceId,
            static fn(array $cible, int $id) => $surveillancesParId[$id]->getExamen()->getId() !== $cible['examenId'],
            ARRAY_FILTER_USE_BOTH,
        );
        if ($deplacements === []) {
            return [];
        }

        $enseignantIds = array_values(array_unique(array_map(
            static fn(int $id) => $surveillancesParId[$id]->getEnseignant()->getId(),
            array_keys($deplacements),
        )));

        $autresSurveillancesParEnseignant = [];
        foreach ($this->surveillanceRepo->findByEnseignants($enseignantIds) as $s) {
            if (isset($cibleParSurveillanceId[$s->getId()])) {
                continue; // remplacée par sa nouvelle cible, gérée via $deplacements ci-dessous
            }
            $autresSurveillancesParEnseignant[$s->getEnseignant()->getId()][] = $s->getExamen();
        }

        $ciblesParEnseignant = [];
        foreach ($deplacements as $id => $cible) {
            $enseignantId = $surveillancesParId[$id]->getEnseignant()->getId();
            $ciblesParEnseignant[$enseignantId][] = $examensParId[$cible['examenId']];
        }

        foreach ($deplacements as $id => $cible) {
            $enseignant  = $surveillancesParId[$id]->getEnseignant();
            $examenCible = $examensParId[$cible['examenId']];

            $conflit = false;
            foreach ($autresSurveillancesParEnseignant[$enseignant->getId()] ?? [] as $autreExamen) {
                if ($examenCible->chevauche($autreExamen)) {
                    $erreurs[] = sprintf('%s surveille déjà « %s » au même horaire.', $enseignant->getNomComplet(), $autreExamen->getLabel());
                    $conflit = true;
                    break;
                }
            }
            if ($conflit) {
                continue;
            }

            foreach ($ciblesParEnseignant[$enseignant->getId()] ?? [] as $autreCible) {
                if ($autreCible !== $examenCible && $autreCible->chevauche($examenCible)) {
                    $erreurs[] = sprintf('%s se retrouverait sur deux examens qui se chevauchent.', $enseignant->getNomComplet());
                    break;
                }
            }
        }

        return array_values(array_unique($erreurs));
    }
}
