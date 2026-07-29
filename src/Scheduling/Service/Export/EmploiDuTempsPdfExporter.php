<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Export;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * @param string|array{0:float,1:float,2:float,3:float} $paper Nom de format dompdf ('A4', 'A5'...)
     *   ou tableau [x1, y1, x2, y2] en points pour un format personnalisé (ex. ticket de caisse
     *   étroit — voir EconomatController::recuPdf()). Un tableau ignore `$orientation`.
     */
    public function exporter(string $html, string $orientation = 'landscape', string|array $paper = 'A4'): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        // Par défaut, dompdf n'autorise le protocole file:// que sous son propre répertoire
        // vendor/ — on l'élargit à tout le projet pour permettre aux templates PDF de
        // référencer des images locales (logo, photos élèves) par chemin absolu.
        $options->setChroot([$this->projectDir]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $dompdf->output();
    }
}
