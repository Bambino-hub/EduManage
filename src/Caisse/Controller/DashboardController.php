<?php

declare(strict_types=1);

namespace App\Caisse\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/caisse', name: 'caisse_')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'dashboard')]
    public function index(): Response
    {
        return $this->render('caisse/dashboard.html.twig');
    }
}
