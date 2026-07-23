<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Grading\Entity\MoyenneManuelle;
use App\Grading\Entity\Trimestre;
use App\Grading\Repository\MoyenneManuelleRepository;
use App\Scheduling\Entity\Attribution;
use App\Student\Entity\Inscription;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistrement en lot des surcharges manuelles de Moy Interro/Moy Devoir sur la fiche
 * de notes en ligne — même principe que NoteSaisieService, un élève par ligne. Une valeur
 * vide efface la surcharge et laisse le calcul automatique reprendre la main.
 */
class MoyenneManuelleSaisieService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MoyenneManuelleRepository $repo,
    ) {
    }

    /**
     * @param Inscription[] $inscriptionsActives
     * @param array<int, array{interro?: string, devoir?: string}> $donneesParEleveId
     */
    public function enregistrer(Attribution $attribution, Trimestre $trimestre, array $inscriptionsActives, array $donneesParEleveId): void
    {
        $existantes = $this->repo->findByAttributionEtTrimestreIndexeesParEleve($attribution, $trimestre);

        foreach ($inscriptionsActives as $inscription) {
            $eleve   = $inscription->getEleve();
            $eleveId = $eleve->getId();
            $donnees = $donneesParEleveId[$eleveId] ?? [];

            $interro = $this->normaliserValeur((string) ($donnees['interro'] ?? ''));
            $devoir  = $this->normaliserValeur((string) ($donnees['devoir'] ?? ''));

            $ligne = $existantes[$eleveId] ?? null;

            if ($interro === null && $devoir === null) {
                if ($ligne !== null) {
                    $this->em->remove($ligne);
                }
                continue;
            }

            if ($ligne === null) {
                $ligne = new MoyenneManuelle();
                $ligne->setAttribution($attribution);
                $ligne->setTrimestre($trimestre);
                $ligne->setEleve($eleve);
                $this->em->persist($ligne);
            }

            $ligne->setMoyenneInterrogation($interro);
            $ligne->setMoyenneDevoirs($devoir);
        }
    }

    private function normaliserValeur(string $valeurBrute): ?string
    {
        $valeurBrute = trim($valeurBrute);
        if ($valeurBrute === '' || !is_numeric($valeurBrute)) {
            return null;
        }

        $valeur = max(0.0, min(20.0, (float) $valeurBrute));

        return number_format($valeur, 2, '.', '');
    }
}
