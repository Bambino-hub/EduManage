<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Staff\Entity\Enseignant;
use App\Staff\Enum\TypePersonnel;
use App\Staff\Form\StagiaireType;
use App\Staff\Repository\EnseignantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des stagiaires — réutilise l'entité Enseignant (type=STAGIAIRE)
 * mais avec sa propre liste et son propre formulaire, plus courts.
 */
#[Route('/admin/stagiaires', name: 'admin_stagiaire_')]
class StagiaireController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(EnseignantRepository $repo): Response
    {
        return $this->render('admin/stagiaire/index.html.twig', [
            'stagiaires' => $repo->findBy(['type' => TypePersonnel::STAGIAIRE], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $stagiaire = new Enseignant();
        $stagiaire->setType(TypePersonnel::STAGIAIRE);

        $form = $this->createForm(StagiaireType::class, $stagiaire);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($stagiaire);
            $em->flush();
            $this->addFlash('success', 'Stagiaire enregistré.');
            return $this->redirectToRoute('admin_stagiaire_index');
        }

        return $this->render('admin/stagiaire/form.html.twig', ['form' => $form, 'stagiaire' => $stagiaire]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Enseignant $stagiaire, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(StagiaireType::class, $stagiaire);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Stagiaire modifié.');
            return $this->redirectToRoute('admin_stagiaire_index');
        }

        return $this->render('admin/stagiaire/form.html.twig', ['form' => $form, 'stagiaire' => $stagiaire]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Enseignant $stagiaire, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$stagiaire->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($stagiaire);
            $em->flush();
            $this->addFlash('success', 'Stagiaire supprimé.');
        }
        return $this->redirectToRoute('admin_stagiaire_index');
    }
}
