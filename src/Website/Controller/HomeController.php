<?php

declare(strict_types=1);

namespace App\Website\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur du site vitrine (partie publique, accessible aux visiteurs non connectés).
 *
 * Rôle d'un contrôleur dans Symfony : recevoir une requête HTTP, déclencher
 * éventuellement de la logique, puis renvoyer une réponse (ici une page HTML).
 * On garde le contrôleur le plus FIN possible : aucune logique métier ici,
 * juste l'aiguillage requête -> template. C'est une bonne pratique clé.
 */
final class HomeController extends AbstractController
{
    /**
     * Page d'accueil du Collège Adèle.
     *
     * L'attribut #[Route] associe l'URL "/" (racine du site) à cette méthode.
     *   - name: 'website_home' -> nom interne de la route, utilisé pour générer
     *     des liens ailleurs (ex. path('website_home')) sans écrire l'URL en dur.
     *   - methods: ['GET'] -> cette page ne répond qu'aux requêtes de lecture.
     */
    #[Route('/', name: 'website_home', methods: ['GET'])]
    public function index(): Response
    {
        // render() fusionne le template Twig avec d'éventuelles variables
        // (ici aucune, le contenu est statique) et renvoie un objet Response.
        return $this->render('website/home.html.twig');
    }
}
