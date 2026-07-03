<?php

declare(strict_types=1);

namespace App\Staff\Service\Export;

use App\Staff\Entity\Enseignant;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Génère le PDF de la liste des enseignants, avec les mêmes colonnes que le
 * tableau affiché à l'écran (voir enseignant/index.html.twig).
 */
class EnseignantPdfExporter
{
    private const array ENTETES = ['NOM ET PRÉNOMS', 'SEXE', 'MATRICULE', 'STATUT', 'DISCIPLINE', 'CYCLE', 'CONTACT'];

    /** @param Enseignant[] $enseignants */
    public function exporter(array $enseignants): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($this->html($enseignants));
        $dompdf->render();

        return $dompdf->output();
    }

    /** @param Enseignant[] $enseignants */
    private function html(array $enseignants): string
    {
        $lignes = '';
        foreach ($enseignants as $e) {
            $disciplines = $e->getDisciplines() !== [] ? implode(', ', $e->getDisciplines()) : '—';
            $contact = implode(' / ', array_filter([$e->getEmail(), $e->getTelephone()])) ?: '—';

            $lignes .= '<tr>'
                .'<td>'.htmlspecialchars($e->getNomComplet()).'</td>'
                .'<td>'.htmlspecialchars($e->getSexe()?->value ?? '—').'</td>'
                .'<td>'.htmlspecialchars($e->getMatricule() ?? 'Privé').'</td>'
                .'<td>'.htmlspecialchars($e->getType()->label()).'</td>'
                .'<td>'.htmlspecialchars($disciplines).'</td>'
                .'<td>'.htmlspecialchars($e->getCycle() ?? '—').'</td>'
                .'<td>'.htmlspecialchars($contact).'</td>'
                .'</tr>';
        }

        $entetes = implode('', array_map(static fn (string $h) => '<th>'.htmlspecialchars($h).'</th>', self::ENTETES));
        $total = count($enseignants);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head>
            <meta charset="utf-8">
            <style>
                body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a2e; }
                h1 { font-size: 16px; margin: 0 0 2px; }
                p.subtitle { font-size: 9px; color: #666; margin: 0 0 12px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
                th { background: #dce6f1; font-weight: bold; }
                tr:nth-child(even) td { background: #f7f7fb; }
            </style>
            </head>
            <body>
                <h1>Liste des enseignants</h1>
                <p class="subtitle">{$total} personne(s)</p>
                <table>
                    <thead><tr>{$entetes}</tr></thead>
                    <tbody>{$lignes}</tbody>
                </table>
            </body>
            </html>
            HTML;
    }
}
