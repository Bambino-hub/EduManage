<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Cycle;
use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\CycleRepository;
use App\Exam\Entity\Examen;
use App\Exam\Repository\RegroupementSurveillanceRepository;
use App\Exam\Repository\SurveillanceRepository;
use App\Exam\Service\ExamenSurveillanceGenerator;
use App\Exam\Service\ExamGridBuilder;
use App\Exam\Service\SurveillancePermutationService;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(name: 'admin_surveillance_')]
class SurveillanceController extends AbstractController
{
    #[Route('/admin/surveillance', name: 'index')]
    public function index(CycleRepository $cycleRepo): Response
    {
        return $this->render('admin/surveillance/index.html.twig', [
            'cycles' => $cycleRepo->findBy([], ['id' => 'ASC']),
        ]);
    }

    #[Route('/admin/surveillance/cycle/{cycle}/tableau', name: 'tableau')]
    public function tableau(
        Cycle $cycle,
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        ExamGridBuilder $gridBuilder,
        SurveillanceRepository $surveillanceRepo,
        ClasseRepository $classeRepo,
        RegroupementSurveillanceRepository $regroupementRepo,
    ): Response {
        $annee  = $anneeRepo->findActive();
        $lignes = $annee ? $gridBuilder->construireLignes($cycle, $annee) : [];

        [$classesParNiveau, $surveillancesParExamenClasse] = $this->construireDonneesAffichage($lignes, $surveillanceRepo, $classeRepo);

        return $this->render('admin/surveillance/tableau.html.twig', [
            'cycle'                          => $cycle,
            'annee'                          => $annee,
            'lignes'                         => $lignes,
            'classesParNiveau'               => $classesParNiveau,
            'surveillancesParExamenClasse'   => $surveillancesParExamenClasse,
            'groupeParClasseId'              => $regroupementRepo->findGroupeParClasseId(),
            'entete'                         => $request->query->getString('entete', ''),
        ]);
    }

    /**
     * Applique un lot de permutations manuelles proposées depuis le tableau (glisser-déposer) :
     * { changes: [{surveillanceId, classeId}, ...] }. Toute la validation métier (regroupement,
     * classe hors périmètre de l'examen, doublon d'enseignant) est déléguée à
     * SurveillancePermutationService, seule source de vérité — jamais uniquement le calcul
     * côté client, qui n'est qu'une aide visuelle.
     */
    #[Route('/admin/surveillance/permuter', name: 'permuter', methods: ['POST'])]
    public function permuter(Request $request, SurveillancePermutationService $permutationService): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Requête invalide.']], 400);
        }

        if (!$this->isCsrfTokenValid('permuter_surveillance', (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse(['succes' => false, 'erreurs' => ['Jeton de sécurité invalide, veuillez recharger la page.']], 403);
        }

        $classeParSurveillanceId = [];
        foreach ((array) ($payload['changes'] ?? []) as $changement) {
            $surveillanceId = (int) ($changement['surveillanceId'] ?? 0);
            $classeId       = (int) ($changement['classeId'] ?? 0);
            if ($surveillanceId > 0 && $classeId > 0) {
                $classeParSurveillanceId[$surveillanceId] = $classeId;
            }
        }

        $resultat = $permutationService->appliquer($classeParSurveillanceId);

        return new JsonResponse(
            ['succes' => $resultat->succes, 'erreurs' => $resultat->erreurs],
            $resultat->succes ? 200 : 422,
        );
    }

    /**
     * Génère TOUJOURS les deux cycles en un seul passage, même déclenché depuis la page d'un
     * seul cycle (le paramètre {cycle} ne sert qu'à savoir où rediriger l'utilisateur ensuite).
     * Générer cycle par cycle indépendamment favorisait systématiquement le cycle lancé en
     * premier au détriment de l'autre pour les enseignants partagés ("1/2") — mesuré et abandonné,
     * voir ExamenSurveillanceGenerator.
     */
    #[Route('/admin/surveillance/cycle/{cycle}/generer', name: 'generate', methods: ['POST'])]
    public function generate(
        Cycle $cycle,
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        ExamenSurveillanceGenerator $generator,
    ): Response {
        if (!$this->isCsrfTokenValid('generer_surveillance', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_surveillance_tableau', ['cycle' => $cycle->getId()]);
        }

        $annee = $anneeRepo->findActive();
        if ($annee === null) {
            $this->addFlash('error', 'Aucune année scolaire active.');
            return $this->redirectToRoute('admin_surveillance_tableau', ['cycle' => $cycle->getId()]);
        }

        $resultat = $generator->genererPourAnnee($annee);

        if ($resultat->succes()) {
            $this->addFlash('success', sprintf(
                'Tableau de surveillance généré pour les deux cycles : %d postes pourvus sur %d.',
                $resultat->surveillancesCreees,
                $resultat->postesRequis,
            ));
        } elseif ($resultat->surveillancesCreees > 0) {
            $this->addFlash('warning', sprintf(
                'Génération partielle (deux cycles) : %d postes pourvus sur %d (voir détail ci-dessous).',
                $resultat->surveillancesCreees,
                $resultat->postesRequis,
            ));
        } else {
            $this->addFlash('error', 'Rien n\'a pu être généré (vérifiez les examens et les enseignants disponibles).');
        }

        return $this->redirectToRoute('admin_surveillance_tableau', ['cycle' => $cycle->getId()]);
    }

    #[Route('/admin/surveillance/cycle/{cycle}/export-pdf', name: 'export_pdf')]
    public function exportPdf(
        Cycle $cycle,
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        ExamGridBuilder $gridBuilder,
        SurveillanceRepository $surveillanceRepo,
        ClasseRepository $classeRepo,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $annee  = $anneeRepo->findActive();
        $lignes = $annee ? $gridBuilder->construireLignes($cycle, $annee) : [];

        [$classesParNiveau, $surveillancesParExamenClasse] = $this->construireDonneesAffichage($lignes, $surveillanceRepo, $classeRepo);

        $html = $this->renderView('admin/surveillance/pdf/tableau.html.twig', [
            'cycle'                        => $cycle,
            'annee'                        => $annee,
            'lignes'                       => $lignes,
            'classesParNiveau'             => $classesParNiveau,
            'surveillancesParExamenClasse' => $surveillancesParExamenClasse,
            'entete'                       => $request->query->getString('entete', ''),
        ]);

        return new Response($exporter->exporter($html), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tableau-surveillance-'.$cycle->getId().'.pdf"',
        ]);
    }

    /**
     * @param \App\Exam\Service\Dto\GrilleLigne[] $lignes
     * @return array{0: array<int, \App\Academic\Entity\Classe[]>, 1: array<int, array<int, \App\Exam\Entity\Surveillance[]>>}
     */
    private function construireDonneesAffichage(array $lignes, SurveillanceRepository $surveillanceRepo, ClasseRepository $classeRepo): array
    {
        $classesParNiveau = [];
        foreach ($classeRepo->findByAnneeScolaireActive() as $classe) {
            $classesParNiveau[$classe->getNiveau()->getId()][] = $classe;
        }

        $examenIds = [];
        foreach ($lignes as $ligne) {
            foreach ($ligne->examensParNiveau as $examens) {
                foreach ($examens as $examen) {
                    /** @var Examen $examen */
                    $examenIds[$examen->getId()] = true;
                }
            }
        }

        $surveillancesParExamenClasse = [];
        foreach ($surveillanceRepo->findByExamens(array_keys($examenIds)) as $surveillance) {
            $surveillancesParExamenClasse[$surveillance->getExamen()->getId()][$surveillance->getClasse()->getId()][] = $surveillance;
        }

        return [$classesParNiveau, $surveillancesParExamenClasse];
    }
}
