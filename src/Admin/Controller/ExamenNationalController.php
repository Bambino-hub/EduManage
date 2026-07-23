<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\ExamenNational\Entity\SessionExamenNational;
use App\ExamenNational\Enum\StatutSessionExamenNational;
use App\ExamenNational\Repository\NoteMatiereCandidatRepository;
use App\ExamenNational\Repository\SessionExamenNationalRepository;
use App\ExamenNational\Service\StatistiqueReleveCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques d'examens nationaux (BEPC/BAC1/BAC2) : min/max/répartition par matière sur
 * une session validée. Voir ExamenNationalImportController pour l'import du relevé PDF qui
 * alimente ces sessions.
 */
#[Route('/admin/examens-nationaux', name: 'admin_releve_national_')]
class ExamenNationalController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(SessionExamenNationalRepository $repo): Response
    {
        return $this->render('admin/examen_national/index.html.twig', [
            'sessions' => $repo->findValideesTriees(),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(
        SessionExamenNational $session,
        NoteMatiereCandidatRepository $noteRepo,
        StatistiqueReleveCalculator $calculator,
    ): Response {
        $this->refuserSiBrouillon($session);

        $notes = $noteRepo->findBySession($session);

        return $this->render('admin/examen_national/show.html.twig', [
            'session'      => $session,
            'statistiques' => $calculator->calculer($notes),
        ]);
    }

    #[Route('/{id}/export/notes.csv', name: 'export_notes')]
    public function exportNotes(SessionExamenNational $session, NoteMatiereCandidatRepository $noteRepo): StreamedResponse
    {
        $this->refuserSiBrouillon($session);
        $notes = $noteRepo->findBySession($session);

        $response = new StreamedResponse(function () use ($notes): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8, pour un affichage correct des accents dans Excel
            fputcsv($handle, ['Nom', 'Prénoms', 'N° Table', 'Matière', 'Type épreuve', 'Note', 'Coefficient'], ';');

            foreach ($notes as $note) {
                $candidat = $note->getCandidat();
                fputcsv($handle, [
                    $candidat->getNom(),
                    $candidat->getPrenoms(),
                    $candidat->getNumeroTable(),
                    $note->getMatiereLibelle(),
                    $note->getTypeEpreuve()->label(),
                    $note->getNote(),
                    $note->getCoefficient(),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="notes_'.$this->nomFichier($session).'.csv"');

        return $response;
    }

    #[Route('/{id}/export/statistiques.csv', name: 'export_statistiques')]
    public function exportStatistiques(
        SessionExamenNational $session,
        NoteMatiereCandidatRepository $noteRepo,
        StatistiqueReleveCalculator $calculator,
    ): StreamedResponse {
        $this->refuserSiBrouillon($session);
        $statistiques = $calculator->calculer($noteRepo->findBySession($session));

        $response = new StreamedResponse(function () use ($statistiques): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Matière', 'N', 'Min', 'Max', '[0;6[', '[6;10[', '[10;15[', '[15;20]'], ';');

            foreach ($statistiques as $statistique) {
                fputcsv($handle, [
                    $statistique->libelle,
                    $statistique->n,
                    $statistique->min,
                    $statistique->max,
                    $statistique->bande0a6,
                    $statistique->bande6a10,
                    $statistique->bande10a15,
                    $statistique->bande15a20,
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="statistiques_'.$this->nomFichier($session).'.csv"');

        return $response;
    }

    private function refuserSiBrouillon(SessionExamenNational $session): void
    {
        if ($session->getStatut() !== StatutSessionExamenNational::VALIDE) {
            throw $this->createNotFoundException();
        }
    }

    private function nomFichier(SessionExamenNational $session): string
    {
        $brut = $session->getType()->value.'_'.$session->getSerie().'_'.($session->getAnneeSession() ?? '');
        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $brut) ?? 'export';
    }
}
