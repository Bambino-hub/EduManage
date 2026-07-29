<?php

declare(strict_types=1);

namespace App\ExamenNational\Service\Export;

use App\ExamenNational\Entity\CandidatExamenNational;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Génère le fichier Excel (.xlsx) de la liste des candidats d'une session d'examen national,
 * mêmes colonnes que l'export PDF (voir admin/examen_national/pdf/candidats.html.twig).
 */
class CandidatExamenNationalXlsxExporter
{
    private const array COLONNES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    private const array ENTETES = ['N° Table', 'Nom', 'Prénoms', 'Sexe', 'Date de naissance', 'Lieu de naissance', 'Moyenne globale'];

    /** @param iterable<CandidatExamenNational> $candidats */
    public function exporter(iterable $candidats): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Candidats');

        foreach (self::ENTETES as $i => $entete) {
            $cellule = $sheet->getCell(self::COLONNES[$i].'1');
            $cellule->setValue($entete);
            $cellule->getStyle()->getFont()->setBold(true);
            $cellule->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('6A2C91');
            $cellule->getStyle()->getFont()->getColor()->setRGB('FFFFFF');
            $cellule->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $ligne = 2;
        foreach ($candidats as $candidat) {
            $sheet->getCell('A'.$ligne)->setValue($candidat->getNumeroTable() ?? '');
            $sheet->getCell('B'.$ligne)->setValue($candidat->getNom());
            $sheet->getCell('C'.$ligne)->setValue($candidat->getPrenoms());
            $sheet->getCell('D'.$ligne)->setValue($candidat->getSexe()?->label() ?? '');

            $dateNaissance = $candidat->getDateNaissance();
            if ($dateNaissance !== null) {
                $celluleDate = $sheet->getCell('E'.$ligne);
                $celluleDate->setValue(Date::PHPToExcel($dateNaissance));
                $celluleDate->getStyle()->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }

            $sheet->getCell('F'.$ligne)->setValue($candidat->getLieuNaissance() ?? '');

            $moyenne = $candidat->getMoyenneGlobaleAffichee();
            if ($moyenne !== null) {
                $celluleMoyenne = $sheet->getCell('G'.$ligne);
                $celluleMoyenne->setValue((float) $moyenne);
                $celluleMoyenne->getStyle()->getNumberFormat()->setFormatCode('0.00');
            }

            $sheet->getCell('A'.$ligne)->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getCell('D'.$ligne)->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getCell('E'.$ligne)->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getCell('G'.$ligne)->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            ++$ligne;
        }

        $derniereLigne = max($ligne - 1, 1);
        // Bordures sur tout le tableau : sans elles, un numéro (aligné à droite) collé à un
        // texte (aligné à gauche) de la colonne suivante donne l'illusion de deux valeurs
        // fusionnées à l'écran (cf. numéro de table/nom, date/lieu de naissance).
        $sheet->getStyle('A1:G'.$derniereLigne)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (self::COLONNES as $colonne) {
            $sheet->getColumnDimension($colonne)->setAutoSize(true);
        }

        $fichierTemporaire = tempnam(sys_get_temp_dir(), 'candidats_xlsx_');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($fichierTemporaire);
        $contenu = file_get_contents($fichierTemporaire);
        unlink($fichierTemporaire);

        return $contenu;
    }
}
