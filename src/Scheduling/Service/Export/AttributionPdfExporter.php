<?php

declare(strict_types=1);

namespace App\Scheduling\Service\Export;

use App\Scheduling\Entity\Attribution;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * PDF de la liste des attributions (enseignant / matière / classe / volume horaire),
 * groupé par enseignant comme le tableau à l'écran (voir attribution/index.html.twig).
 * Rendu serveur dompdf — identique quel que soit le navigateur (cf. EnseignantPdfExporter).
 */
class AttributionPdfExporter
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * @param list<array{enseignant: \App\Staff\Entity\Enseignant, total: int, matieres: list<array{matiere: \App\Academic\Entity\Matiere, attributions: Attribution[]}>}> $groupes
     */
    public function exporter(array $groupes, string $titre, bool $avecEntete = false): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([$this->projectDir]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->html($groupes, $titre, $avecEntete));
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param list<array{enseignant: \App\Staff\Entity\Enseignant, total: int, matieres: list<array{matiere: \App\Academic\Entity\Matiere, attributions: Attribution[]}>}> $groupes
     */
    private function html(array $groupes, string $titre, bool $avecEntete): string
    {
        $enteteCollegeHtml = $avecEntete ? $this->twig->render('admin/pdf/_entete_college.html.twig') : '';

        $lignes          = '';
        $totalGeneral    = 0;
        $nbAttributions  = 0;

        foreach ($groupes as $groupe) {
            $enseignant = $groupe['enseignant'];
            $totalGeneral += $groupe['total'];

            $lignes .= '<tr class="grp">'
                .'<td colspan="2"><strong>'.htmlspecialchars($enseignant->getNomComplet()).'</strong>'
                .' <span class="muted">— '.htmlspecialchars($enseignant->getType()->label()).'</span></td>'
                .'<td class="tc"><strong>'.$groupe['total'].'h</strong> / sem.</td>'
                .'</tr>';

            foreach ($groupe['matieres'] as $mg) {
                foreach ($mg['attributions'] as $attribution) {
                    $nbAttributions++;
                    $lignes .= '<tr>'
                        .'<td>'.htmlspecialchars($mg['matiere']->getCode()).' '
                        .'<span class="muted">'.htmlspecialchars($mg['matiere']->getNom()).'</span></td>'
                        .'<td>'.htmlspecialchars($attribution->getClasse()?->getNom() ?? '—').'</td>'
                        .'<td class="tc">'.$attribution->getVolumeHoraireHebdo().'h</td>'
                        .'</tr>';
                }
            }
        }

        if ($lignes === '') {
            $lignes = '<tr><td colspan="3" class="tc muted">Aucune attribution.</td></tr>';
        }

        $titreHtml    = htmlspecialchars($titre);
        $sousTitre    = $nbAttributions.' attribution(s) — '.count($groupes).' enseignant(s) — '.$totalGeneral.'h/sem. au total';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head>
            <meta charset="utf-8">
            <style>
                @page { margin: 1.5cm; }
                html, body { margin: 0; padding: 0; }
                body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a2e; }
                h1 { font-size: 16px; margin: 0 0 2px; }
                p.subtitle { font-size: 9px; color: #666; margin: 0 0 12px; }
                table { width: 100%; border-collapse: separate; border-spacing: 0; }
                th, td { border: 1px solid #999; border-left: 0; border-top: 0; padding: 4px 6px; text-align: left; vertical-align: top; }
                th:first-child, td:first-child { border-left: 1px solid #999; }
                thead th { border-top: 1px solid #999; background: #dce6f1; font-weight: bold; }
                tr.grp td { background: #eef2f9; }
                td.tc { text-align: center; white-space: nowrap; }
                .muted { color: #666; }
            </style>
            </head>
            <body>
                {$enteteCollegeHtml}
                <h1>{$titreHtml}</h1>
                <p class="subtitle">{$sousTitre}</p>
                <table>
                    <thead><tr><th>Matière</th><th>Classe</th><th>H/sem.</th></tr></thead>
                    <tbody>{$lignes}</tbody>
                </table>
            </body>
            </html>
            HTML;
    }
}
