<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Export;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Rendu PDF générique (HTML -> PDF via dompdf, même moteur que EnseignantPdfExporter) pour
 * les vues emploi du temps. Ne connaît rien de la structure des grilles : le HTML est déjà
 * entièrement construit par les templates `admin/edt/pdf/*.html.twig` (CSS volontairement
 * simple — dompdf ne supporte pas flexbox/grid correctement, contrairement aux templates
 * écran qui utilisent Bootstrap). Un rendu PDF identique quel que soit le navigateur de
 * l'utilisateur évite les divergences de pagination à l'impression (Chrome correct, Firefox
 * avec des pages vides sur les longs documents — cf. mémoire projet).
 */
class EmploiDuTempsPdfExporter
{
    public function exporter(string $html, string $orientation = 'landscape'): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $dompdf->output();
    }
}
