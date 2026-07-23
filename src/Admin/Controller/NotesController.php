<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Grading\Entity\Evaluation;
use App\Grading\Enum\TypeEvaluation;
use App\Grading\Repository\EvaluationRepository;
use App\Grading\Repository\MoyenneManuelleRepository;
use App\Grading\Repository\NoteRepository;
use App\Grading\Repository\TrimestreRepository;
use App\Grading\Security\AttributionVoter;
use App\Grading\Service\FicheNotesService;
use App\Grading\Service\MoyenneCalculator;
use App\Grading\Service\MoyenneManuelleSaisieService;
use App\Grading\Service\NoteSaisieService;
use App\Scheduling\Entity\Attribution;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use App\Student\Repository\InscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Vue admin de la saisie des notes : toutes les attributions de l'année active (pas
 * seulement "les miennes"), pour corriger/saisir à la place d'un enseignant absent.
 * Même service de saisie (Grading\Service\NoteSaisieService) que la vue enseignant —
 * seule la source des attributions et les templates diffèrent.
 *
 * La structure de la fiche (nombre d'interrogations/devoirs) est fixée une fois pour
 * toutes par l'admin sur le Trimestre (Trimestre::nbInterrogations/nbDevoirs, voir
 * TrimestreType) — appliquée automatiquement à toutes les matières, pas de gestion
 * colonne par colonne ici (voir Grading\Service\FicheNotesService).
 */
#[Route('/admin/notes', name: 'admin_notes_')]
class NotesController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(AnneeScolaireRepository $anneeRepo, AttributionRepository $attributionRepo, TrimestreRepository $trimestreRepo): Response
    {
        $annee     = $anneeRepo->findActive();
        $trimestre = $trimestreRepo->findActive();

        return $this->render('admin/notes/index.html.twig', [
            'attributions' => $annee ? $attributionRepo->findByAnneeScolaire((int) $annee->getId()) : [],
            'trimestre'    => $trimestre,
        ]);
    }

    #[Route('/attribution/{id}', name: 'attribution')]
    public function attribution(Attribution $attribution, TrimestreRepository $trimestreRepo): Response
    {
        $this->denyAccessUnlessGranted(AttributionVoter::GERER_NOTES, $attribution);

        $trimestre = $trimestreRepo->findActive();
        if ($trimestre === null) {
            $this->addFlash('error', 'Aucun trimestre actif — activez-en un avant de saisir des notes.');
            return $this->redirectToRoute('admin_notes_index');
        }

        return $this->render('admin/notes/attribution.html.twig', [
            'attribution' => $attribution,
            'trimestre'   => $trimestre,
        ]);
    }

    /** Grille façon fiche papier : une colonne par évaluation (interro/devoir), Compos/Moy/Rang calculés en direct. */
    #[Route('/attribution/{id}/fiche', name: 'fiche')]
    public function fiche(
        Attribution $attribution,
        Request $request,
        TrimestreRepository $trimestreRepo,
        EvaluationRepository $evaluationRepo,
        InscriptionRepository $inscriptionRepo,
        NoteRepository $noteRepo,
        MatiereNiveauRepository $matiereNiveauRepo,
        MoyenneCalculator $moyenneCalculator,
        MoyenneManuelleRepository $moyenneManuelleRepo,
        NoteSaisieService $noteSaisieService,
        MoyenneManuelleSaisieService $moyenneManuelleSaisieService,
        FicheNotesService $ficheNotesService,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(AttributionVoter::GERER_NOTES, $attribution);

        $trimestre = $trimestreRepo->findActive();
        if ($trimestre === null) {
            $this->addFlash('error', 'Aucun trimestre actif — activez-en un avant de saisir des notes.');
            return $this->redirectToRoute('admin_notes_index');
        }

        $ficheNotesService->assurerColonnes($attribution, $trimestre);
        $em->flush();

        $evaluations  = $evaluationRepo->findByAttributionEtTrimestre($attribution, $trimestre);
        $inscriptions = $inscriptionRepo->findActivesByClasse($attribution->getClasse());

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('fiche'.$attribution->getId(), $request->getPayload()->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
                return $this->redirectToRoute('admin_notes_fiche', ['id' => $attribution->getId()]);
            }

            $notesPost = $request->getPayload()->all('notes');
            foreach ($evaluations as $evaluation) {
                $noteSaisieService->enregistrer($evaluation, $inscriptions, $notesPost[$evaluation->getId()] ?? []);
            }
            $moyenneManuelleSaisieService->enregistrer($attribution, $trimestre, $inscriptions, $request->getPayload()->all('manuel'));
            $em->flush();
            $this->addFlash('success', 'Notes enregistrées.');
            return $this->redirectToRoute('admin_notes_fiche', ['id' => $attribution->getId()]);
        }

        $notesParEvaluationId = [];
        foreach ($evaluations as $evaluation) {
            $notesParEvaluationId[$evaluation->getId()] = $noteRepo->findByEvaluationIndexeesParEleve($evaluation);
        }

        $interrogations = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::INTERROGATION));
        $devoirs        = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::DEVOIR));
        $composition    = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::COMPOSITION))[0] ?? null;

        $calculAutoParEleve = [];
        foreach ($inscriptions as $inscription) {
            $eleveId = $inscription->getEleve()->getId();
            $calculAutoParEleve[$eleveId] = [
                'interro' => $moyenneCalculator->sousMoyenne($interrogations, $notesParEvaluationId, $eleveId),
                'devoir'  => $moyenneCalculator->sousMoyenne($devoirs, $notesParEvaluationId, $eleveId),
            ];
        }

        $coefficientMatiere = $matiereNiveauRepo
            ->findOneByMatiereEtNiveau($attribution->getMatiere(), $attribution->getClasse()->getNiveau())
            ?->getCoefficient() ?? '1.00';

        return $this->render('admin/notes/fiche.html.twig', [
            'attribution'          => $attribution,
            'trimestre'            => $trimestre,
            'inscriptions'         => $inscriptions,
            'interrogations'       => $interrogations,
            'devoirs'              => $devoirs,
            'composition'          => $composition,
            'notesParEvaluationId' => $notesParEvaluationId,
            'surchargesParEleve'   => $moyenneManuelleRepo->findByAttributionEtTrimestreIndexeesParEleve($attribution, $trimestre),
            'calculAutoParEleve'   => $calculAutoParEleve,
            'coefficientMatiere'   => $coefficientMatiere,
            'resultats'            => $moyenneCalculator->calculerPourAttribution($attribution, $trimestre),
        ]);
    }

    #[Route('/attribution/{id}/fiche/pdf', name: 'fiche_pdf')]
    public function fichePdf(
        Attribution $attribution,
        Request $request,
        TrimestreRepository $trimestreRepo,
        EvaluationRepository $evaluationRepo,
        InscriptionRepository $inscriptionRepo,
        MatiereNiveauRepository $matiereNiveauRepo,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $this->denyAccessUnlessGranted(AttributionVoter::GERER_NOTES, $attribution);

        $trimestre = $trimestreRepo->findActive();
        if ($trimestre === null) {
            $this->addFlash('error', 'Aucun trimestre actif — activez-en un avant de saisir des notes.');
            return $this->redirectToRoute('admin_notes_index');
        }

        $evaluations = $evaluationRepo->findByAttributionEtTrimestre($attribution, $trimestre);

        $coefficientMatiere = $matiereNiveauRepo
            ->findOneByMatiereEtNiveau($attribution->getMatiere(), $attribution->getClasse()->getNiveau())
            ?->getCoefficient() ?? '1.00';

        $html = $this->renderView('grading/pdf/fiche_notes.html.twig', [
            'attribution'        => $attribution,
            'trimestre'          => $trimestre,
            'inscriptions'       => $inscriptionRepo->findActivesByClasse($attribution->getClasse()),
            'coefficientMatiere' => $coefficientMatiere,
            'nbInterrogations'   => max($trimestre->getNbInterrogations(), count(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::INTERROGATION))),
            'nbDevoirs'          => max($trimestre->getNbDevoirs(), count(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::DEVOIR))),
            'avecEntete'         => $request->query->getBoolean('entete_college', false),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'fiche-notes-'.$attribution->getClasse()->getNom().'-'.$attribution->getMatiere()->getNom().'-'.$trimestre->getLibelle(),
        )->lower().'.pdf';

        return new Response($exporter->exporter($html, 'landscape'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nomFichier.'"',
        ]);
    }
}
