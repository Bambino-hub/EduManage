<?php

declare(strict_types=1);

namespace App\Exam\Service;

use App\Academic\Entity\Niveau;
use App\Academic\Repository\ClasseRepository;
use App\Exam\Entity\Surveillance;
use App\Exam\Repository\RegroupementSurveillanceRepository;
use App\Exam\Repository\SurveillanceRepository;
use App\Exam\Service\Dto\PermutationResultSurveillance;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique un lot de permutations manuelles (réaffecter une surveillance existante à une autre
 * classe du MÊME examen) proposées depuis le tableau de surveillance — glisser-déposer côté
 * client, cette classe est la seule source de vérité pour valider le lot avant écriture.
 *
 * Portée volontairement restreinte à l'examen d'origine (comme la permutation de l'emploi du
 * temps restreint une séance à sa classe d'origine, en ne faisant varier que le créneau) : les
 * deux enseignants échangés couvraient déjà cet examen exactement au même horaire, donc aucun
 * nouveau conflit de disponibilité n'est possible — seule la cohérence classe/regroupement est
 * à revérifier.
 */
final class SurveillancePermutationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurveillanceRepository $surveillanceRepo,
        private readonly ClasseRepository $classeRepo,
        private readonly RegroupementSurveillanceRepository $regroupementRepo,
    ) {
    }

    /**
     * @param array<int, int> $classeParSurveillanceId nouvelle classe id, par id de surveillance
     */
    public function appliquer(array $classeParSurveillanceId): PermutationResultSurveillance
    {
        if ($classeParSurveillanceId === []) {
            return new PermutationResultSurveillance(false, ['Aucune modification à enregistrer.']);
        }

        $surveillancesParId = [];
        foreach ($this->surveillanceRepo->findByIds(array_keys($classeParSurveillanceId)) as $surveillance) {
            $surveillancesParId[$surveillance->getId()] = $surveillance;
        }

        $classesParId = [];
        foreach ($this->classeRepo->findAll() as $classe) {
            $classesParId[$classe->getId()] = $classe;
        }

        $groupeParClasseId = $this->regroupementRepo->findGroupeParClasseId();

        $erreurs = $this->validerReferences($classeParSurveillanceId, $surveillancesParId, $classesParId, $groupeParClasseId);
        if ($erreurs !== []) {
            return new PermutationResultSurveillance(false, $erreurs);
        }

        $erreurs = $this->validerEtatFinal($classeParSurveillanceId, $surveillancesParId, $classesParId);
        if ($erreurs !== []) {
            return new PermutationResultSurveillance(false, $erreurs);
        }

        foreach ($classeParSurveillanceId as $surveillanceId => $classeId) {
            $surveillancesParId[$surveillanceId]->setClasse($classesParId[$classeId]);
        }
        $this->em->flush();

        return new PermutationResultSurveillance(true);
    }

    /**
     * Erreurs de forme : identifiants inconnus, classe cible hors du périmètre de l'examen
     * d'origine, ou surveillance faisant partie d'un `RegroupementSurveillance` (déplacer une
     * seule des classes réunies casserait leur appariement — refusé).
     *
     * @param array<int, int> $classeParSurveillanceId
     * @param array<int, Surveillance> $surveillancesParId
     * @param array<int, \App\Academic\Entity\Classe> $classesParId
     * @param array<int, int> $groupeParClasseId
     * @return string[]
     */
    private function validerReferences(array $classeParSurveillanceId, array $surveillancesParId, array $classesParId, array $groupeParClasseId): array
    {
        $erreurs = [];

        foreach ($classeParSurveillanceId as $surveillanceId => $classeId) {
            if (!isset($surveillancesParId[$surveillanceId])) {
                $erreurs[] = "Surveillance #{$surveillanceId} introuvable.";
                continue;
            }
            if (!isset($classesParId[$classeId])) {
                $erreurs[] = "Classe #{$classeId} introuvable.";
                continue;
            }

            $surveillance   = $surveillancesParId[$surveillanceId];
            $classeOrigine  = $surveillance->getClasse();

            if (isset($groupeParClasseId[$classeOrigine->getId()])) {
                $erreurs[] = sprintf(
                    '%s (%s) concerne des classes réunies pour la surveillance : elle ne peut pas être déplacée seule.',
                    $surveillance->getEnseignant()->getNomComplet(),
                    $classeOrigine->getNom(),
                );
                continue;
            }

            $niveauIds = array_map(static fn(Niveau $n) => $n->getId(), $surveillance->getExamen()->getNiveaux()->toArray());
            if (!in_array($classesParId[$classeId]->getNiveau()->getId(), $niveauIds, true)) {
                $erreurs[] = sprintf(
                    '%s ne fait pas partie des classes concernées par « %s ».',
                    $classesParId[$classeId]->getNom(),
                    $surveillance->getExamen()->getLabel(),
                );
            }
        }

        return $erreurs;
    }

    /**
     * Valide l'état obtenu une fois TOUT le lot appliqué, examen par examen : un même
     * enseignant ne doit jamais se retrouver deux fois sur le même examen (déjà garanti par le
     * générateur, mais un glisser-déposer maladroit — reposer la même personne sur 2 classes —
     * doit être refusé plutôt que silencieusement accepté).
     *
     * @param array<int, int> $classeParSurveillanceId
     * @param array<int, Surveillance> $surveillancesParId
     * @param array<int, \App\Academic\Entity\Classe> $classesParId
     * @return string[]
     */
    private function validerEtatFinal(array $classeParSurveillanceId, array $surveillancesParId, array $classesParId): array
    {
        $erreurs = [];

        $examenIds = array_unique(array_map(
            static fn(Surveillance $s) => $s->getExamen()->getId(),
            $surveillancesParId,
        ));

        foreach ($examenIds as $examenId) {
            $classeFinaleParEnseignant = [];

            foreach ($this->surveillanceRepo->findByExamens([$examenId]) as $surveillance) {
                $classeFinale = $classeParSurveillanceId[$surveillance->getId()] ?? $surveillance->getClasse()->getId();
                $enseignantId = $surveillance->getEnseignant()->getId();

                if (isset($classeFinaleParEnseignant[$enseignantId]) && $classeFinaleParEnseignant[$enseignantId] !== $classeFinale) {
                    $erreurs[] = sprintf(
                        '%s se retrouverait sur deux classes différentes pour le même examen.',
                        $surveillance->getEnseignant()->getNomComplet(),
                    );
                }
                $classeFinaleParEnseignant[$enseignantId] = $classeFinale;
            }
        }

        return array_values(array_unique($erreurs));
    }
}
