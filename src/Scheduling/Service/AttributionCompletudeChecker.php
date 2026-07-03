<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Service\Dto\ClasseSansOption;
use App\Scheduling\Service\Dto\MatiereCompletude;
use App\Scheduling\Service\Dto\NiveauCompletude;
use App\Scheduling\Service\Dto\RapportCompletude;

/**
 * Vérifie, pour chaque niveau, si toutes ses classes ont un enseignant affecté
 * dans chaque matière réellement enseignée à ce niveau (MatiereNiveau avec
 * heuresParSemaine > 0). Une classe sans attribution pour une matière attendue
 * ne pourra jamais voir ses heures placées dans l'emploi du temps : ce rapport
 * sert à repérer ces trous avant la génération.
 *
 * Cas particulier des matières à choix (groupeOptionnel, ex. Allemand/Espagnol) :
 * une classe n'est tenue d'avoir un enseignant que pour les matières du groupe
 * qu'elle a explicitement choisies (Classe::$matieresOptionnelles), pas pour
 * toutes — sinon une classe qui ne fait que l'Allemand serait à tort signalée
 * comme "sans enseignant d'Espagnol". Si une classe n'a rien choisi du tout
 * dans un groupe attendu à son niveau, c'est un autre problème (configuration
 * manquante, pas enseignant manquant) : voir NiveauCompletude::$classesSansOption.
 */
final class AttributionCompletudeChecker
{
    public function __construct(
        private readonly ClasseRepository $classeRepo,
        private readonly MatiereNiveauRepository $matiereNiveauRepo,
        private readonly AttributionRepository $attributionRepo,
    ) {
    }

    public function verifier(AnneeScolaire $annee): RapportCompletude
    {
        $classesParNiveauId = [];
        $optionsParClasseId = [];
        foreach ($this->classeRepo->findByAnneeScolaireActive() as $classe) {
            $classesParNiveauId[$classe->getNiveau()->getId()][] = $classe;
            foreach ($classe->getMatieresOptionnelles() as $matiereOptionnelle) {
                $optionsParClasseId[$classe->getId()][$matiereOptionnelle->getId()] = true;
            }
        }

        $matiereNiveauxParNiveauId = [];
        foreach ($this->matiereNiveauRepo->findToutesEnseignees() as $matiereNiveau) {
            $matiereNiveauxParNiveauId[$matiereNiveau->getNiveau()->getId()][] = $matiereNiveau;
        }

        $couverture = [];
        foreach ($this->attributionRepo->findByAnneeScolaire((int) $annee->getId()) as $attribution) {
            $couverture[$attribution->getClasse()->getId()][$attribution->getMatiere()->getId()] = $attribution;
        }

        $niveaux = [];
        foreach ($classesParNiveauId as $niveauId => $classesDuNiveau) {
            $niveau = $classesDuNiveau[0]->getNiveau();
            $matiereNiveauxDuNiveau = $matiereNiveauxParNiveauId[$niveauId] ?? [];

            // Matières à choix attendues à ce niveau, groupées par valeur d'enum, pour
            // détecter les classes n'ayant fait aucun choix dans un groupe pourtant proposé.
            $matieresParGroupe = [];
            foreach ($matiereNiveauxDuNiveau as $matiereNiveau) {
                $groupe = $matiereNiveau->getMatiere()->getGroupeOptionnel();
                if ($groupe !== null) {
                    $matieresParGroupe[$groupe->value][] = $matiereNiveau->getMatiere();
                }
            }

            $matieres = [];
            foreach ($matiereNiveauxDuNiveau as $matiereNiveau) {
                $matiere                 = $matiereNiveau->getMatiere();
                $attributionsParClasseId = [];
                $concerneeParClasseId    = [];
                $classesManquantes       = [];

                foreach ($classesDuNiveau as $classe) {
                    $concernee = $matiere->getGroupeOptionnel() === null
                        || ($optionsParClasseId[$classe->getId()][$matiere->getId()] ?? false);
                    $concerneeParClasseId[$classe->getId()] = $concernee;

                    if (!$concernee) {
                        continue;
                    }

                    $attribution = $couverture[$classe->getId()][$matiere->getId()] ?? null;
                    $attributionsParClasseId[$classe->getId()] = $attribution;
                    if ($attribution === null) {
                        $classesManquantes[] = $classe;
                    }
                }

                $matieres[] = new MatiereCompletude($matiere, $attributionsParClasseId, $concerneeParClasseId, $classesManquantes);
            }

            $classesSansOption = [];
            foreach ($matieresParGroupe as $matieresDuGroupe) {
                $groupe = $matieresDuGroupe[0]->getGroupeOptionnel();
                foreach ($classesDuNiveau as $classe) {
                    $aChoisi = false;
                    foreach ($matieresDuGroupe as $matiereDuGroupe) {
                        if ($optionsParClasseId[$classe->getId()][$matiereDuGroupe->getId()] ?? false) {
                            $aChoisi = true;
                            break;
                        }
                    }
                    if (!$aChoisi) {
                        $classesSansOption[] = new ClasseSansOption($classe, $groupe);
                    }
                }
            }

            $niveaux[] = new NiveauCompletude($niveau, $classesDuNiveau, $matieres, $classesSansOption);
        }

        return new RapportCompletude($niveaux);
    }
}
