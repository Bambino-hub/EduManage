<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\Niveau;
use App\Academic\Repository\MatiereNiveauRepository;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Repository\ExamenBlancNoteRepository;
use App\ExamenBlanc\Service\ExamenBlancMatieresResolver;
use App\ExamenBlanc\Service\ExamenBlancNoteSaisieService;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use App\Student\Repository\InscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Saisie manuelle des notes d'un examen blanc, une fiche NIVEAU × MATIÈRE à la fois — toutes
 * les classes du niveau fusionnées en un seul groupe trié alphabétiquement (pas de notion de
 * classe pour un examen blanc, voir ExamenBlanc\Service\ExamenBlancMoyenneCalculator). Route
 * sous /admin, déjà réservée à ROLE_ADMIN par le firewall (security.yaml) — pas de Voter
 * dédié : une fiche niveau-wide n'a plus de "propriétaire" unique (plusieurs enseignants,
 * parfois des correcteurs externes saisis à la main sur la fiche papier).
 */
#[Route('/admin/examens-blancs/{examenBlancId}/niveau/{niveauId}/matiere/{matiereId}', name: 'admin_examen_blanc_note_')]
class ExamenBlancNoteController extends AbstractController
{
    #[Route('/fiche', name: 'fiche')]
    public function fiche(
        int $examenBlancId,
        int $niveauId,
        int $matiereId,
        Request $request,
        EntityManagerInterface $em,
        InscriptionRepository $inscriptionRepo,
        MatiereNiveauRepository $matiereNiveauRepo,
        ExamenBlancNoteRepository $noteRepo,
        ExamenBlancNoteSaisieService $saisieService,
        ExamenBlancMatieresResolver $matieresResolver,
    ): Response {
        [$examenBlanc, $niveau, $matiere] = $this->resoudre($examenBlancId, $niveauId, $matiereId, $em, $matieresResolver);
        $inscriptions = $inscriptionRepo->findActivesByNiveauEtAnnee($niveau, $examenBlanc->getAnneeScolaire());

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('fiche_blanc'.$examenBlancId.'_'.$niveauId.'_'.$matiereId, $request->getPayload()->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
                return $this->redirectToRoute('admin_examen_blanc_note_fiche', ['examenBlancId' => $examenBlancId, 'niveauId' => $niveauId, 'matiereId' => $matiereId]);
            }

            $saisieService->enregistrer($examenBlanc, $niveau, $matiere, $inscriptions, $request->getPayload()->all('notes'));
            $em->flush();
            $this->addFlash('success', 'Notes enregistrées.');
            return $this->redirectToRoute('admin_examen_blanc_note_fiche', ['examenBlancId' => $examenBlancId, 'niveauId' => $niveauId, 'matiereId' => $matiereId]);
        }

        $coefficientMatiere = $matiereNiveauRepo->findOneByMatiereEtNiveau($matiere, $niveau)?->getCoefficient() ?? '1.00';

        return $this->render('admin/examen_blanc/fiche.html.twig', [
            'examenBlanc'          => $examenBlanc,
            'niveau'               => $niveau,
            'matiere'              => $matiere,
            'inscriptions'         => $inscriptions,
            'notesParInscription'  => $noteRepo->findByNiveauEtMatiereIndexeesParInscription($examenBlanc, $niveau, $matiere),
            'coefficientMatiere'   => $coefficientMatiere,
        ]);
    }

    #[Route('/fiche/pdf', name: 'fiche_pdf')]
    public function fichePdf(
        int $examenBlancId,
        int $niveauId,
        int $matiereId,
        Request $request,
        EntityManagerInterface $em,
        InscriptionRepository $inscriptionRepo,
        MatiereNiveauRepository $matiereNiveauRepo,
        EmploiDuTempsPdfExporter $exporter,
        ExamenBlancMatieresResolver $matieresResolver,
    ): Response {
        [$examenBlanc, $niveau, $matiere] = $this->resoudre($examenBlancId, $niveauId, $matiereId, $em, $matieresResolver);

        $coefficientMatiere = $matiereNiveauRepo->findOneByMatiereEtNiveau($matiere, $niveau)?->getCoefficient() ?? '1.00';

        $html = $this->renderView('admin/examen_blanc/pdf/fiche.html.twig', [
            'examenBlanc'        => $examenBlanc,
            'niveau'             => $niveau,
            'matiere'            => $matiere,
            'inscriptions'       => $inscriptionRepo->findActivesByNiveauEtAnnee($niveau, $examenBlanc->getAnneeScolaire()),
            'coefficientMatiere' => $coefficientMatiere,
            'avecEntete'         => $request->query->getBoolean('entete_college', false),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'fiche-'.$examenBlanc->getLibelle().'-'.$niveau->getNomComplet().'-'.$matiere->getNom(),
        )->lower().'.pdf';

        return new Response($exporter->exporter($html, 'landscape'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nomFichier.'"',
        ]);
    }

    /**
     * @return array{0: ExamenBlanc, 1: Niveau, 2: Matiere}
     *
     * Vérifie que la matière est réellement évaluée pour ce niveau dans cet examen blanc
     * (voir ExamenBlancMatieresResolver) — sans ce garde-fou, l'URL de saisie restait
     * accessible pour n'importe quel couple niveau/matière de l'ID, même une matière non
     * enseignée à ce niveau (ex. Philosophie pour la 3ème).
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
