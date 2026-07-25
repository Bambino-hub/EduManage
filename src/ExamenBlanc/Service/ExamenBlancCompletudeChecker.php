<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service;

use App\Academic\Entity\Matiere;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Repository\ExamenBlancNoteRepository;
use App\ExamenBlanc\Service\Dto\MatiereCompletudeBlanc;
use App\ExamenBlanc\Service\Dto\RapportCompletudeBlanc;
use App\ExamenBlanc\Service\Dto\StatutFicheBlanc;
use App\Student\Repository\InscriptionRepository;

/**
 * Construit la grille matière × niveau d'un examen blanc — une fiche par (niveau, matière),
 * fusionnant déjà toutes les classes du niveau (voir ExamenBlancNoteController) : quelles
 * fiches ont déjà été renseignées, et l'effectif de chaque niveau. Les matières évaluées par
 * niveau viennent de ExamenBlancMatieresResolver (matières facultatives et EPS déjà exclues).
 */
final class ExamenBlancCompletudeChecker
{
    public function __construct(
        private readonly InscriptionRepository $inscriptionRepo,
        private readonly ExamenBlancNoteRepository $noteRepo,
        private readonly ExamenBlancMatieresResolver $matieresResolver,
    ) {
    }

    public function verifier(ExamenBlanc $examenBlanc): RapportCompletudeBlanc
    {
        $annee   = $examenBlanc->getAnneeScolaire();
        $niveaux = $examenBlanc->getNiveaux()->toArray();
        usort($niveaux, static fn ($a, $b) => $a->getOrdre() <=> $b->getOrdre());

        /** @var array<int, Matiere> $matieresParId */
        $matieresParId = [];
        /** @var array<int, array<int, StatutFicheBlanc>> $statutParMatiereEtNiveau [matiereId][niveauId] */
        $statutParMatiereEtNiveau = [];

        foreach ($niveaux as $niveau) {
            $effectif = count($this->inscriptionRepo->findActivesByNiveauEtAnnee($niveau, $annee));

            foreach ($this->matieresResolver->resoudre($examenBlanc, $niveau) as $matiere) {
                $matieresParId[$matiere->getId()] = $matiere;
                $renseignee = $this->noteRepo->existeNoteRenseignee($examenBlanc, $niveau, $matiere);
                $statutParMatiereEtNiveau[$matiere->getId()][$niveau->getId()] = new StatutFicheBlanc($renseignee, $effectif);
            }
        }

        $matieres = array_values($matieresParId);
        usort($matieres, static fn (Matiere $a, Matiere $b): int => $a->getNom() <=> $b->getNom());

        $lignes = array_map(
            static fn (Matiere $matiere): MatiereCompletudeBlanc => new MatiereCompletudeBlanc(
                $matiere,
                $statutParMatiereEtNiveau[$matiere->getId()] ?? [],
            ),
            $matieres,
        );

        return new RapportCompletudeBlanc($niveaux, $lignes);
    }
}
