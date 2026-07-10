<?php

declare(strict_types=1);

namespace App\Website\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page vitrine du cycle Lycée (2nde à Terminale) : présentation, conditions
 * d'admission, tenue scolaire, frais de scolarité et internat.
 */
final class LyceeController extends AbstractController
{
    #[Route('/lycee', name: 'website_lycee', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('website/lycee.html.twig');
    }
}
