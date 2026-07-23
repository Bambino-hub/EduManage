<?php

declare(strict_types=1);

namespace App\ExamenNational\Service;

use setasign\Fpdi\Fpdi;

/**
 * Découpe un PDF source en petits PDF de quelques pages, pour l'envoyer par lots à l'IA
 * plutôt que d'un coup — voir ReleveExtractionService pour le pourquoi (fiabilité + éviter
 * les timeouts sur un relevé de plusieurs dizaines de pages).
 */
class RelevePdfSplitter
{
    public function compterPages(string $cheminSource): int
    {
        $pdf = new Fpdi();
        return $pdf->setSourceFile($cheminSource);
    }

    /**
     * Extrait les pages [$pageDebut, $pageFin] (1-indexé, inclusif) dans un nouveau fichier
     * PDF temporaire et retourne son chemin. À l'appelant de le supprimer après usage.
     */
    public function extraireLot(string $cheminSource, int $pageDebut, int $pageFin): string
    {
        $pdf = new Fpdi();
        $pdf->setSourceFile($cheminSource);

        for ($numero = $pageDebut; $numero <= $pageFin; $numero++) {
            $idTemplate = $pdf->importPage($numero);
            $taille     = $pdf->getTemplateSize($idTemplate);
            $pdf->AddPage($taille['orientation'], [$taille['width'], $taille['height']]);
            $pdf->useTemplate($idTemplate);
        }

        $cheminLot = tempnam(sys_get_temp_dir(), 'releve_lot_').'.pdf';
        $pdf->Output('F', $cheminLot);

        return $cheminLot;
    }
}
