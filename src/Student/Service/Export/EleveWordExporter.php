<?php

declare(strict_types=1);

namespace App\Student\Service\Export;

use App\Student\Entity\Eleve;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Génère le document Word (.docx) de la liste des élèves, avec les mêmes
 * colonnes que le tableau affiché à l'écran (voir eleve/index.html.twig).
 */
class EleveWordExporter
{
    private const array LARGEURS_COLONNES = [1600, 2600, 900, 1600, 1600, 2200, 2200, 1400];
    private const array ENTETES = ['MATRICULE', 'NOM ET PRÉNOMS', 'SEXE', 'DATE DE NAISSANCE', 'CLASSE', 'TUTEUR', 'CONTACT TUTEUR', 'STATUT'];

    /** @param Eleve[] $eleves */
    public function exporter(array $eleves, string $titre = 'Liste des élèves'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection(['orientation' => 'landscape']);

        $section->addText($titre, ['bold' => true, 'size' => 16]);
        $section->addText(sprintf('%d élève(s)', count($eleves)), ['size' => 9, 'color' => '666666']);
        $section->addTextBreak(1);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);

        $table->addRow();
        foreach (self::ENTETES as $i => $entete) {
            $table->addCell(self::LARGEURS_COLONNES[$i], ['bgColor' => 'DCE6F1'])
                ->addText($entete, ['bold' => true, 'size' => 9]);
        }

        foreach ($eleves as $e) {
            $classe = $e->getInscriptionEnCours()?->getClasse()?->getNom() ?? '—';

            $table->addRow();
            $table->addCell(self::LARGEURS_COLONNES[0])->addText($e->getMatricule(), ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[1])->addText($e->getNomComplet(), ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[2])->addText($e->getSexe()?->value ?? '—', ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[3])->addText($e->getDateNaissance()?->format('d/m/Y') ?? '—', ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[4])->addText($classe, ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[5])->addText($e->getNomTuteur(), ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[6])->addText($e->getTelephoneTuteur(), ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[7])->addText($e->getStatut()->label(), ['size' => 9]);
        }

        $fichierTemporaire = tempnam(sys_get_temp_dir(), 'eleves_word_');
        IOFactory::createWriter($phpWord, 'Word2007')->save($fichierTemporaire);
        $contenu = file_get_contents($fichierTemporaire);
        unlink($fichierTemporaire);

        return $contenu;
    }
}
