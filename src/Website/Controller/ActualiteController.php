<?php

declare(strict_types=1);

namespace App\Website\Controller;

use App\Academic\Enum\TypeCycle;
use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\CycleRepository;
use App\Exam\Repository\ExamenRepository;
use App\Website\Repository\ActualiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique "Actualités" : annonces publiées + calendrier des examens à venir (collège et
 * lycée), ce dernier calculé automatiquement depuis les examens déjà saisis côté admin.
 */
final class ActualiteController extends AbstractController
{
    #[Route('/actualites', name: 'website_actualites', methods: ['GET'])]
    public function index(
        ActualiteRepository $actualiteRepo,
        ExamenRepository $examenRepo,
        CycleRepository $cycleRepo,
        AnneeScolaireRepository $anneeRepo,
    ): Response {
        $annee = $anneeRepo->findActive();
        $today = new \DateTimeImmutable('today');

        $examensCollege = [];
        $examensLycee   = [];

        if ($annee) {
            $cycleCollege = $cycleRepo->findOneBy(['type' => TypeCycle::COLLEGE]);
            $cycleLycee   = $cycleRepo->findOneBy(['type' => TypeCycle::LYCEE]);

            $examensCollege = $cycleCollege ? $examenRepo->findAVenirParCycle($cycleCollege, $annee, $today) : [];
            $examensLycee   = $cycleLycee ? $examenRepo->findAVenirParCycle($cycleLycee, $annee, $today) : [];
        }

        return $this->render('website/actualites.html.twig', [
            'actualites'     => $actualiteRepo->findPublieesRecentes(),
            'examensCollege' => $examensCollege,
            'examensLycee'   => $examensLycee,
        ]);
    }
}
