<?php

declare(strict_types=1);

namespace App\Staff\Service\Import;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\IOFactory;

/** Lit le premier tableau d'un document Word (.docx) au format "liste du personnel". */
class DocxEnseignantReader implements EnseignantFileReaderInterface
{
    public function __construct(
        private readonly EnseignantTableInterpreter $interpreter,
    ) {
    }

    public function lire(string $cheminFichier): array
    {
        $phpWord = IOFactory::load($cheminFichier);

        $grille = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof Table) {
                    $grille = $this->lireTable($element);
                    break 2;
                }
            }
        }

        return $this->interpreter->interpreter($grille);
    }

    /** @return list<list<string>> */
    private function lireTable(Table $table): array
    {
        $grille = [];
        foreach ($table->getRows() as $row) {
            $cellules = [];
            foreach ($row->getCells() as $cell) {
                // Text::getText() renvoie le texte échappé XML (ex. "&amp;" pour "&").
                $texte      = html_entity_decode($this->lireTexteContainer($cell), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $cellules[] = trim(preg_replace('/\s+/u', ' ', $texte) ?? $texte);
            }
            $grille[] = $cellules;
        }

        return $this->realignerEnTeteFusionne($grille);
    }

    /**
     * Une cellule d'en-tête fusionnée sur plusieurs colonnes (ex. "DISCIPLINES" qui couvre
     * les 2 colonnes de matières) donne une 1ère ligne plus courte que les lignes de données,
     * ce qui décale tous les index de colonnes après elle. On la duplique pour réaligner.
     *
     * @param list<list<string>> $grille
     * @return list<list<string>>
     */
    private function realignerEnTeteFusionne(array $grille): array
    {
        if (count($grille) < 2) {
            return $grille;
        }

        $largeurDonnees = 0;
        foreach (array_slice($grille, 1) as $ligne) {
            if (count(array_filter($ligne, static fn (string $v) => $v !== '')) > 3) {
                $largeurDonnees = count($ligne);
                break;
            }
        }

        $enTete = $grille[0];
        $manque = $largeurDonnees - count($enTete);
        if ($largeurDonnees > 0 && $manque > 0) {
            $nouvelEntete = [];
            foreach ($enTete as $cellule) {
                $nouvelEntete[] = $cellule;
                if ($manque > 0 && str_contains(mb_strtoupper($cellule, 'UTF-8'), 'DISCIPLIN')) {
                    for ($k = 0; $k < $manque; $k++) {
                        $nouvelEntete[] = $cellule;
                    }
                    $manque = 0;
                }
            }
            $grille[0] = $nouvelEntete;
        }

        return $grille;
    }

    /**
     * Extrait récursivement le texte d'un conteneur PhpWord (Cell, TextRun…) : `Text::getText()`
     * existe, mais `TextRun` (utilisé dès qu'une cellule a un style de caractère) n'a PAS de
     * `getText()` — c'est lui-même un conteneur qu'il faut redescendre pour trouver ses `Text`.
     */
    private function lireTexteContainer(AbstractContainer $container): string
    {
        $texte = '';
        foreach ($container->getElements() as $el) {
            if ($el instanceof Text) {
                $texte .= $el->getText();
            } elseif ($el instanceof AbstractContainer) {
                $texte .= $this->lireTexteContainer($el);
            } else {
                // Élément non textuel (saut de ligne, image…) : un espace pour ne pas
                // accoler deux mots qui étaient séparés dans la cellule d'origine.
                $texte .= ' ';
            }
        }

        return $texte;
    }
}
