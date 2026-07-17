<?php

declare(strict_types=1);

namespace App\Security\Controller;

use App\Security\Entity\Utilisateur;
use App\Security\Form\ChangePasswordType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ProfileController extends AbstractController
{
    #[Route('/mon-compte/mot-de-passe', name: 'security_change_password')]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();

            if (!$passwordHasher->isPasswordValid($utilisateur, $currentPassword)) {
                $form->get('currentPassword')->addError(new FormError('Mot de passe actuel incorrect.'));
            } else {
                $plainPassword = $form->get('plainPassword')->getData();
                $utilisateur->setPassword($passwordHasher->hashPassword($utilisateur, $plainPassword));
                $utilisateur->setDoitChangerMotDePasse(false);
                $em->flush();

                $this->addFlash('success', 'Mot de passe mis à jour.');
                return $this->redirectToRoute('security_apres_connexion');
            }
        }

        return $this->render('security/change_password.html.twig', ['form' => $form]);
    }
}
