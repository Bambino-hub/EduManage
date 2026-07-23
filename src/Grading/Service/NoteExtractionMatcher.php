<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Grading\Service\Dto\FicheNotesExtraite;
use App\Grading\Service\Dto\LigneAssociee;
use App\Student\Entity\Inscription;

/**
 * Rapproche les noms lus sur la fiche (ex: "ABOUYOU Attiwé Landry") des élèves réellement
 * inscrits dans la classe, par similarité de texte insensible aux accents/casse/ordre
 * nom-prénom. Affectation gloutonne globale (meilleur score d'abord) pour éviter qu'un même
 * élève soit associé à deux lignes. En dessous du seuil, la ligne reste non associée — c'est
 * à l'admin de choisir manuellement sur l'écran de correction.
 */
class NoteExtractionMatcher
{
    private const SEUIL_SCORE = 55;

    /**
     * @param Inscription[] $inscriptionsActives
     * @return LigneAssociee[]
     */
    public function associer(FicheNotesExtraite $fiche, array $inscriptionsActives): array
    {
        $candidatsParInscription = [];
        foreach ($inscriptionsActives as $inscription) {
            $eleve = $inscription->getEleve();
            $candidatsParInscription[$inscription->getId()] = [
                $this->normaliser($eleve->getNom().' '.$eleve->getPrenom()),
                $this->normaliser($eleve->getPrenom().' '.$eleve->getNom()),
            ];
        }

        // Toutes les paires (ligne, inscription) avec leur score, triées du meilleur au pire.
        $paires = [];
        foreach ($fiche->lignes as $indexLigne => $ligne) {
            $nomNormalise = $this->normaliser($ligne->nomExtrait);
            foreach ($candidatsParInscription as $inscriptionId => $candidats) {
                $score = max(
                    $this->similarite($nomNormalise, $candidats[0]),
                    $this->similarite($nomNormalise, $candidats[1]),
                );
                if ($score >= self::SEUIL_SCORE) {
                    $paires[] = [$score, $indexLigne, $inscriptionId];
                }
            }
        }
        usort($paires, static fn (array $a, array $b) => $b[0] <=> $a[0]);

        $inscriptionsParId = [];
        foreach ($inscriptionsActives as $inscription) {
            $inscriptionsParId[$inscription->getId()] = $inscription;
        }

        $ligneAffectee       = [];
        $inscriptionAffectee = [];
        $affectations        = [];
        foreach ($paires as [$score, $indexLigne, $inscriptionId]) {
            if (isset($ligneAffectee[$indexLigne]) || isset($inscriptionAffectee[$inscriptionId])) {
                continue;
            }
            $ligneAffectee[$indexLigne]             = true;
            $inscriptionAffectee[$inscriptionId]    = true;
            $affectations[$indexLigne]              = [$inscriptionsParId[$inscriptionId], $score];
        }

        $resultat = [];
        foreach ($fiche->lignes as $indexLigne => $ligne) {
            [$inscription, $score] = $affectations[$indexLigne] ?? [null, 0];
            $resultat[] = new LigneAssociee(
                nomExtrait: $ligne->nomExtrait,
                eleve: $inscription?->getEleve(),
                score: $score,
                moyInterro: $ligne->moyInterro,
                moyDevoir: $ligne->moyDevoir,
                compos: $ligne->compos,
            );
        }

        return $resultat;
    }

    private function normaliser(string $nom): string
    {
        $translitere = iconv('UTF-8', 'ASCII//TRANSLIT', $nom) ?: $nom;
        $nettoye     = preg_replace('/[^A-Za-z\s]/', ' ', $translitere) ?? $translitere;
        $compact     = preg_replace('/\s+/', ' ', $nettoye) ?? $nettoye;

        return strtoupper(trim($compact));
    }

    private function similarite(string $a, string $b): int
    {
        similar_text($a, $b, $pourcentage);
        return (int) round($pourcentage);
    }
}
