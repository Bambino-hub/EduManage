<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\SalleRepository;
use App\Scheduling\Entity\Creneau;
use App\Scheduling\Entity\EmploiDuTempsVersion;
use App\Scheduling\Entity\Seance;
use App\Scheduling\Enum\OrigineVersionEdt;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Repository\EmploiDuTempsVersionRepository;
use App\Scheduling\Repository\RegroupementClasseRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Scheduling\Service\EmploiDuTempsGenerator;
use App\Scheduling\Service\EmploiDuTempsHistorique;
use App\Scheduling\Service\GrilleEmploiDuTempsBuilder;
use App\Scheduling\Service\EmploiDuTempsPermutationService;
use App\Scheduling\Service\EmploiDuTempsPersonnalisationService;
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
            'avecEntete'      => $request->query->getBoolean('entete_college', false),
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
        Request $request,
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
            'avecEntete'      => $request->query->getBoolean('entete_college', false),
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
        Request $request,
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
            'avecEntete'      => $request->query->getBoolean('entete_college', false),
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

    /**
     * « Personnaliser » : grille interactive d'UN enseignant — pour choisir ses séances à
     * verrouiller (et, si besoin, les déplacer avant de les verrouiller). Le déplacement
     * réutilise directement /globale/permuter (EmploiDuTempsPermutationService, déjà
     * générique — il ne dépend pas de la vue globale), pas de service dédié. Une fois
     * verrouillée, une séance survient telle quelle à une « Réorganisation »
     * (EmploiDuTempsGenerator::reorganiser(), cf. generate()/reorganize()).
     */
    #[Route('/personnaliser', name: 'personnaliser')]
    public function personnaliser(
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        EnseignantRepository $enseignantRepo,
        SeanceRepository $seanceRepo,
        CreneauRepository $creneauRepo,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        $annee       = $anneeRepo->findActive();
        $enseignants = $enseignantRepo->findActifs();

        $enseignantId  = $request->query->getInt('enseignant') ?: null;
        $enseignantObj = $enseignantId ? $enseignantRepo->find($enseignantId) : null;

        $seances = ($annee && $enseignantObj)
            ? $seanceRepo->findByEnseignantEtAnnee($enseignantId, (int) $annee->getId())
            : [];

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        return $this->render('admin/edt/personnaliser.html.twig', [
            'annee'           => $annee,
            'enseignants'     => $enseignants,
            'enseignantObj'   => $enseignantObj,
            'grille'          => $grilleBuilder->regrouperParCreneau($seances),
            'creneauxParJour' => $creneauxParJour,
            'joursAffiches'   => $joursAffiches,
            'ordreMax'        => $ordreMax,
        ]);
    }

    /**
     * Déplace librement une séance (et sa cascade fusion/parallèle) vers n'importe quel
     * créneau — SANS vérification de conflit enseignant/salle/classe, cf. la docblock
     * d'EmploiDuTempsPersonnalisationService::deplacer(). Appelé en AJAX depuis
     * personnaliser() ; volontairement une route dédiée, distincte de
     * /globale/permuter (EmploiDuTempsPermutationService) qui reste strictement validée
     * pour la vue globale.
     */
    #[Route('/personnaliser/deplacer', name: 'personnaliser_deplacer', methods: ['POST'])]
    public function personnaliserDeplacer(
        Request $request,
        SeanceRepository $seanceRepo,
        EmploiDuTempsPersonnalisationService $personnalisationService,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Requête invalide.']], 400);
        }

        if (!$this->isCsrfTokenValid('edt_personnaliser_deplacer', (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Jeton de sécurité invalide, veuillez recharger la page.']], 403);
        }

        $seance = $seanceRepo->find((int) ($payload['seanceId'] ?? 0));
        if ($seance === null) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Séance introuvable.']], 404);
        }

        $resultat = $personnalisationService->deplacer($seance, (int) ($payload['creneauId'] ?? 0));

        return new JsonResponse(
            ['succes' => $resultat['succes'], 'erreurs' => $resultat['erreurs']],
            $resultat['succes'] ? 200 : 422,
        );
    }

    /**
     * Verrouille/déverrouille une séance (et sa cascade fusion/parallèle, cf.
     * EmploiDuTempsPersonnalisationService) — appelé en AJAX depuis personnaliser().
     */
    #[Route('/personnaliser/verrouiller', name: 'personnaliser_verrouiller', methods: ['POST'])]
    public function personnaliserVerrouiller(
        Request $request,
        SeanceRepository $seanceRepo,
        EmploiDuTempsPersonnalisationService $personnalisationService,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Requête invalide.']], 400);
        }

        if (!$this->isCsrfTokenValid('edt_personnaliser_verrouiller', (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Jeton de sécurité invalide, veuillez recharger la page.']], 403);
        }

        $seance = $seanceRepo->find((int) ($payload['seanceId'] ?? 0));
        if ($seance === null) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Séance introuvable.']], 404);
        }

        $verrouille = (bool) ($payload['verrouille'] ?? true);
        $resultat   = $personnalisationService->basculerVerrou($seance, $verrouille);

        return new JsonResponse(
            [
                'succes'     => $resultat['succes'],
                'erreurs'    => $resultat['erreurs'],
                'verrouille' => $verrouille,
                'seanceIds'  => array_map(static fn (Seance $s) => $s->getId(), $resultat['seances']),
            ],
            $resultat['succes'] ? 200 : 422,
        );
    }

    /**
     * Export PDF de la vue globale — même mise en page compacte que l'impression
     * navigateur. `?affichage=matiere` (défaut, 1 page A4 paysage, code matière seul) ou
     * `?affichage=enseignant` (matière + nom de l'enseignant par case, 1 page A3 paysage :
     * ~27 colonnes avec ce texte en plus ne tiennent plus lisiblement en A4).
     */
    #[Route('/globale/export-pdf', name: 'globale_export_pdf')]
    public function exportPdfGlobale(
        Request $request,
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

        $avecEnseignant = $request->query->getString('affichage', 'matiere') === 'enseignant';
        // Budget relevé à 22 (au lieu de 15) pour tenir sur 2 pages max, en-tête compris,
        // plutôt que 3 : retesté empiriquement (rendu réel + pdfinfo + inspection visuelle
        // des 2 variantes matière/enseignant, avec et sans en-tête) après resserrement du
        // CSS de globale.html.twig (paddings, line-height, en-tête local plus compact —
        // cf. `.pdf-globale-entete` dans ce template). Cf. calculerSautsDePage().
        $joursSautDePage = $this->calculerSautsDePage($creneauxParJour, $joursAffiches, 22);

        $html = $this->renderView('admin/edt/pdf/globale.html.twig', [
            'annee'               => $annee,
            'classes'             => $classes,
            'grille'              => $grille,
            'creneauxParJour'     => $creneauxParJour,
            'joursAffiches'       => $joursAffiches,
            'reserveRowspan'      => $reserveRowspan,
            'reserveContinuation' => $reserveContinuation,
            'joursSautDePage'     => $joursSautDePage,
            'avecEntete'          => $request->query->getBoolean('entete_college', false),
            'avecEnseignant'      => $avecEnseignant,
        ]);

        $contenu   = $exporter->exporter($html, 'landscape', $avecEnseignant ? 'A3' : 'A4');
        $nomFichier = $avecEnseignant ? 'emploi-du-temps-vue-globale-detaillee.pdf' : 'emploi-du-temps-vue-globale.pdf';

        return $this->reponsePdf($contenu, $nomFichier);
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

    /**
     * Détermine, pour l'export PDF de la vue globale, avant quels jours forcer un saut de
     * page (`JourSemaine::value => bool`) — un jour ne tient JAMAIS à cheval sur 2 pages
     * (dompdf coupe alors le rowspan de la colonne "Jour" et désynchronise les lignes
     * suivantes, bug constaté). On regroupe donc autant de jours que le budget de lignes
     * le permet par page plutôt que d'imposer 1 jour = 1 page (qui gâche beaucoup de
     * papier) : dès qu'un jour de plus dépasserait le budget, on force la page suivante.
     * `$budgetLignes` est volontairement conservateur (estimé empiriquement pour rester
     * sûr même avec des cases sur 2 lignes) plutôt que calculé au pixel près.
     *
     * @param array<string, array<int, Creneau>> $creneauxParJour
     * @param JourSemaine[] $joursAffiches
     * @return array<string, bool>
     */
    private function calculerSautsDePage(array $creneauxParJour, array $joursAffiches, int $budgetLignes): array
    {
        $sauts               = [];
        $lignesPageActuelle  = 0;

        foreach ($joursAffiches as $i => $jour) {
            $lignesJour = count($creneauxParJour[$jour->value] ?? []);

            if ($i > 0 && $lignesPageActuelle + $lignesJour > $budgetLignes) {
                $sauts[$jour->value] = true;
                $lignesPageActuelle  = 0;
            }

            $lignesPageActuelle += $lignesJour;
        }

        return $sauts;
    }

    #[Route('/generer', name: 'generate', methods: ['GET', 'POST'])]
    public function generate(
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        AttributionRepository $attributionRepo,
        CreneauRepository $creneauRepo,
        SalleRepository $salleRepo,
        SeanceRepository $seanceRepo,
        EmploiDuTempsGenerator $generator,
        EmploiDuTempsHistorique $historique,
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

            // Filet de sécurité : la génération PURGE l'emploi du temps existant
            // avant de recalculer. On en dépose donc un instantané dans l'historique
            // AVANT de lancer le générateur — inutile de penser à « Enregistrer
            // l'état actuel » à la main. Ne fait rien s'il n'y a encore aucune séance.
            $historique->capturer(
                $annee,
                OrigineVersionEdt::PreGeneration,
                'Emploi du temps existant, sauvegardé avant la génération du '
                    .(new \DateTimeImmutable())->format('d/m/Y à H\hi'),
            );

            $resultat = $generator->generer($annee);

            // Instantané de l'emploi du temps fraîchement généré : il rejoint
            // l'historique (borné à EmploiDuTempsHistorique::MAX_VERSIONS) pour
            // pouvoir y revenir plus tard même après une nouvelle génération.
            $historique->capturer(
                $annee,
                OrigineVersionEdt::Generation,
                sprintf(
                    'Génération du %s — %dh placées%s',
                    (new \DateTimeImmutable())->format('d/m/Y à H\hi'),
                    $resultat->heuresPlacees,
                    $resultat->heuresNonPlacees > 0 ? sprintf(', %dh non placées', $resultat->heuresNonPlacees) : '',
                ),
                $resultat,
            );

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
            'annee'                 => $annee,
            'resultat'              => $resultat,
            'mode'                  => 'generer',
            'nbAttributions'        => $annee ? count($attributionRepo->findByAnneeScolaire((int) $annee->getId())) : 0,
            'nbCreneaux'            => count($creneauRepo->findOrdonnes()),
            'nbSalles'              => count($salleRepo->findAll()),
            'nbSeancesVerrouillees' => $annee ? count($seanceRepo->findVerrouilleesByAnneeScolaire((int) $annee->getId())) : 0,
        ]);
    }

    /**
     * « Réorganiser » : recalcule l'emploi du temps en respectant les séances
     * verrouillées (personnalisées, cf. EmploiDuTempsPersonnalisationService) — contrairement
     * à generate() qui repart entièrement de zéro. Même page de rapport (generate.html.twig),
     * mêmes filets de sécurité (instantané historique avant/après, cf. commentaires de
     * generate()).
     */
    #[Route('/reorganiser', name: 'reorganize', methods: ['POST'])]
    public function reorganize(
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        AttributionRepository $attributionRepo,
        CreneauRepository $creneauRepo,
        SalleRepository $salleRepo,
        SeanceRepository $seanceRepo,
        EmploiDuTempsGenerator $generator,
        EmploiDuTempsHistorique $historique,
    ): Response {
        $annee = $anneeRepo->findActive();

        if (!$this->isCsrfTokenValid('reorganiser_edt', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_edt_generate');
        }

        if ($annee === null) {
            $this->addFlash('error', 'Aucune année scolaire active. Activez une année avant de réorganiser.');
            return $this->redirectToRoute('admin_edt_generate');
        }

        $historique->capturer(
            $annee,
            OrigineVersionEdt::PreGeneration,
            'Emploi du temps existant, sauvegardé avant réorganisation du '
                .(new \DateTimeImmutable())->format('d/m/Y à H\hi'),
        );

        $resultat = $generator->reorganiser($annee);

        $historique->capturer(
            $annee,
            OrigineVersionEdt::Generation,
            sprintf(
                'Réorganisation du %s — %dh placées%s',
                (new \DateTimeImmutable())->format('d/m/Y à H\hi'),
                $resultat->heuresPlacees,
                $resultat->heuresNonPlacees > 0 ? sprintf(', %dh non placées', $resultat->heuresNonPlacees) : '',
            ),
            $resultat,
        );

        if ($resultat->succes()) {
            $this->addFlash('success', sprintf(
                'Emploi du temps réorganisé : %d heures placées sans conflit (personnalisations respectées).',
                $resultat->heuresPlacees,
            ));
        } elseif ($resultat->heuresPlacees > 0) {
            $this->addFlash('warning', sprintf(
                'Réorganisation partielle : %d heures placées, %d non placées (voir détail ci-dessous).',
                $resultat->heuresPlacees,
                $resultat->heuresNonPlacees,
            ));
        } else {
            $this->addFlash('error', 'Rien n\'a pu être réorganisé (vérifiez les attributions, salles et créneaux).');
        }

        return $this->render('admin/edt/generate.html.twig', [
            'annee'                 => $annee,
            'resultat'              => $resultat,
            'mode'                  => 'reorganiser',
            'nbAttributions'        => count($attributionRepo->findByAnneeScolaire((int) $annee->getId())),
            'nbCreneaux'            => count($creneauRepo->findOrdonnes()),
            'nbSalles'              => count($salleRepo->findAll()),
            'nbSeancesVerrouillees' => count($seanceRepo->findVerrouilleesByAnneeScolaire((int) $annee->getId())),
        ]);
    }

    /**
     * Historique des emplois du temps de l'année active : chaque génération auto (et
     * chaque enregistrement manuel) y dépose un instantané restaurable. L'historique
     * est borné à EmploiDuTempsHistorique::MAX_VERSIONS entrées par année.
     */
    #[Route('/historique', name: 'historique')]
    public function historique(
        AnneeScolaireRepository $anneeRepo,
        EmploiDuTempsVersionRepository $versionRepo,
        SeanceRepository $seanceRepo,
    ): Response {
        $annee    = $anneeRepo->findActive();
        $versions = $annee ? $versionRepo->findByAnnee((int) $annee->getId()) : [];

        return $this->render('admin/edt/historique.html.twig', [
            'annee'        => $annee,
            'versions'     => $versions,
            'maxVersions'  => EmploiDuTempsHistorique::MAX_VERSIONS,
            'nbSeancesNow' => $annee ? count($seanceRepo->findByAnneeScolaire((int) $annee->getId())) : 0,
        ]);
    }

    /** Enregistre l'état actuel de l'emploi du temps dans l'historique (bouton « Enregistrer l'état actuel »). */
    #[Route('/historique/enregistrer', name: 'historique_enregistrer', methods: ['POST'])]
    public function historiqueEnregistrer(
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        EmploiDuTempsHistorique $historique,
    ): Response {
        if (!$this->isCsrfTokenValid('edt_historique', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_edt_historique');
        }

        $annee = $anneeRepo->findActive();
        if ($annee === null) {
            $this->addFlash('error', 'Aucune année scolaire active.');
            return $this->redirectToRoute('admin_edt_historique');
        }

        $libelle = trim($request->getPayload()->getString('libelle'))
            ?: 'Enregistrement du '.(new \DateTimeImmutable())->format('d/m/Y à H\hi');

        $version = $historique->capturer($annee, OrigineVersionEdt::Manuel, $libelle);

        $this->addFlash(
            $version ? 'success' : 'warning',
            $version
                ? 'État actuel enregistré dans l\'historique.'
                : 'Aucune séance à enregistrer pour le moment.',
        );

        return $this->redirectToRoute('admin_edt_historique');
    }

    /** Restaure une version : l'état courant est sauvegardé, puis remplacé par celui de la version choisie. */
    #[Route('/historique/{id}/restaurer', name: 'historique_restaurer', methods: ['POST'])]
    public function historiqueRestaurer(
        Request $request,
        EmploiDuTempsVersion $version,
        EmploiDuTempsHistorique $historique,
    ): Response {
        if (!$this->isCsrfTokenValid('edt_historique', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_edt_historique');
        }

        $recreees = $historique->restaurer($version);

        $this->addFlash('success', sprintf(
            'Emploi du temps restauré : %d séance(s) rétablie(s). L\'état précédent a été sauvegardé dans l\'historique.',
            $recreees,
        ));

        return $this->redirectToRoute('admin_edt_index');
    }

    /** Supprime une entrée de l'historique. */
    #[Route('/historique/{id}/supprimer', name: 'historique_supprimer', methods: ['POST'])]
    public function historiqueSupprimer(
        Request $request,
        EmploiDuTempsVersion $version,
        EmploiDuTempsHistorique $historique,
    ): Response {
        if (!$this->isCsrfTokenValid('edt_historique', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_edt_historique');
        }

        $historique->supprimer($version);
        $this->addFlash('success', 'Entrée supprimée de l\'historique.');

        return $this->redirectToRoute('admin_edt_historique');
    }
}
