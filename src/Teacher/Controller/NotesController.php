<?php

declare(strict_types=1);

namespace App\Teacher\Controller;

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
use App\Security\Entity\Utilisateur;
use App\Student\Repository\InscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Saisie des notes par l'enseignant, restreinte à ses propres attributions
 * (voir Grading\Security\AttributionVoter — premier Voter de l'application).
 *
 * La structure de la fiche (nombre d'interrogations/devoirs, colonne Composition
 * unique) est fixée par l'admin sur le Trimestre — appliquée automatiquement à toutes
 * les matières (voir Grading\Service\FicheNotesService). L'enseignant ne peut que
 * saisir des valeurs dans les colonnes déjà en place, jamais en changer le nombre.
 */
#[Route('/enseignant/notes', name: 'teacher_notes_')]
class NotesController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(AnneeScolaireRepository $anneeRepo, AttributionRepository $attributionRepo, TrimestreRepository $trimestreRepo): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $enseignant  = $utilisateur->getEnseignant();

        if ($enseignant === null) {
            throw $this->createAccessDeniedException('Ce compte n\'est lié à aucune fiche enseignant.');
        }

        $annee     = $anneeRepo->findActive();
        $trimestre = $trimestreRepo->findActive();

        $attributions = $annee ? $attributionRepo->findByEnseignantEtAnnee((int) $enseignant->getId(), (int) $annee->getId()) : [];

        return $this->render('teacher/notes/index.html.twig', [
            'attributions' => $attributions,
            'trimestre'    => $trimestre,
        ]);
    }

    #[Route('/attribution/{id}', name: 'attribution')]
    public function attribution(Attribution $attribution, TrimestreRepository $trimestreRepo): Response
    {
        $this->denyAccessUnlessGranted(AttributionVoter::GERER_NOTES, $attribution);

        $trimestre = $trimestreRepo->findActive();
        if ($trimestre === null) {
            $this->addFlash('error', 'Aucun trimestre actif — demandez à l\'administration d\'en activer un.');
            return $this->redirectToRoute('teacher_notes_index');
        }

        return $this->render('teacher/notes/attribution.html.twig', [
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
            $this->addFlash('error', 'Aucun trimestre actif — demandez à l\'administration d\'en activer un.');
            return $this->redirectToRoute('teacher_notes_index');
        }

        $ficheNotesService->assurerColonnes($attribution, $trimestre);
        $em->flush();

        $evaluations  = $evaluationRepo->findByAttributionEtTrimestre($attribution, $trimestre);
        $inscriptions = $inscriptionRepo->findActivesByClasse($attribution->getClasse());

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('fiche'.$attribution->getId(), $request->getPayload()->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
                return $this->redirectToRoute('teacher_notes_fiche', ['id' => $attribution->getId()]);
            }

            $notesPost = $request->getPayload()->all('notes');
            foreach ($evaluations as $evaluation) {
                $noteSaisieService->enregistrer($evaluation, $inscriptions, $notesPost[$evaluation->getId()] ?? []);
            }
            $moyenneManuelleSaisieService->enregistrer($attribution, $trimestre, $inscriptions, $request->getPayload()->all('manuel'));
            $em->flush();
            $this->addFlash('success', 'Notes enregistrées.');
            return $this->redirectToRoute('teacher_notes_fiche', ['id' => $attribution->getId()]);
        }

        $notesParEvaluationId = [];
        foreach ($evaluations as $evaluation) {
            $notesParEvaluationId[$evaluation->getId()] = $noteRepo->findByEvaluationIndexeesParEleve($evaluation);
        }

        $interrogations = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::INTERROGATION));
        $devoirs        = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::DEVOIR));
        $composition     = array_values(array_filter($evaluations, static fn (Evaluation $e): bool => $e->getType() === TypeEvaluation::COMPOSITION))[0] ?? null;

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

        return $this->render('teacher/notes/fiche.html.twig', [
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
            $this->addFlash('error', 'Aucun trimestre actif — demandez à l\'administration d\'en activer un.');
            return $this->redirectToRoute('teacher_notes_index');
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
