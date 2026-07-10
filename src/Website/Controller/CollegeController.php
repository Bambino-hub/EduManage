<?php

declare(strict_types=1);

namespace App\Website\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page vitrine du cycle Collège (6e à 3e) : présentation, conditions
 * d'admission, tenue scolaire, frais de scolarité et internat.
 */
final class CollegeController extends AbstractController
{
    #[Route('/college', name: 'website_college', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('website/college.html.twig');
    }
}
