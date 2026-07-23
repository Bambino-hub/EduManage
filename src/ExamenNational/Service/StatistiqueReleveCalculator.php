<?php

declare(strict_types=1);

namespace App\ExamenNational\Service;

use App\ExamenNational\Entity\NoteMatiereCandidat;
use App\ExamenNational\Service\Dto\StatistiqueMatiere;

/**
 * Regroupe les notes d'une session par matière et calcule N/min/max/répartition par bande —
 * le tableau attendu par l'admin (voir capture de référence). Pas de FK vers Matiere : le
 * regroupement se fait par similarité de texte (accents/casse/petite faute de lecture IA,
 * ex. "Morale" lu "Moralés" sur une page), pas par égalité stricte — sur un gros relevé
 * (100+ pages), une lecture légèrement différente d'une même matière sur une seule page est
 * courante et ne doit pas se retrouver isolée dans sa propre ligne à N=1. Comparaison
 * uniquement entre matières de même type (écrite/facultative), jamais entre les deux. Un
 * candidat non concerné par une matière (note null, case "-") n'entre pas dans N.
 */
class StatistiqueReleveCalculator
{
    private const SEUIL_SIMILARITE = 85;

    /**
     * @param NoteMatiereCandidat[] $notes
     * @return StatistiqueMatiere[] écrites d'abord (dans l'ordre de première apparition), puis facultatives
     */
    public function calculer(array $notes): array
    {
        $groupes = [];
        foreach ($notes as $note) {
            if ($note->getNote() === null) {
                continue;
            }

            $type      = $note->getTypeEpreuve()->value;
            $normalise = $this->normaliser($note->getMatiereLibelle());

            $indexGroupe = $this->trouverGroupeProche($groupes, $type, $normalise);
            if ($indexGroupe === null) {
                $groupes[] = ['type' => $type, 'normalise' => $normalise, 'libelles' => [], 'valeurs' => []];
                $indexGroupe = array_key_last($groupes);
            }

            $groupes[$indexGroupe]['libelles'][] = $note->getMatiereLibelle();
            $groupes[$indexGroupe]['valeurs'][]   = (float) $note->getNote();
        }

        $resultat = [];
        foreach ($groupes as $groupe) {
            $valeurs = $groupe['valeurs'];
            $resultat[] = new StatistiqueMatiere(
                libelle: $this->libelleLePlusFrequent($groupe['libelles']),
                typeEpreuve: $groupe['type'],
                n: count($valeurs),
                min: min($valeurs),
                max: max($valeurs),
                bande0a6: count(array_filter($valeurs, static fn(float $v): bool => $v < 6)),
                bande6a10: count(array_filter($valeurs, static fn(float $v): bool => $v >= 6 && $v < 10)),
                bande10a15: count(array_filter($valeurs, static fn(float $v): bool => $v >= 10 && $v < 15)),
                bande15a20: count(array_filter($valeurs, static fn(float $v): bool => $v >= 15)),
            );
        }

        usort($resultat, static fn(StatistiqueMatiere $a, StatistiqueMatiere $b): int => $a->typeEpreuve <=> $b->typeEpreuve);

        return $resultat;
    }

    /** @param array<int, array{type: string, normalise: string, libelles: string[], valeurs: float[]}> $groupes */
    private function trouverGroupeProche(array $groupes, string $type, string $normalise): ?int
    {
        foreach ($groupes as $index => $groupe) {
            if ($groupe['type'] !== $type) {
                continue;
            }
            similar_text($normalise, $groupe['normalise'], $pourcentage);
            if ($pourcentage >= self::SEUIL_SIMILARITE) {
                return $index;
            }
        }

        return null;
    }

    /** @param string[] $libelles */
    private function libelleLePlusFrequent(array $libelles): string
    {
        $comptes = array_count_values($libelles);
        arsort($comptes);
        return array_key_first($comptes);
    }

    private function normaliser(string $matiere): string
    {
        $translitere = iconv('UTF-8', 'ASCII//TRANSLIT', $matiere) ?: $matiere;
        $nettoye     = preg_replace('/[^A-Za-z0-9\s]/', ' ', $translitere) ?? $translitere;
        $compact     = preg_replace('/\s+/', ' ', $nettoye) ?? $nettoye;

        return strtoupper(trim($compact));
    }
}
