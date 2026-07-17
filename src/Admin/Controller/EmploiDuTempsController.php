<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\SalleRepository;
use App\Scheduling\Entity\Creneau;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Repository\RegroupementClasseRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Scheduling\Service\EmploiDuTempsGenerator;
use App\Scheduling\Service\GrilleEmploiDuTempsBuilder;
use App\Scheduling\Service\EmploiDuTempsPermutationService;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use App\Staff\Repository\EnseignantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

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
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $classes     = $classeRepo->findByAnneeScolaireActive();
        $enseignants = $enseignantRepo->findActifs();

        $enseignantId = $request->query->getInt('enseignant') ?: null;
        $classeId     = $enseignantId ? null : ($request->query->getInt('classe') ?: null);

        if (!$classeId && !$enseignantId && $classes !== []) {
            $classeId = $classes[0]->getId();
        }

        $classeObj     = null;
        $enseignantObj = null;

        if ($enseignantId) {
            $seances       = $seanceRepo->findByEnseignant($enseignantId);
            $enseignantObj = $enseignantRepo->find($enseignantId);
        } elseif ($classeId) {
            $seances   = $seanceRepo->findByClasse($classeId);
            $classeObj = $classeRepo->find($classeId);
        } else {
            $seances = [];
        }

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        return $this->render('admin/edt/index.html.twig', [
            'classes'               => $classes,
            'enseignants'           => $enseignants,
            'classeSelectionnee'    => $classeId,
            'enseignantSelectionne' => $enseignantId,
            'classeObj'             => $classeObj,
            'enseignantObj'         => $enseignantObj,
            'grille'                => $grilleBuilder->regrouperParCreneau($seances),
            'creneauxParJour'       => $creneauxParJour,
            'joursAffiches'         => $joursAffiches,
            'ordreMax'              => $ordreMax,
        ]);
    }

    /**
     * Export PDF de la classe ou de l'enseignant actuellement sélectionné sur `index()`
     * (mêmes paramètres `classe`/`enseignant` en query string) — rendu serveur (dompdf)
     * identique quel que soit le navigateur, contrairement à l'impression navigateur
     * (`window.print()`) dont la pagination diverge entre Chrome et Firefox sur les
     * documents longs (voir mémoire projet : pages vides sur Firefox en impression groupée).
     */
    #[Route('/export-pdf', name: 'export_pdf')]
    public function exportPdf(
        Request $request,
        SeanceRepository $seanceRepo,
        ClasseRepository $classeRepo,
        EnseignantRepository $enseignantRepo,
        CreneauRepository $creneauRepo,
        EmploiDuTempsPdfExporter $exporter,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $enseignantId = $request->query->getInt('enseignant') ?: null;
        $classeId     = $enseignantId ? null : ($request->query->getInt('classe') ?: null);

        $titre    = 'Emploi du temps';
        $contexte = 'classe';
        $seances  = [];

        if ($enseignantId) {
            $enseignantObj = $enseignantRepo->find($enseignantId);
            if ($enseignantObj) {
                $seances  = $seanceRepo->findByEnseignant($enseignantId);
                $titre    = 'Emploi du temps — '.$enseignantObj->getNomComplet();
                $contexte = 'enseignant';
            }
        } elseif ($classeId) {
            $classeObj = $classeRepo->find($classeId);
            if ($classeObj) {
                $seances = $seanceRepo->findByClasse($classeId);
                $titre   = 'Emploi du temps — '.$classeObj->getNom().' — '.$classeObj->getAnneeScolaire()->getLibelle();
            }
        }

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        $html = $this->renderView('admin/edt/pdf/grilles.html.twig', [
            'pages'           => [['titre' => $titre, 'grille' => $grilleBuilder->regrouperParCreneau($seances), 'contexte' => $contexte]],
            'creneauxParJour' => $creneauxParJour,
            'joursAffiches'   => $joursAffiches,
            'ordreMax'        => $ordreMax,
        ]);

        return $this->reponsePdf($exporter->exporter($html), $this->nomFichier($titre));
    }

    /**
     * Impression groupée : l'emploi du temps de TOUTES les classes de l'année active à
     * la suite (une classe par page à l'impression, cf. `.edt-page-break` dans
     * `imprimer_classes.html.twig`) — évite d'imprimer classe par classe depuis la vue
     * `index()`. Une seule requête `findByAnneeScolaire` (déjà utilisée par `globale()`)
     * plutôt qu'un `findByClasse` par classe.
     */
    #[Route('/imprimer/classes', name: 'imprimer_classes')]
    public function imprimerClasses(
        AnneeScolaireRepository $anneeRepo,
        ClasseRepository $classeRepo,
        SeanceRepository $seanceRepo,
        CreneauRepository $creneauRepo,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $annee   = $anneeRepo->findActive();
        $classes = $annee ? $classeRepo->findByAnneeScolaireActive() : [];
        $seances = $annee ? $seanceRepo->findByAnneeScolaire((int) $annee->getId()) : [];

        $seancesParClasse = [];
        foreach ($seances as $seance) {
            $seancesParClasse[$seance->getAttribution()->getClasse()->getId()][] = $seance;
        }

        $grillesParClasse = [];
        foreach ($classes as $classe) {
            $grillesParClasse[$classe->getId()] = $grilleBuilder->regrouperParCreneau($seancesParClasse[$classe->getId()] ?? []);
        }

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        return $this->render('admin/edt/imprimer_classes.html.twig', [
            'annee'             => $annee,
            'classes'           => $classes,
            'grillesParClasse'  => $grillesParClasse,
            'creneauxParJour'   => $creneauxParJour,
            'joursAffiches'     => $joursAffiches,
            'ordreMax'          => $ordreMax,
        ]);
    }

    /** Export PDF de imprimerClasses() — une page par classe (dompdf, cf. exportPdf()). */
    #[Route('/imprimer/classes/export-pdf', name: 'imprimer_classes_export_pdf')]
    public function exportPdfClasses(
        AnneeScolaireRepository $anneeRepo,
        ClasseRepository $classeRepo,
        SeanceRepository $seanceRepo,
        CreneauRepository $creneauRepo,
        EmploiDuTempsPdfExporter $exporter,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $annee   = $anneeRepo->findActive();
        $classes = $annee ? $classeRepo->findByAnneeScolaireActive() : [];
        $seances = $annee ? $seanceRepo->findByAnneeScolaire((int) $annee->getId()) : [];

        $seancesParClasse = [];
        foreach ($seances as $seance) {
            $seancesParClasse[$seance->getAttribution()->getClasse()->getId()][] = $seance;
        }

        $pages = [];
        foreach ($classes as $classe) {
            $pages[] = [
                'titre'    => 'Emploi du temps — '.$classe->getNom().($annee ? ' — '.$annee->getLibelle() : ''),
                'grille'   => $grilleBuilder->regrouperParCreneau($seancesParClasse[$classe->getId()] ?? []),
                'contexte' => 'classe',
            ];
        }

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        $html = $this->renderView('admin/edt/pdf/grilles.html.twig', [
            'pages'           => $pages,
            'creneauxParJour' => $creneauxParJour,
            'joursAffiches'   => $joursAffiches,
            'ordreMax'        => $ordreMax,
        ]);

        return $this->reponsePdf($exporter->exporter($html), 'emploi-du-temps-toutes-les-classes.pdf');
    }

    /**
     * Impression groupée : l'emploi du temps de TOUS les enseignants ayant au moins une
     * séance cette année à la suite (une classe sans heure placée n'a rien à imprimer,
     * cf. filtre ci-dessous) — même principe que imprimerClasses().
     */
    #[Route('/imprimer/enseignants', name: 'imprimer_enseignants')]
    public function imprimerEnseignants(
        AnneeScolaireRepository $anneeRepo,
        EnseignantRepository $enseignantRepo,
        SeanceRepository $seanceRepo,
        CreneauRepository $creneauRepo,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $annee   = $anneeRepo->findActive();
        $seances = $annee ? $seanceRepo->findByAnneeScolaire((int) $annee->getId()) : [];

        $seancesParEnseignant = [];
        foreach ($seances as $seance) {
            $seancesParEnseignant[$seance->getAttribution()->getEnseignant()->getId()][] = $seance;
        }

        $enseignants = array_values(array_filter(
            $enseignantRepo->findActifs(),
            static fn ($e) => isset($seancesParEnseignant[$e->getId()]),
        ));

        $grillesParEnseignant = [];
        foreach ($enseignants as $enseignant) {
            $grillesParEnseignant[$enseignant->getId()] = $grilleBuilder->regrouperParCreneau($seancesParEnseignant[$enseignant->getId()]);
        }

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        return $this->render('admin/edt/imprimer_enseignants.html.twig', [
            'annee'                 => $annee,
            'enseignants'           => $enseignants,
            'grillesParEnseignant'  => $grillesParEnseignant,
            'creneauxParJour'       => $creneauxParJour,
            'joursAffiches'         => $joursAffiches,
            'ordreMax'              => $ordreMax,
        ]);
    }

    /** Export PDF de imprimerEnseignants() — une page par enseignant (dompdf, cf. exportPdf()). */
    #[Route('/imprimer/enseignants/export-pdf', name: 'imprimer_enseignants_export_pdf')]
    public function exportPdfEnseignants(
        AnneeScolaireRepository $anneeRepo,
        EnseignantRepository $enseignantRepo,
        SeanceRepository $seanceRepo,
        CreneauRepository $creneauRepo,
        EmploiDuTempsPdfExporter $exporter,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $annee   = $anneeRepo->findActive();
        $seances = $annee ? $seanceRepo->findByAnneeScolaire((int) $annee->getId()) : [];

        $seancesParEnseignant = [];
        foreach ($seances as $seance) {
            $seancesParEnseignant[$seance->getAttribution()->getEnseignant()->getId()][] = $seance;
        }

        $enseignants = array_values(array_filter(
            $enseignantRepo->findActifs(),
            static fn ($e) => isset($seancesParEnseignant[$e->getId()]),
        ));

        $pages = [];
        foreach ($enseignants as $enseignant) {
            $pages[] = [
                'titre'    => 'Emploi du temps — '.$enseignant->getNomComplet().($annee ? ' — '.$annee->getLibelle() : ''),
                'grille'   => $grilleBuilder->regrouperParCreneau($seancesParEnseignant[$enseignant->getId()]),
                'contexte' => 'enseignant',
            ];
        }

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        $html = $this->renderView('admin/edt/pdf/grilles.html.twig', [
            'pages'           => $pages,
            'creneauxParJour' => $creneauxParJour,
            'joursAffiches'   => $joursAffiches,
            'ordreMax'        => $ordreMax,
        ]);

        return $this->reponsePdf($exporter->exporter($html), 'emploi-du-temps-tous-les-enseignants.pdf');
    }

    private function reponsePdf(string $contenu, string $nomFichier): Response
    {
        return new Response($contenu, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nomFichier.'"',
        ]);
    }

    private function nomFichier(string $titre): string
    {
        return (new AsciiSlugger())->slug($titre)->lower().'.pdf';
    }

    /**
     * Vue globale : toutes les classes de l'année active côte à côte, une ligne par
     * créneau — reproduit le format du document papier officiel (code matière seul,
     * plages réservées type "DEVOIR"/"PLEINAIRE" fusionnées sur toutes les colonnes).
     *
     * Chaque séance affichée porte les attributs `data-*` nécessaires au contrôleur
     * Stimulus `edt-globale` (permutation manuelle par glisser-déposer ou double clic) :
     * identifiants classe/créneau/enseignant/salle, code matière, et si elle fait partie
     * d'un regroupement de classes fusionnées (non déplaçable seule, cf.
     * EmploiDuTempsPermutationService).
     */
    #[Route('/globale', name: 'globale')]
    public function globale(
        AnneeScolaireRepository $anneeRepo,
        ClasseRepository $classeRepo,
        CreneauRepository $creneauRepo,
        SeanceRepository $seanceRepo,
        RegroupementClasseRepository $regroupementRepo,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $annee   = $anneeRepo->findActive();
        $classes = $annee ? $classeRepo->findByAnneeScolaireActive() : [];
        $seances = $annee ? $seanceRepo->findByAnneeScolaire((int) $annee->getId()) : [];

        $regroupementParClasseEtMatiere = $regroupementRepo->indexerParClasseEtMatiere();

        // grille[jour][ordre][classeId] = Seance[] (plusieurs si matières parallèles, ex. ALL/ESP)
        $grille = [];
        foreach ($seances as $seance) {
            $creneau  = $seance->getCreneau();
            $classeId = $seance->getAttribution()->getClasse()->getId();
            $grille[$creneau->getJourSemaine()->value][$creneau->getOrdre()][$classeId][] = $seance;
        }

        [$creneauxParJour, $joursAffiches] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        [$reserveRowspan, $reserveContinuation] = $this->calculerRunsReserves($creneauxParJour);

        return $this->render('admin/edt/globale.html.twig', [
            'annee'                          => $annee,
            'classes'                        => $classes,
            'grille'                         => $grille,
            'creneauxParJour'                => $creneauxParJour,
            'joursAffiches'                  => $joursAffiches,
            'reserveRowspan'                 => $reserveRowspan,
            'reserveContinuation'            => $reserveContinuation,
            'regroupementParClasseEtMatiere' => $regroupementParClasseEtMatiere,
        ]);
    }

    /**
     * Applique un lot de permutations manuelles proposées depuis la vue globale
     * (glisser-déposer ou double clic) : { changes: [{seanceId, creneauId}, ...] }.
     * Toute la validation métier (conflits enseignant/salle/classe, règles EPS/FHR/8ème
     * heure, classes fusionnées) est déléguée à EmploiDuTempsPermutationService, seule
     * source de vérité — jamais uniquement le calcul côté client, qui n'est qu'une aide
     * visuelle.
     */
    #[Route('/globale/permuter', name: 'globale_permuter', methods: ['POST'])]
    public function permuterGlobale(
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        EmploiDuTempsPermutationService $permutationService,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Requête invalide.']], 400);
        }

        if (!$this->isCsrfTokenValid('edt_globale_permuter', (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Jeton de sécurité invalide, veuillez recharger la page.']], 403);
        }

        $annee = $anneeRepo->findActive();
        if ($annee === null) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Aucune année scolaire active.']], 422);
        }

        $creneauParSeanceId = [];
        foreach ((array) ($payload['changes'] ?? []) as $changement) {
            $seanceId  = (int) ($changement['seanceId'] ?? 0);
            $creneauId = (int) ($changement['creneauId'] ?? 0);
            if ($seanceId > 0 && $creneauId > 0) {
                $creneauParSeanceId[$seanceId] = $creneauId;
            }
        }

        $resultat = $permutationService->appliquer($annee, $creneauParSeanceId);

        return new JsonResponse(
            ['succes' => $resultat->succes, 'erreurs' => $resultat->erreurs],
            $resultat->succes ? 200 : 422,
        );
    }

    /** Export PDF de la vue globale — même mise en page compacte 1 page que l'impression navigateur. */
    #[Route('/globale/export-pdf', name: 'globale_export_pdf')]
    public function exportPdfGlobale(
        AnneeScolaireRepository $anneeRepo,
        ClasseRepository $classeRepo,
        CreneauRepository $creneauRepo,
        SeanceRepository $seanceRepo,
        EmploiDuTempsPdfExporter $exporter,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $annee   = $anneeRepo->findActive();
        $classes = $annee ? $classeRepo->findByAnneeScolaireActive() : [];
        $seances = $annee ? $seanceRepo->findByAnneeScolaire((int) $annee->getId()) : [];

        $grille = [];
        foreach ($seances as $seance) {
            $creneau  = $seance->getCreneau();
            $classeId = $seance->getAttribution()->getClasse()->getId();
            $grille[$creneau->getJourSemaine()->value][$creneau->getOrdre()][$classeId][] = $seance;
        }

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);
        [$reserveRowspan, $reserveContinuation]        = $this->calculerRunsReserves($creneauxParJour);

        $html = $this->renderView('admin/edt/pdf/globale.html.twig', [
            'annee'               => $annee,
            'classes'             => $classes,
            'grille'              => $grille,
            'creneauxParJour'     => $creneauxParJour,
            'joursAffiches'       => $joursAffiches,
            'reserveRowspan'      => $reserveRowspan,
            'reserveContinuation' => $reserveContinuation,
        ]);

        return $this->reponsePdf($exporter->exporter($html), 'emploi-du-temps-vue-globale.pdf');
    }

    /**
     * Détecte les créneaux réservés consécutifs (même jour, même libellé, ordre qui se
     * suit) pour les fusionner visuellement en une seule cellule sur plusieurs lignes
     * (ex. DEVOIR/PLEINAIRE mardi/mercredi, qui occupent les 6ᵉ et 7ᵉ heures) plutôt que
     * de répéter le libellé sur chaque ligne.
     *
     * @param array<string, array<int, Creneau>> $creneauxParJour
     * @return array{0: array<string, array<int, int>>, 1: array<string, array<int, true>>}
     */
    private function calculerRunsReserves(array $creneauxParJour): array
    {
        $rowspan      = [];
        $continuation = [];

        foreach ($creneauxParJour as $jour => $parOrdre) {
            ksort($parOrdre);
            $ordres = array_keys($parOrdre);
            $n      = count($ordres);
            $i      = 0;

            while ($i < $n) {
                $ordre   = $ordres[$i];
                $creneau = $parOrdre[$ordre];

                if (!$creneau->isReserve()) {
                    $i++;
                    continue;
                }

                $longueur = 1;
                while (
                    $i + $longueur < $n
                    && $ordres[$i + $longueur] === $ordre + $longueur
                    && $parOrdre[$ordres[$i + $longueur]]->isReserve()
                    && $parOrdre[$ordres[$i + $longueur]]->getLibelleReserve() === $creneau->getLibelleReserve()
                ) {
                    $longueur++;
                }

                $rowspan[$jour][$ordre] = $longueur;
                for ($k = 1; $k < $longueur; $k++) {
                    $continuation[$jour][$ordres[$i + $k]] = true;
                }

                $i += $longueur;
            }
        }

        return [$rowspan, $continuation];
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
