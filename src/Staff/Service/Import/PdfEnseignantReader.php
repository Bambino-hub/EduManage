<?php

declare(strict_types=1);

namespace App\Staff\Service\Import;

use App\Staff\Enum\TypePersonnel;
use Smalot\PdfParser\Parser;

/**
 * Lecture "meilleur effort" d'un PDF au format "liste du personnel". Contrairement
 * au Word/Excel, un PDF n'a pas de vraies cellules : le texte est reconstitué selon
 * la position visuelle, ce qui casse les colonnes de façon imprévisible (deux champs
 * sur une même ligne, un champ étalé sur plusieurs lignes, parfois un numéro de
 * téléphone collé au champ précédent sans saut de ligne). Cette lecture est donc
 * nettement moins fiable que Docx/Xlsx — la relecture manuelle en écran de
 * prévisualisation est indispensable avant de confirmer l'import.
 *
 * Heuristique (calée sur la sortie réelle de smalot/pdfparser sur le document
 * "Liste des enseignants 2025-2026", pas une supposition théorique) : le numéro
 * de téléphone (8-10 chiffres consécutifs) est le seul repère fiable pour délimiter
 * une fiche, qu'il soit seul sur sa ligne ou collé à d'autres champs. Le texte entier
 * est donc découpé sur ce motif plutôt que ligne par ligne. Le numéro d'ordre (N°)
 * en tête de fiche est ignoré. Les mentions "AUTRES" / "LISTE DES ENSEIGNANTS
 * EXTERNES" font basculer le statut appliqué aux fiches suivantes.
 */
class PdfEnseignantReader implements EnseignantFileReaderInterface
{
    public function __construct(
        private readonly EnseignantValeurNormalizer $normalizer,
    ) {
    }

    public function lire(string $cheminFichier): array
    {
        $parser = new Parser();
        $texte  = $parser->parseFile($cheminFichier)->getText();
        $texte  = $this->ignorerEnTeteDocument($texte);

        $morceaux = preg_split('/(\d{8,10})/u', $texte, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $lignesRes   = [];
        $typeCourant = TypePersonnel::INTERNE;

        for ($i = 0; $i + 1 < count($morceaux); $i += 2) {
            $segment   = $morceaux[$i];
            $telephone = $morceaux[$i + 1];

            [$segment, $typeCourant] = $this->extraireChangementSection($segment, $typeCourant);

            $ligne = $this->interpreterSegment($segment, $telephone, $typeCourant);
            if ($ligne !== null) {
                $lignesRes[] = $ligne;
            }
        }

        return $lignesRes;
    }

    private function ignorerEnTeteDocument(string $texte): string
    {
        if (preg_match('/CYCLE\s*\n?\s*CONTACT/iu', $texte, $m, PREG_OFFSET_CAPTURE)) {
            return substr($texte, $m[0][1] + strlen($m[0][0]));
        }

        return $texte;
    }

    /** @return array{0: string, 1: TypePersonnel} */
    private function extraireChangementSection(string $segment, TypePersonnel $typeCourant): array
    {
        if (preg_match('/^(.*)\bAUTRES\b(.*)$/isu', $segment, $m)) {
            return [$m[2], TypePersonnel::AUTRE];
        }
        if (preg_match('/^(.*)LISTE\s+DES\s+ENSEIGNANTS\s+EXTERNES\s*(.*)$/isu', $segment, $m)) {
            return [$m[2], TypePersonnel::EXTERNE];
        }

        return [$segment, $typeCourant];
    }

    /**
     * @return array{nom: string, prenom: string, sexe: ?\App\Staff\Enum\Sexe, matricule: ?string,
     *     poste: ?string, specialite: ?string, cycle: ?string, telephone: string, type: TypePersonnel}|null
     */
    private function interpreterSegment(string $segment, string $telephone, TypePersonnel $type): ?array
    {
        $texte = trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment);
        // Numéro d'ordre (N°) en tête de fiche, isolé par des espaces.
        $texte = trim(preg_replace('/^\d{1,2}\s+/', '', $texte) ?? $texte);
        if ($texte === '') {
            return null;
        }

        // Titres religieux ("Sr M.", "Sr.M.") en tête : à retirer avant de chercher le sexe,
        // sinon le "M" du titre est pris à tort pour le sexe.
        $texte = preg_replace('/^Sr\.?\s*M\.?\s+/iu', '', $texte) ?? $texte;

        if (!preg_match('/^(.*?)\b([MF])\b(.*)$/u', $texte, $m)) {
            $split = $this->normalizer->splitNomPrenom($texte);

            return [
                'nom' => $split['nom'], 'prenom' => $split['prenom'], 'sexe' => null,
                'matricule' => null, 'poste' => null, 'specialite' => null, 'cycle' => null,
                'telephone' => $telephone, 'type' => $type,
            ];
        }

        $nomBrut = trim($m[1]);
        $sexe    = $this->normalizer->normaliserSexe($m[2]);
        $reste   = trim($m[3]);

        $matricule = null;
        if (preg_match('/^(privé|prive)\b\s*/iu', $reste, $mm)) {
            $reste = trim(substr($reste, strlen($mm[0])));
        } elseif (preg_match('/^fonctionnaire\s+externes?\b\s*/iu', $reste, $mm)) {
            $reste = trim(substr($reste, strlen($mm[0])));
        } elseif (preg_match('/^([A-Z0-9][A-Z0-9\-]{3,12})\s+/u', $reste, $mm)) {
            $matricule = $this->normalizer->normaliserMatricule($mm[1]);
            $reste     = trim(substr($reste, strlen($mm[0])));
        }

        $cycle = null;
        if (preg_match('/(\d(?:\s*\/\s*\d)?)\s*$/u', $reste, $mc)) {
            $cycle = $this->normalizer->normaliserCycle($mc[1]);
            $reste = trim(substr($reste, 0, -strlen($mc[0])));
        }
        $reste = trim(preg_replace('/-\s*$/', '', $reste) ?? $reste);

        $poste      = null;
        $specialite = null;
        // Comparaison sensible à la casse : "Enseignant" est parfois collé sans espace au
        // champ suivant (artefact d'extraction PDF, ex. "EnseignantESPAGNOL") — une comparaison
        // insensible à la casse mangerait le "E" du mot suivant.
        if (preg_match('/^(Enseignante|Enseignant)(\s*\/?\s*Surveillant)?\s*(.*)$/u', $reste, $mp)) {
            $poste      = trim($mp[1].($mp[2] ?? ''));
            $specialite = trim($mp[3]) ?: null;
        } elseif ($reste !== '') {
            $poste = $reste;
        }

        $split = $this->normalizer->splitNomPrenom($nomBrut);

        return [
            'nom'        => $split['nom'],
            'prenom'     => $split['prenom'],
            'sexe'       => $sexe,
            'matricule'  => $matricule,
            'poste'      => $poste,
            'specialite' => $specialite,
            'cycle'      => $cycle,
            'telephone'  => $telephone,
            'type'       => $type,
        ];
    }
}
