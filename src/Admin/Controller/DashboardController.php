<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\MatiereRepository;
use App\Academic\Repository\SalleRepository;
use App\Staff\Repository\EnseignantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'dashboard')]
    public function index(
        AnneeScolaireRepository $anneeRepo,
        EnseignantRepository    $enseignantRepo,
        ClasseRepository        $classeRepo,
        MatiereRepository       $matiereRepo,
        SalleRepository         $salleRepo,
    ): Response {
        $anneeActive = $anneeRepo->findActive();

        return $this->render('admin/dashboard.html.twig', [
            'anneeActive'    => $anneeActive,
            'nbEnseignants'  => $enseignantRepo->count(['actif' => true]),
            'nbClasses'      => $anneeActive ? count($classeRepo->findByAnneeScolaireActive()) : 0,
            'nbMatieres'     => $matiereRepo->count([]),
            'nbSalles'       => $salleRepo->count([]),
        ]);
    }
}
