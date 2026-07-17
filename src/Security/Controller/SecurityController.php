<?php

declare(strict_types=1);

namespace App\Security\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'security_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('security_apres_connexion');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Point de redirection unique après connexion : `default_target_path` ne peut pas
     * différer par rôle nativement dans le firewall, donc on route ici puis on renvoie
     * chacun vers son espace (admin ou enseignant).
     */
    #[Route('/apres-connexion', name: 'security_apres_connexion')]
    public function apresConnexion(): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->redirectToRoute('teacher_dashboard');
    }

    /** Jamais exécutée : interceptée par le firewall (config `logout`) avant d'atteindre le contrôleur. */
    #[Route('/logout', name: 'security_logout')]
    public function logout(): void
    {
        throw new \LogicException('Cette méthode ne devrait jamais être appelée directement.');
    }
}
