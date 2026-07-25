<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Student\Entity\Inscription;

/**
 * Rapproche des noms lus sur une fiche papier (ex: "ABOUYOU Attiwé Landry") des élèves
 * réellement inscrits, par similarité de texte insensible aux accents/casse/ordre
 * nom-prénom. Affectation gloutonne globale (meilleur score d'abord) pour éviter qu'un même
 * élève soit associé à deux lignes. En dessous du seuil, la ligne reste non associée — c'est
 * à l'admin de choisir manuellement sur l'écran de correction.
 *
 * Algorithme partagé par {@see NoteExtractionMatcher} (fiche bulletin) et
 * ExamenBlanc\Service\ExamenBlancNoteMatcher (fiche examen blanc) — extrait ici pour ne pas
 * dupliquer le rapprochement de noms, identique dans les deux cas.
 */
final class NomEleveMatcher
{
    private const SEUIL_SCORE = 55;

    /**
     * @param array<int, string> $nomsExtraits texte brut lu sur la fiche, indexé par ligne
     * @param Inscription[] $inscriptionsActives
     * @return array<int, array{0: ?Inscription, 1: int}> même clés que $nomsExtraits — [inscription rapprochée ou null, score]
     */
    public function associer(array $nomsExtraits, array $inscriptionsActives): array
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
        foreach ($nomsExtraits as $indexLigne => $nomExtrait) {
            $nomNormalise = $this->normaliser($nomExtrait);
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
            $ligneAffectee[$indexLigne]          = true;
            $inscriptionAffectee[$inscriptionId] = true;
            $affectations[$indexLigne]           = [$inscriptionsParId[$inscriptionId], $score];
        }

        $resultat = [];
        foreach ($nomsExtraits as $indexLigne => $nomExtrait) {
            $resultat[$indexLigne] = $affectations[$indexLigne] ?? [null, 0];
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
