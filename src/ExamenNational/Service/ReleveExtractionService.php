<?php

declare(strict_types=1);

namespace App\ExamenNational\Service;

use App\ExamenNational\Service\Dto\CandidatExtrait;
use App\ExamenNational\Service\Dto\NoteExtraite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lecture d'un lot de quelques pages d'un relevé officiel d'examen (BEPC/BAC), via Gemini
 * (vision) — même principe que NoteExtractionService (module Grading) pour les fiches de
 * notes de classe, mais une page = un candidat ici, et on lit tout le tableau de notes
 * (matière/note/coef/points) plutôt que des moyennes déjà agrégées. Le résultat passe par un
 * contrôle arithmétique automatique (voir ReleveControleService) puis une vérification
 * humaine avant tout enregistrement définitif.
 */
class ReleveExtractionService
{
    private const MODEL = 'gemini-3.1-flash-lite';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.':generateContent';

    private const PROMPT = <<<'PROMPT'
        Tu lis une ou plusieurs pages d'un relevé de notes officiel d'examen togolais (BEPC, BAC 1ère partie ou BAC 2ème partie). Chaque page correspond à UN SEUL candidat.

        Pour CHAQUE page, extrais :
        - nom (nom de famille du candidat)
        - prenoms
        - sexe ("M" ou "F")
        - dateNaissance (format JJ/MM/AAAA)
        - lieuNaissance
        - numeroJury
        - numeroTable
        - serie (juste le code, ex "D", "A4", "B" — sans le libellé qui peut suivre)
        - libelleSerie (le texte qui suit la série s'il y en a un, ex "MATHEMATIQUES ET SCIENCES DE LA NATURE" — null sinon)
        - session (ex "JUIN-2026" ou "2026")
        - centreExamen
        - decisionJury (texte tel qu'écrit, ex "ADMIS(E) AVEC MENTION ASSEZ BIEN")
        - moyenneGlobaleAffichee (la moyenne générale imprimée sur la page, sur 20)
        - totalPointsEcritesAffiche (le total de points imprimé en bas du tableau des épreuves écrites/obligatoires uniquement, pas celui des facultatives)
        - notes : une entrée par ligne de matière, dans la table des épreuves écrites/obligatoires ET dans la table des épreuves facultatives si elle existe. Pour chaque ligne :
          - typeEpreuve : "ecrite" ou "facultative" selon la table d'où vient la ligne
          - matiere : le nom de la matière tel qu'écrit
          - note : la note sur 20 (null si la case est vide ou marquée "-" — candidat non concerné par cette matière)
          - coefficient : le coefficient (null si vide)
          - pointsObtenus : les points obtenus imprimés sur cette ligne (null si vide)

        Ne devine jamais une valeur illisible : mets null plutôt que d'inventer. Les nombres peuvent utiliser la virgule ou le point comme séparateur décimal.

        Réponds UNIQUEMENT en JSON strict, sans texte autour, avec ce format exact :
        {
          "candidats": [
            {
              "nom": "...", "prenoms": "...", "sexe": "...", "dateNaissance": "...", "lieuNaissance": "...",
              "numeroJury": "...", "numeroTable": "...", "serie": "...", "libelleSerie": "...", "session": "...",
              "centreExamen": "...", "decisionJury": "...", "moyenneGlobaleAffichee": ..., "totalPointsEcritesAffiche": ...,
              "notes": [
                {"typeEpreuve": "ecrite", "matiere": "...", "note": ..., "coefficient": ..., "pointsObtenus": ...}
              ]
            }
          ]
        }
        PROMPT;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
    ) {
    }

    /** @return CandidatExtrait[] */
    public function extraire(string $cheminPdf): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Clé API Gemini absente (GEMINI_API_KEY) — configurez-la dans .env.local.');
        }

        $donnees = base64_encode(file_get_contents($cheminPdf));

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => [
                    'contents' => [[
                        'parts' => [
                            ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $donnees]],
                            ['text' => self::PROMPT],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature'      => 0,
                        'responseMimeType' => 'application/json',
                    ],
                ],
                'timeout' => 90,
            ]);

            $payload = $response->toArray();
        } catch (HttpClientExceptionInterface $e) {
            throw new \RuntimeException('Échec de l\'appel à Gemini : '.$e->getMessage(), previous: $e);
        }

        $texte = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($texte === null) {
            throw new \RuntimeException('Réponse Gemini inexploitable (aucun contenu retourné).');
        }

        $donneesJson = json_decode($texte, true);
        if (!is_array($donneesJson)) {
            throw new \RuntimeException('Réponse Gemini non conforme (JSON invalide).');
        }

        $candidats = [];
        foreach ($donneesJson['candidats'] ?? [] as $candidat) {
            $nom = trim((string) ($candidat['nom'] ?? ''));
            if ($nom === '') {
                continue;
            }

            $notes = [];
            foreach ($candidat['notes'] ?? [] as $ligne) {
                $matiere = trim((string) ($ligne['matiere'] ?? ''));
                if ($matiere === '') {
                    continue;
                }
                $typeEpreuve = ((string) ($ligne['typeEpreuve'] ?? '')) === 'facultative' ? 'facultative' : 'ecrite';
                $notes[] = new NoteExtraite(
                    typeEpreuve: $typeEpreuve,
                    matiere: $matiere,
                    note: $this->versFloatNote($ligne['note'] ?? null),
                    coefficient: $this->versFloatLibre($ligne['coefficient'] ?? null),
                    pointsObtenus: $this->versFloatLibre($ligne['pointsObtenus'] ?? null),
                );
            }

            $candidats[] = new CandidatExtrait(
                nom: $nom,
                prenoms: trim((string) ($candidat['prenoms'] ?? '')),
                sexe: $this->videVersNull($candidat['sexe'] ?? null),
                dateNaissance: $this->videVersNull($candidat['dateNaissance'] ?? null),
                lieuNaissance: $this->videVersNull($candidat['lieuNaissance'] ?? null),
                numeroJury: $this->videVersNull($candidat['numeroJury'] ?? null),
                numeroTable: $this->videVersNull($candidat['numeroTable'] ?? null),
                serie: $this->videVersNull($candidat['serie'] ?? null),
                libelleSerie: $this->videVersNull($candidat['libelleSerie'] ?? null),
                session: $this->videVersNull($candidat['session'] ?? null),
                centreExamen: $this->videVersNull($candidat['centreExamen'] ?? null),
                decisionJury: $this->videVersNull($candidat['decisionJury'] ?? null),
                moyenneGlobaleAffichee: $this->versFloatNote($candidat['moyenneGlobaleAffichee'] ?? null),
                totalPointsEcritesAffiche: $this->versFloatLibre($candidat['totalPointsEcritesAffiche'] ?? null),
                notes: $notes,
            );
        }

        return $candidats;
    }

    /** Note sur 20 : bornée [0, 20]. */
    private function versFloatNote(mixed $valeur): ?float
    {
        $valeur = $this->versFloatLibre($valeur);
        return $valeur !== null ? max(0.0, min(20.0, $valeur)) : null;
    }

    /** Coefficient/points/total : pas de borne fixe (un total peut dépasser 300). */
    private function versFloatLibre(mixed $valeur): ?float
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (is_string($valeur)) {
            $valeur = str_replace(',', '.', $valeur);
        }
        return is_numeric($valeur) ? (float) $valeur : null;
    }

    private function videVersNull(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        return $valeur !== '' ? $valeur : null;
    }
}
