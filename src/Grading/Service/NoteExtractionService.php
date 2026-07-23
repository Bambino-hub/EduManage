<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Grading\Service\Dto\FicheNotesExtraite;
use App\Grading\Service\Dto\LigneEleveExtraite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lecture d'une fiche de notes papier (scan PDF ou photo) via Gemini (vision) : extrait
 * uniquement les 3 colonnes déjà agrégées à la main par l'enseignant — Moy Interro,
 * Moy Devoir, Compos — jamais les interrogations brutes individuelles (voir
 * [[saisie-automatique-notes]]). Le résultat brut passe ensuite par un écran de
 * correction humaine obligatoire avant tout enregistrement de Note.
 */
class NoteExtractionService
{
    private const MODEL = 'gemini-3.1-flash-lite';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.':generateContent';

    private const PROMPT = <<<'PROMPT'
        Tu lis une fiche de notes scolaire manuscrite. Extrais uniquement :
        - classe (ex: "4ème A")
        - matiere
        - professeur (nom du professeur)
        - pour CHAQUE élève listé : nom_eleve tel qu'écrit, moy_interro, moy_devoir, compos (les 3 colonnes déjà calculées à la main, PAS les interrogations brutes individuelles)

        Les valeurs numériques sont sur 20, avec virgule ou point comme séparateur décimal. Si une case est vide, illisible ou barrée, mets null plutôt que de deviner. Si l'élève est marqué absent, mets null pour la colonne concernée.

        Réponds UNIQUEMENT en JSON strict, sans texte autour, avec ce format exact :
        {
          "classe": "...",
          "matiere": "...",
          "professeur": "...",
          "eleves": [
            {"nom_eleve": "...", "moy_interro": ..., "moy_devoir": ..., "compos": ...}
          ]
        }
        PROMPT;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
    ) {
    }

    public function extraire(UploadedFile $fichier): FicheNotesExtraite
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Clé API Gemini absente (GEMINI_API_KEY) — configurez-la dans .env.local.');
        }

        $mimeType = $fichier->getMimeType() ?? 'application/octet-stream';
        $donnees  = base64_encode(file_get_contents($fichier->getPathname()));

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => [
                    'contents' => [[
                        'parts' => [
                            ['inline_data' => ['mime_type' => $mimeType, 'data' => $donnees]],
                            ['text' => self::PROMPT],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature'      => 0,
                        'responseMimeType' => 'application/json',
                    ],
                ],
                'timeout' => 60,
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

        $lignes = [];
        foreach ($donneesJson['eleves'] ?? [] as $ligne) {
            $nom = trim((string) ($ligne['nom_eleve'] ?? ''));
            if ($nom === '') {
                continue;
            }
            $lignes[] = new LigneEleveExtraite(
                nomExtrait: $nom,
                moyInterro: $this->versFloat($ligne['moy_interro'] ?? null),
                moyDevoir: $this->versFloat($ligne['moy_devoir'] ?? null),
                compos: $this->versFloat($ligne['compos'] ?? null),
            );
        }

        return new FicheNotesExtraite(
            classe: $this->videVersNull($donneesJson['classe'] ?? null),
            matiere: $this->videVersNull($donneesJson['matiere'] ?? null),
            professeur: $this->videVersNull($donneesJson['professeur'] ?? null),
            lignes: $lignes,
        );
    }

    private function versFloat(mixed $valeur): ?float
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (is_string($valeur)) {
            $valeur = str_replace(',', '.', $valeur);
        }
        return is_numeric($valeur) ? max(0.0, min(20.0, (float) $valeur)) : null;
    }

    private function videVersNull(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        return $valeur !== '' ? $valeur : null;
    }
}
