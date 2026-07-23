<?php

declare(strict_types=1);

namespace App\Student\Service\Export;

use App\Student\Entity\Eleve;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Génère le PDF de la liste des élèves, avec les mêmes colonnes que le
 * tableau affiché à l'écran (voir eleve/index.html.twig).
 */
class ElevePdfExporter
{
    private const array ENTETES = ['MATRICULE', 'NOM ET PRÉNOMS', 'SEXE', 'DATE DE NAISSANCE', 'CLASSE', 'TUTEUR', 'CONTACT TUTEUR', 'STATUT'];

    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** @param Eleve[] $eleves */
    public function exporter(array $eleves, string $titre = 'Liste des élèves', bool $avecEntete = false): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        // Par défaut, dompdf n'autorise le protocole file:// que sous son propre répertoire
        // vendor/ — élargi au projet pour que le logo de l'en-tête (voir EmploiDuTempsPdfExporter,
        // même correctif) soit accessible.
        $options->setChroot([$this->projectDir]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($this->html($eleves, $titre, $avecEntete));
        $dompdf->render();

        return $dompdf->output();
    }

    /** @param Eleve[] $eleves */
    private function html(array $eleves, string $titre, bool $avecEntete): string
    {
        $enteteCollegeHtml = $avecEntete ? $this->twig->render('admin/pdf/_entete_college.html.twig') : '';
        $lignes = '';
        foreach ($eleves as $e) {
            $classe = $e->getInscriptionEnCours()?->getClasse()?->getNom() ?? '—';

            $lignes .= '<tr>'
                .'<td>'.htmlspecialchars($e->getMatricule()).'</td>'
                .'<td>'.htmlspecialchars($e->getNomComplet()).'</td>'
                .'<td>'.htmlspecialchars($e->getSexe()?->value ?? '—').'</td>'
                .'<td>'.htmlspecialchars($e->getDateNaissance()?->format('d/m/Y') ?? '—').'</td>'
                .'<td>'.htmlspecialchars($classe).'</td>'
                .'<td>'.htmlspecialchars($e->getNomTuteur()).'</td>'
                .'<td>'.htmlspecialchars($e->getTelephoneTuteur()).'</td>'
                .'<td>'.htmlspecialchars($e->getStatut()->label()).'</td>'
                .'</tr>';
        }

        $entetes = implode('', array_map(static fn (string $h) => '<th>'.htmlspecialchars($h).'</th>', self::ENTETES));
        $total = count($eleves);
        $titre = htmlspecialchars($titre);

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
                {$enteteCollegeHtml}
                <h1>{$titre}</h1>
                <p class="subtitle">{$total} élève(s)</p>
                <table>
                    <thead><tr>{$entetes}</tr></thead>
                    <tbody>{$lignes}</tbody>
                </table>
            </body>
            </html>
            HTML;
    }
}
