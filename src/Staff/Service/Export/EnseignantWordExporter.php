<?php

declare(strict_types=1);

namespace App\Staff\Service\Export;

use App\Staff\Entity\Enseignant;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Génère le document Word (.docx) de la liste des enseignants, avec les mêmes
 * colonnes que le tableau affiché à l'écran (voir enseignant/index.html.twig).
 */
class EnseignantWordExporter
{
    private const array LARGEURS_COLONNES = [2600, 900, 1600, 2200, 2200, 900, 2600];
    private const array ENTETES = ['NOM ET PRÉNOMS', 'SEXE', 'MATRICULE', 'STATUT', 'DISCIPLINE', 'CYCLE', 'CONTACT'];

    /** @param Enseignant[] $enseignants */
    public function exporter(array $enseignants): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection(['orientation' => 'landscape']);

        $section->addText('Liste des enseignants', ['bold' => true, 'size' => 16]);
        $section->addText(sprintf('%d personne(s)', count($enseignants)), ['size' => 9, 'color' => '666666']);
        $section->addTextBreak(1);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);

        $table->addRow();
        foreach (self::ENTETES as $i => $entete) {
            $table->addCell(self::LARGEURS_COLONNES[$i], ['bgColor' => 'DCE6F1'])
                ->addText($entete, ['bold' => true, 'size' => 9]);
        }

        foreach ($enseignants as $e) {
            $table->addRow();
            $table->addCell(self::LARGEURS_COLONNES[0])->addText($e->getNomComplet(), ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[1])->addText($e->getSexe()?->value ?? '—', ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[2])->addText($e->getMatricule() ?? 'Privé', ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[3])->addText($e->getType()->label(), ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[4])->addText($e->getDisciplines() !== [] ? implode(', ', $e->getDisciplines()) : '—', ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[5])->addText($e->getCycle() ?? '—', ['size' => 9]);
            $table->addCell(self::LARGEURS_COLONNES[6])->addText($this->contact($e), ['size' => 9]);
        }

        $fichierTemporaire = tempnam(sys_get_temp_dir(), 'enseignants_word_');
        IOFactory::createWriter($phpWord, 'Word2007')->save($fichierTemporaire);
        $contenu = file_get_contents($fichierTemporaire);
        unlink($fichierTemporaire);

        return $contenu;
    }

    private function contact(Enseignant $e): string
    {
        $parties = array_filter([$e->getEmail(), $e->getTelephone()]);

        return $parties !== [] ? implode(' / ', $parties) : '—';
    }
}
