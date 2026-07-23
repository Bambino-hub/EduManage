<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Grading\Entity\Evaluation;
use App\Grading\Entity\Trimestre;
use App\Grading\Enum\TypeEvaluation;
use App\Grading\Form\NoteImportUploadType;
use App\Grading\Repository\EvaluationRepository;
use App\Grading\Repository\TrimestreRepository;
use App\Grading\Security\AttributionVoter;
use App\Grading\Service\MoyenneManuelleSaisieService;
use App\Grading\Service\NoteExtractionMatcher;
use App\Grading\Service\NoteExtractionService;
use App\Grading\Service\NoteSaisieService;
use App\Scheduling\Entity\Attribution;
use App\Student\Entity\Inscription;
use App\Student\Repository\InscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import automatique des notes depuis une fiche papier scannée (PDF/photo), via extraction
 * vision (Gemini). Même flux en 2 temps que l'import élèves : upload → aperçu éditable avec
 * rapprochement élève auto (à corriger si besoin) → enregistrement réel via le même
 * NoteSaisieService que la saisie manuelle. Voir [[saisie-automatique-notes]].
 */
#[Route('/admin/notes/attribution/{id}/import', name: 'admin_note_import_')]
class NoteImportController extends AbstractController
{
    private const TITRE_COMPOSITION = 'Composition';

    #[Route('', name: 'new', methods: ['GET'])]
    public function new(Attribution $attribution, TrimestreRepository $trimestreRepo): Response
    {
        $this->denyAccessUnlessGranted(AttributionVoter::GERER_NOTES, $attribution);

        if ($trimestreRepo->findActive() === null) {
            $this->addFlash('error', 'Aucun trimestre actif — activez-en un avant d\'importer une fiche.');
            return $this->redirectToRoute('admin_notes_attribution', ['id' => $attribution->getId()]);
        }

        return $this->render('admin/notes/import_new.html.twig', [
            'attribution' => $attribution,
            'form'        => $this->createForm(NoteImportUploadType::class),
        ]);
    }

    #[Route('/apercu', name: 'preview', methods: ['POST'])]
    public function preview(
        Attribution $attribution,
        Request $request,
        NoteExtractionService $extractionService,
        NoteExtractionMatcher $matcher,
        InscriptionRepository $inscriptionRepo,
    ): Response {
        $this->denyAccessUnlessGranted(AttributionVoter::GERER_NOTES, $attribution);

        $form = $this->createForm(NoteImportUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/notes/import_new.html.twig', ['attribution' => $attribution, 'form' => $form]);
        }

        $inscriptions = $inscriptionRepo->findActivesByClasse($attribution->getClasse());

        /** @var UploadedFile $fichier */
        $fichier = $form->get('fichier')->getData();

        try {
            $fiche = $extractionService->extraire($fichier);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec de la lecture de la fiche : '.$e->getMessage());
            return $this->redirectToRoute('admin_note_import_new', ['id' => $attribution->getId()]);
        }

        if ($fiche->lignes === []) {
            $this->addFlash('error', 'Aucune ligne élève exploitable trouvée sur cette fiche.');
            return $this->redirectToRoute('admin_note_import_new', ['id' => $attribution->getId()]);
        }

        return $this->render('admin/notes/import_preview.html.twig', [
            'attribution'  => $attribution,
            'fiche'        => $fiche,
            'lignes'       => $matcher->associer($fiche, $inscriptions),
            'inscriptions' => $inscriptions,
        ]);
    }

    #[Route('/confirmer', name: 'confirm', methods: ['POST'])]
    public function confirm(
        Attribution $attribution,
        Request $request,
        EntityManagerInterface $em,
        TrimestreRepository $trimestreRepo,
        EvaluationRepository $evaluationRepo,
        NoteSaisieService $noteSaisieService,
        MoyenneManuelleSaisieService $moyenneManuelleSaisieService,
        InscriptionRepository $inscriptionRepo,
    ): Response {
        $this->denyAccessUnlessGranted(AttributionVoter::GERER_NOTES, $attribution);

        if (!$this->isCsrfTokenValid('note_import'.$attribution->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_notes_attribution', ['id' => $attribution->getId()]);
        }

        $trimestre = $trimestreRepo->findActive();
        if ($trimestre === null) {
            $this->addFlash('error', 'Aucun trimestre actif — activez-en un avant d\'importer une fiche.');
            return $this->redirectToRoute('admin_notes_attribution', ['id' => $attribution->getId()]);
        }

        // Interro/Devoir : ce sont des MOYENNES lues sur la fiche papier, pas la note d'une
        // interrogation ou d'un devoir précis — elles vont dans la surcharge manuelle
        // (MoyenneManuelle, colonnes "Moy Interro"/"Moy Devoir"), jamais dans une Evaluation
        // Interrogation/Devoir existante (voir MoyenneManuelle::class pour le pourquoi).
        // Compos reste une note d'évaluation classique : il n'y a toujours qu'une seule
        // composition par trimestre (voir FicheNotesService::assurerColonnes).
        $donneesMoyennesParEleveId = [];
        $donneesComposParEleveId  = [];

        $ignorees = 0;
        foreach ($request->getPayload()->all('lignes') as $ligne) {
            $eleveId = (int) ($ligne['eleve_id'] ?? 0);
            if ($eleveId === 0) {
                $ignorees++;
                continue; // ligne non rapprochée d'un élève, laissée de côté par l'admin
            }

            $interro = trim((string) ($ligne['interro'] ?? ''));
            $devoir  = trim((string) ($ligne['devoir'] ?? ''));
            $compos  = trim((string) ($ligne['compos'] ?? ''));

            if ($interro !== '' || $devoir !== '') {
                $donneesMoyennesParEleveId[$eleveId] = ['interro' => $interro, 'devoir' => $devoir];
            }
            if ($compos !== '') {
                $donneesComposParEleveId[$eleveId] = ['valeur' => $compos];
            }
        }

        $inscriptions = $inscriptionRepo->findActivesByClasse($attribution->getClasse());

        $importes = 0;

        if ($donneesMoyennesParEleveId !== []) {
            // Restreint aux élèves effectivement extraits : ne touche pas aux surcharges
            // déjà en place pour un élève absent de cette fiche (pas de vidage collatéral).
            $inscriptionsConcernees = array_values(array_filter(
                $inscriptions,
                static fn (Inscription $i): bool => isset($donneesMoyennesParEleveId[$i->getEleve()->getId()]),
            ));
            $moyenneManuelleSaisieService->enregistrer($attribution, $trimestre, $inscriptionsConcernees, $donneesMoyennesParEleveId);
            $importes += count($donneesMoyennesParEleveId);
        }

        if ($donneesComposParEleveId !== []) {
            $evaluation = $this->trouverOuCreerEvaluationComposition($attribution, $trimestre, $evaluationRepo, $em);
            $noteSaisieService->enregistrer($evaluation, $inscriptions, $donneesComposParEleveId);
            $importes += count($donneesComposParEleveId);
        }

        $em->flush();

        $message = sprintf('%d note(s) importée(s).', $importes);
        if ($ignorees > 0) {
            $message .= sprintf(' %d ligne(s) ignorée(s) (élève non rapproché).', $ignorees);
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_notes_attribution', ['id' => $attribution->getId()]);
    }

    private function trouverOuCreerEvaluationComposition(
        Attribution $attribution,
        Trimestre $trimestre,
        EvaluationRepository $evaluationRepo,
        EntityManagerInterface $em,
    ): Evaluation {
        foreach ($evaluationRepo->findByAttributionEtTrimestre($attribution, $trimestre) as $evaluation) {
            if ($evaluation->getType() === TypeEvaluation::COMPOSITION) {
                return $evaluation;
            }
        }

        $evaluation = new Evaluation();
        $evaluation->setAttribution($attribution);
        $evaluation->setTrimestre($trimestre);
        $evaluation->setType(TypeEvaluation::COMPOSITION);
        $evaluation->setTitre(self::TITRE_COMPOSITION);
        $evaluation->setDate(new \DateTimeImmutable());
        $em->persist($evaluation);
        $em->flush(); // ID nécessaire immédiatement : NoteSaisieService interroge les notes existantes par évaluation

        return $evaluation;
    }
}
