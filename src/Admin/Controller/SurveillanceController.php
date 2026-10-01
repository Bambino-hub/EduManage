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
use App\Exam\Service\ExamenClassesResolver;
use App\Exam\Service\ExamenSurveillanceGenerator;
use App\Exam\Service\ExamGridBuilder;
use App\Exam\Service\SurveillancePermutationService;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use App\Staff\Entity\Enseignant;
use App\Staff\Repository\EnseignantRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        ExamenClassesResolver $classesResolver,
    ): Response {
        $annee  = $anneeRepo->findActive();
        $lignes = $annee ? $gridBuilder->construireLignes($cycle, $annee) : [];

        [$cellules, $surveillancesParExamenClasse] = $this->construireDonneesAffichage($lignes, $surveillanceRepo, $classeRepo, $classesResolver);

        return $this->render('admin/surveillance/tableau.html.twig', [
            'cycle'                          => $cycle,
            'niveaux'                        => $gridBuilder->niveauxAffiches($cycle),
            'annee'                          => $annee,
            'lignes'                         => $lignes,
            'cellules'                       => $cellules,
            'surveillancesParExamenClasse'   => $surveillancesParExamenClasse,
            'groupeParClasseId'              => $regroupementRepo->findGroupeParClasseId(),
            'entete'                         => $request->query->getString('entete', ''),
        ]);
    }

    /**
     * Applique un lot de permutations manuelles proposées depuis le tableau (glisser-déposer) :
     * { changes: [{surveillanceId, examenId, classeId}, ...] } — la cible peut appartenir à un
     * AUTRE examen que celui d'origine. Toute la validation métier (regroupement, classe hors
     * périmètre de l'examen, doublon d'enseignant, disponibilité au nouvel horaire) est déléguée
     * à SurveillancePermutationService, seule source de vérité — jamais uniquement le calcul
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

        $cibleParSurveillanceId = [];
        foreach ((array) ($payload['changes'] ?? []) as $changement) {
            $surveillanceId = (int) ($changement['surveillanceId'] ?? 0);
            $classeId       = (int) ($changement['classeId'] ?? 0);
            $examenId       = (int) ($changement['examenId'] ?? 0);
            if ($surveillanceId > 0 && $classeId > 0 && $examenId > 0) {
                $cibleParSurveillanceId[$surveillanceId] = ['examenId' => $examenId, 'classeId' => $classeId];
            }
        }

        $resultat = $permutationService->appliquer($cibleParSurveillanceId);

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
            $this->addFlash('error', 'Rien n\'a pu être généré (vérifiez les examens et les enseignants autorisés à surveiller).');
        }

        if ($resultat->successions !== []) {
            $this->addFlash('warning', sprintf(
                '%d surveillance(s) successive(s) n\'ont pas pu être évitées, faute d\'autre surveillant disponible : %s%s',
                count($resultat->successions),
                implode(' ; ', array_slice($resultat->successions, 0, 8)),
                count($resultat->successions) > 8 ? ' ; …' : '.',
            ));
        }

        return $this->redirectToRoute('admin_surveillance_tableau', ['cycle' => $cycle->getId()]);
    }

    /**
     * Récapitulatif de tous les surveillants (pool éligible complet, y compris ceux à 0) avec
     * leur nombre total de surveillances sur l'année — trié du plus chargé au moins chargé pour
     * repérer un déséquilibre d'un coup d'œil.
     */
    #[Route('/admin/surveillance/recapitulatif', name: 'recapitulatif')]
    public function recapitulatif(EnseignantRepository $enseignantRepo, SurveillanceRepository $surveillanceRepo): Response
    {
        $charges = $surveillanceRepo->compterParEnseignant();

        $lignes = array_map(
            static fn($enseignant) => [
                'enseignant' => $enseignant,
                'charge'     => $charges[$enseignant->getId()] ?? 0,
            ],
            $enseignantRepo->findEligiblesSurveillance(),
        );

        usort($lignes, static fn(array $a, array $b) => $b['charge'] <=> $a['charge'] ?: $a['enseignant']->getNom() <=> $b['enseignant']->getNom());

        $charges = array_column($lignes, 'charge');

        return $this->render('admin/surveillance/recapitulatif.html.twig', [
            'lignes'  => $lignes,
            'total'   => array_sum($charges),
            'moyenne' => $charges !== [] ? array_sum($charges) / count($charges) : 0,
            'min'     => $charges !== [] ? min($charges) : 0,
            'max'     => $charges !== [] ? max($charges) : 0,
        ]);
    }

    /**
     * Réglages de surveillance des personnes autorisées à surveiller dans ce cycle, accessibles
     * depuis le programme des devoirs : période de disponibilité (un stagiaire présent un temps
     * donné n'est plus programmé en dehors) et demi-charge. Les réglages appartiennent à la
     * personne, pas au cycle : un enseignant partagé ("1/2") apparaît sur les deux pages avec
     * les mêmes valeurs. Pris en compte à la prochaine génération (ExamenSurveillanceGenerator).
     */
    #[Route('/admin/surveillance/cycle/{cycle}/disponibilites', name: 'disponibilites')]
    public function disponibilites(Cycle $cycle, Request $request, EnseignantRepository $enseignantRepo, EntityManagerInterface $em): Response
    {
        $surveillants = array_values(array_filter(
            $enseignantRepo->findEligiblesSurveillance(),
            static fn(Enseignant $e) => in_array(trim((string) $e->getCycle()), ['', '1/2', (string) $cycle->getId()], true),
        ));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('disponibilites_surveillance', $request->getPayload()->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
                return $this->redirectToRoute('admin_surveillance_disponibilites', ['cycle' => $cycle->getId()]);
            }

            $saisie  = $request->getPayload()->all('surveillants');
            $erreurs = [];
            foreach ($surveillants as $enseignant) {
                $ligne = (array) ($saisie[$enseignant->getId()] ?? []);
                $du    = self::lireDate($ligne['du'] ?? '');
                $au    = self::lireDate($ligne['au'] ?? '');

                if ($du !== null && $au !== null && $du > $au) {
                    $erreurs[] = $enseignant->getNomComplet();
                    continue;
                }

                $enseignant->setSurveillanceDu($du);
                $enseignant->setSurveillanceAu($au);
                $enseignant->setSurveillanceMoitie(!empty($ligne['moitie']));
            }
            $em->flush();

            if ($erreurs !== []) {
                $this->addFlash('error', 'Période ignorée (la date de début est après la date de fin) : '.implode(', ', $erreurs).'.');
            }
            $this->addFlash('success', 'Disponibilités enregistrées. Régénérez le tableau de surveillance pour les appliquer.');

            return $this->redirectToRoute('admin_surveillance_disponibilites', ['cycle' => $cycle->getId()]);
        }

        return $this->render('admin/surveillance/disponibilites.html.twig', [
            'cycle'        => $cycle,
            'surveillants' => $surveillants,
        ]);
    }

    private static function lireDate(mixed $valeur): ?\DateTimeImmutable
    {
        $date = is_string($valeur) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) : false;

        return $date !== false ? $date : null;
    }

    #[Route('/admin/surveillance/cycle/{cycle}/export-pdf', name: 'export_pdf')]
    public function exportPdf(
        Cycle $cycle,
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        ExamGridBuilder $gridBuilder,
        SurveillanceRepository $surveillanceRepo,
        ClasseRepository $classeRepo,
        ExamenClassesResolver $classesResolver,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $annee  = $anneeRepo->findActive();
        $lignes = $annee ? $gridBuilder->construireLignes($cycle, $annee) : [];

        [$cellules, $surveillancesParExamenClasse] = $this->construireDonneesAffichage($lignes, $surveillanceRepo, $classeRepo, $classesResolver);

        $html = $this->renderView('admin/surveillance/pdf/tableau.html.twig', [
            'cycle'                        => $cycle,
            'niveaux'                      => $gridBuilder->niveauxAffiches($cycle),
            'annee'                        => $annee,
            'lignes'                       => $lignes,
            'cellules'                     => $cellules,
            'surveillancesParExamenClasse' => $surveillancesParExamenClasse,
            'entete'                       => $request->query->getString('entete', ''),
            'avecEntete'                   => $request->query->getBoolean('entete_college', false),
        ]);

        return new Response($exporter->exporter($html), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tableau-surveillance-'.$cycle->getId().'.pdf"',
        ]);
    }

    /**
     * Contenu de chaque case (examen × niveau) du tableau : les classes à surveiller pour cet
     * examen (voir ExamenClassesResolver — matières à choix limitées aux classes qui les suivent,
     * une seule ligne par classe pour deux examens parallèles) et l'en-tête à afficher
     * ("ALL + ESP" quand une classe y passe les deux à la fois). Une case vide dont la classe est
     * déjà couverte par l'examen parallèle vaut `null` : elle n'est pas affichée du tout.
     *
     * @param \App\Exam\Service\Dto\GrilleLigne[] $lignes
     * @return array{0: array<int, array<int, array{entete: string, classes: array<int, array{classe: \App\Academic\Entity\Classe, libelle: string}>}|null>>, 1: array<int, array<int, \App\Exam\Entity\Surveillance[]>>}
     */
    private function construireDonneesAffichage(array $lignes, SurveillanceRepository $surveillanceRepo, ClasseRepository $classeRepo, ExamenClassesResolver $classesResolver): array
    {
        $examens = [];
        foreach ($lignes as $ligne) {
            foreach ($ligne->examensParNiveau as $examensNiveau) {
                foreach ($examensNiveau as $examen) {
                    /** @var Examen $examen */
                    $examens[$examen->getId()] = $examen;
                }
            }
        }

        $repartition = $classesResolver->repartir(array_values($examens), $classeRepo->findByAnneeScolaireActive());

        $cellules = [];
        $couverts = []; // examenId => niveauId => true : examen déjà surveillé via un examen parallèle
        foreach ($examens as $examenId => $examen) {
            foreach ($repartition[$examenId] ?? [] as $portee) {
                $niveauId = $portee['classe']->getNiveau()->getId();
                $codes    = array_map(static fn(Examen $e) => $e->getMatiere()->getCode(), $portee['examens']);

                $cellules[$examenId][$niveauId]['classes'][] = [
                    'classe'  => $portee['classe'],
                    'libelle' => count($codes) > 1 ? sprintf('%s (%s)', $portee['classe']->getNom(), implode(' + ', $codes)) : $portee['classe']->getNom(),
                ];
                foreach ($codes as $code) {
                    $cellules[$examenId][$niveauId]['codes'][$code] = true;
                }
                foreach ($portee['examens'] as $parallele) {
                    $couverts[$parallele->getId()][$niveauId] = true;
                }
            }
        }

        foreach ($examens as $examenId => $examen) {
            foreach ($examen->getNiveaux() as $niveau) {
                $niveauId = $niveau->getId();
                if (isset($cellules[$examenId][$niveauId])) {
                    $cellules[$examenId][$niveauId]['entete'] = implode(' + ', array_keys($cellules[$examenId][$niveauId]['codes']));
                    unset($cellules[$examenId][$niveauId]['codes']);
                } elseif (isset($couverts[$examenId][$niveauId])) {
                    $cellules[$examenId][$niveauId] = null;
                } else {
                    $cellules[$examenId][$niveauId] = ['entete' => $examen->getMatiere()->getCode(), 'classes' => []];
                }
            }
        }

        $surveillancesParExamenClasse = [];
        foreach ($surveillanceRepo->findByExamens(array_keys($examens)) as $surveillance) {
            $surveillancesParExamenClasse[$surveillance->getExamen()->getId()][$surveillance->getClasse()->getId()][] = $surveillance;
        }

        return [$cellules, $surveillancesParExamenClasse];
    }
}
