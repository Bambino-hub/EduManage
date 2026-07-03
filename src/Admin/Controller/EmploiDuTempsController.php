<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\SalleRepository;
use App\Scheduling\Enum\JourSemaine;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Scheduling\Service\EmploiDuTempsGenerator;
use App\Staff\Repository\EnseignantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/emplois-du-temps', name: 'admin_edt_')]
class EmploiDuTempsController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(
        Request $request,
        SeanceRepository $seanceRepo,
        ClasseRepository $classeRepo,
        EnseignantRepository $enseignantRepo,
        CreneauRepository $creneauRepo,
    ): Response {
        $classes     = $classeRepo->findByAnneeScolaireActive();
        $enseignants = $enseignantRepo->findActifs();

        $enseignantId = $request->query->getInt('enseignant') ?: null;
        $classeId     = $enseignantId ? null : ($request->query->getInt('classe') ?: null);

        if (!$classeId && !$enseignantId && $classes !== []) {
            $classeId = $classes[0]->getId();
        }

        if ($enseignantId) {
            $seances = $seanceRepo->findByEnseignant($enseignantId);
        } elseif ($classeId) {
            $seances = $seanceRepo->findByClasse($classeId);
        } else {
            $seances = [];
        }

        // Plusieurs séances peuvent partager un même créneau pour une même classe
        // (matières "parallèles" comme Allemand/Espagnol) : chaque case peut donc
        // contenir plusieurs séances, pas une seule.
        $grille = [];
        foreach ($seances as $seance) {
            $creneau = $seance->getCreneau();
            $grille[$creneau->getJourSemaine()->value][$creneau->getOrdre()][] = $seance;
        }

        $creneauxParJour = [];
        $ordreMax        = 0;
        foreach ($creneauRepo->findOrdonnes() as $creneau) {
            $creneauxParJour[$creneau->getJourSemaine()->value][$creneau->getOrdre()] = $creneau;
            $ordreMax = max($ordreMax, $creneau->getOrdre());
        }

        $joursAffiches = array_values(array_filter(
            JourSemaine::cases(),
            static fn (JourSemaine $j) => isset($creneauxParJour[$j->value]),
        ));
        usort($joursAffiches, static fn (JourSemaine $a, JourSemaine $b) => $a->ordre() <=> $b->ordre());

        return $this->render('admin/edt/index.html.twig', [
            'classes'               => $classes,
            'enseignants'           => $enseignants,
            'classeSelectionnee'    => $classeId,
            'enseignantSelectionne' => $enseignantId,
            'grille'                => $grille,
            'creneauxParJour'       => $creneauxParJour,
            'joursAffiches'         => $joursAffiches,
            'ordreMax'              => $ordreMax,
        ]);
    }

    /**
     * Vue globale : toutes les classes de l'année active côte à côte, une ligne par
     * créneau — reproduit le format du document papier officiel (code matière seul,
     * plages réservées type "DEVOIR"/"PLEINAIRE" fusionnées sur toutes les colonnes).
     */
    #[Route('/globale', name: 'globale')]
    public function globale(
        AnneeScolaireRepository $anneeRepo,
        ClasseRepository $classeRepo,
        CreneauRepository $creneauRepo,
        SeanceRepository $seanceRepo,
    ): Response {
        $annee   = $anneeRepo->findActive();
        $classes = $annee ? $classeRepo->findByAnneeScolaireActive() : [];
        $seances = $annee ? $seanceRepo->findByAnneeScolaire((int) $annee->getId()) : [];

        // grille[jour][ordre][classeId] = Seance[] (plusieurs si matières parallèles, ex. ALL/ESP)
        $grille = [];
        foreach ($seances as $seance) {
            $creneau  = $seance->getCreneau();
            $classeId = $seance->getAttribution()->getClasse()->getId();
            $grille[$creneau->getJourSemaine()->value][$creneau->getOrdre()][$classeId][] = $seance;
        }

        $creneauxParJour = [];
        $ordreMax        = 0;
        foreach ($creneauRepo->findOrdonnes() as $creneau) {
            $creneauxParJour[$creneau->getJourSemaine()->value][$creneau->getOrdre()] = $creneau;
            $ordreMax = max($ordreMax, $creneau->getOrdre());
        }

        $joursAffiches = array_values(array_filter(
            JourSemaine::cases(),
            static fn (JourSemaine $j) => isset($creneauxParJour[$j->value]),
        ));
        usort($joursAffiches, static fn (JourSemaine $a, JourSemaine $b) => $a->ordre() <=> $b->ordre());

        return $this->render('admin/edt/globale.html.twig', [
            'annee'           => $annee,
            'classes'         => $classes,
            'grille'          => $grille,
            'creneauxParJour' => $creneauxParJour,
            'joursAffiches'   => $joursAffiches,
        ]);
    }

    #[Route('/generer', name: 'generate', methods: ['GET', 'POST'])]
    public function generate(
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        AttributionRepository $attributionRepo,
        CreneauRepository $creneauRepo,
        SalleRepository $salleRepo,
        EmploiDuTempsGenerator $generator,
    ): Response {
        $annee    = $anneeRepo->findActive();
        $resultat = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('generer_edt', $request->getPayload()->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
                return $this->redirectToRoute('admin_edt_generate');
            }

            if ($annee === null) {
                $this->addFlash('error', 'Aucune année scolaire active. Activez une année avant de générer.');
                return $this->redirectToRoute('admin_edt_generate');
            }

            $resultat = $generator->generer($annee);

            if ($resultat->succes()) {
                $this->addFlash('success', sprintf(
                    'Emploi du temps généré : %d heures placées sans conflit.',
                    $resultat->heuresPlacees,
                ));
            } elseif ($resultat->heuresPlacees > 0) {
                $this->addFlash('warning', sprintf(
                    'Génération partielle : %d heures placées, %d non placées (voir détail ci-dessous).',
                    $resultat->heuresPlacees,
                    $resultat->heuresNonPlacees,
                ));
            } else {
                $this->addFlash('error', 'Rien n\'a pu être généré (vérifiez les attributions, salles et créneaux).');
            }
        }

        return $this->render('admin/edt/generate.html.twig', [
            'annee'          => $annee,
            'resultat'       => $resultat,
            'nbAttributions' => $annee ? count($attributionRepo->findByAnneeScolaire((int) $annee->getId())) : 0,
            'nbCreneaux'     => count($creneauRepo->findOrdonnes()),
            'nbSalles'       => count($salleRepo->findAll()),
        ]);
    }
}
