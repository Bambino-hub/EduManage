<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Service\ExamenBlancMatieresResolver;
use App\ExamenBlanc\Service\ExamenBlancNoteExtractionService;
use App\ExamenBlanc\Service\ExamenBlancNoteMatcher;
use App\ExamenBlanc\Service\ExamenBlancNoteSaisieService;
use App\Grading\Form\NoteImportUploadType;
use App\Student\Entity\Inscription;
use App\Student\Repository\InscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import automatique des notes d'un examen blanc depuis une fiche papier scannée (PDF/photo),
 * via extraction vision (Gemini) — même flux en 2 temps que Grading\NoteImportController
 * (upload → aperçu éditable avec rapprochement élève auto sur TOUT le niveau → enregistrement
 * réel via ExamenBlancNoteSaisieService), réutilise le même formulaire d'upload générique
 * (Grading\Form\NoteImportUploadType, non lié à une entité).
 */
#[Route('/admin/examens-blancs/{examenBlancId}/niveau/{niveauId}/matiere/{matiereId}/import', name: 'admin_examen_blanc_note_import_')]
class ExamenBlancNoteImportController extends AbstractController
{
    #[Route('', name: 'new', methods: ['GET'])]
    public function new(int $examenBlancId, int $niveauId, int $matiereId, EntityManagerInterface $em, ExamenBlancMatieresResolver $matieresResolver): Response
    {
        [$examenBlanc, $niveau, $matiere] = $this->resoudre($examenBlancId, $niveauId, $matiereId, $em, $matieresResolver);

        return $this->render('admin/examen_blanc/import_new.html.twig', [
            'examenBlanc' => $examenBlanc,
            'niveau'      => $niveau,
            'matiere'     => $matiere,
            'form'        => $this->createForm(NoteImportUploadType::class),
        ]);
    }

    #[Route('/apercu', name: 'preview', methods: ['POST'])]
    public function preview(
        int $examenBlancId,
        int $niveauId,
        int $matiereId,
        Request $request,
        EntityManagerInterface $em,
        ExamenBlancNoteExtractionService $extractionService,
        ExamenBlancNoteMatcher $matcher,
        InscriptionRepository $inscriptionRepo,
        ExamenBlancMatieresResolver $matieresResolver,
    ): Response {
        [$examenBlanc, $niveau, $matiere] = $this->resoudre($examenBlancId, $niveauId, $matiereId, $em, $matieresResolver);

        $form = $this->createForm(NoteImportUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/examen_blanc/import_new.html.twig', [
                'examenBlanc' => $examenBlanc,
                'niveau'      => $niveau,
                'matiere'     => $matiere,
                'form'        => $form,
            ]);
        }

        $inscriptions = $inscriptionRepo->findActivesByNiveauEtAnnee($niveau, $examenBlanc->getAnneeScolaire());

        /** @var UploadedFile $fichier */
        $fichier = $form->get('fichier')->getData();

        try {
            $fiche = $extractionService->extraire($fichier);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec de la lecture de la fiche : '.$e->getMessage());
            return $this->redirectToRoute('admin_examen_blanc_note_import_new', ['examenBlancId' => $examenBlancId, 'niveauId' => $niveauId, 'matiereId' => $matiereId]);
        }

        if ($fiche->lignes === []) {
            $this->addFlash('error', 'Aucune ligne élève exploitable trouvée sur cette fiche.');
            return $this->redirectToRoute('admin_examen_blanc_note_import_new', ['examenBlancId' => $examenBlancId, 'niveauId' => $niveauId, 'matiereId' => $matiereId]);
        }

        return $this->render('admin/examen_blanc/import_preview.html.twig', [
            'examenBlanc'  => $examenBlanc,
            'niveau'       => $niveau,
            'matiere'      => $matiere,
            'fiche'        => $fiche,
            'lignes'       => $matcher->associer($fiche, $inscriptions),
            'inscriptions' => $inscriptions,
        ]);
    }

    #[Route('/confirmer', name: 'confirm', methods: ['POST'])]
    public function confirm(
        int $examenBlancId,
        int $niveauId,
        int $matiereId,
        Request $request,
        EntityManagerInterface $em,
        ExamenBlancNoteSaisieService $saisieService,
        InscriptionRepository $inscriptionRepo,
        ExamenBlancMatieresResolver $matieresResolver,
    ): Response {
        [$examenBlanc, $niveau, $matiere] = $this->resoudre($examenBlancId, $niveauId, $matiereId, $em, $matieresResolver);

        if (!$this->isCsrfTokenValid('note_import_blanc'.$examenBlancId.'_'.$niveauId.'_'.$matiereId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_examen_blanc_note_fiche', ['examenBlancId' => $examenBlancId, 'niveauId' => $niveauId, 'matiereId' => $matiereId]);
        }

        $donneesParEleveId = [];
        $ignorees          = 0;
        foreach ($request->getPayload()->all('lignes') as $ligne) {
            $eleveId = (int) ($ligne['eleve_id'] ?? 0);
            if ($eleveId === 0) {
                $ignorees++;
                continue; // ligne non rapprochée d'un élève, laissée de côté par l'admin
            }

            $valeur = trim((string) ($ligne['note'] ?? ''));
            if ($valeur !== '') {
                $donneesParEleveId[$eleveId] = ['valeur' => $valeur];
            }
        }

        $importes = 0;
        if ($donneesParEleveId !== []) {
            $inscriptions = array_values(array_filter(
                $inscriptionRepo->findActivesByNiveauEtAnnee($niveau, $examenBlanc->getAnneeScolaire()),
                static fn (Inscription $i): bool => isset($donneesParEleveId[$i->getEleve()->getId()]),
            ));
            $saisieService->enregistrer($examenBlanc, $niveau, $matiere, $inscriptions, $donneesParEleveId);
            $importes += count($donneesParEleveId);
        }

        $em->flush();

        $message = sprintf('%d note(s) importée(s).', $importes);
        if ($ignorees > 0) {
            $message .= sprintf(' %d ligne(s) ignorée(s) (élève non rapproché).', $ignorees);
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_examen_blanc_note_fiche', ['examenBlancId' => $examenBlancId, 'niveauId' => $niveauId, 'matiereId' => $matiereId]);
    }

    /**
     * @return array{0: ExamenBlanc, 1: Niveau, 2: Matiere}
     *
     * Vérifie que la matière est réellement évaluée pour ce niveau dans cet examen blanc
     * (voir ExamenBlancMatieresResolver) — même garde-fou que ExamenBlancNoteController,
     * sinon l'import OCR restait accessible pour une matière non enseignée à ce niveau.
     */
    private function resoudre(int $examenBlancId, int $niveauId, int $matiereId, EntityManagerInterface $em, ExamenBlancMatieresResolver $matieresResolver): array
    {
        $examenBlanc = $em->getRepository(ExamenBlanc::class)->find($examenBlancId) ?? throw $this->createNotFoundException();
        $niveau      = $em->getRepository(Niveau::class)->find($niveauId) ?? throw $this->createNotFoundException();
        $matiere     = $em->getRepository(Matiere::class)->find($matiereId) ?? throw $this->createNotFoundException();

        if (!isset($matieresResolver->resoudre($examenBlanc, $niveau)[$matiere->getId()])) {
            throw $this->createNotFoundException('Cette matière n\'est pas évaluée pour ce niveau dans cet examen blanc.');
        }

        return [$examenBlanc, $niveau, $matiere];
    }
}
