<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Scheduling\Entity\Creneau;
use App\Scheduling\Form\CreneauType;
use App\Scheduling\Repository\CreneauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/creneaux', name: 'admin_creneau_')]
class CreneauController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(CreneauRepository $repo): Response
    {
        return $this->render('admin/creneau/index.html.twig', [
            'creneaux' => $repo->findOrdonnes(),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $creneau = new Creneau();
        $form    = $this->createForm(CreneauType::class, $creneau);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($creneau);
            $em->flush();
            $this->addFlash('success', 'Créneau créé.');
            return $this->redirectToRoute('admin_creneau_index');
        }

        return $this->render('admin/creneau/form.html.twig', ['form' => $form, 'creneau' => $creneau]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Creneau $creneau, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CreneauType::class, $creneau);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Créneau modifié.');
            return $this->redirectToRoute('admin_creneau_index');
        }

        return $this->render('admin/creneau/form.html.twig', ['form' => $form, 'creneau' => $creneau]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Creneau $creneau, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$creneau->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($creneau);
            $em->flush();
            $this->addFlash('success', 'Créneau supprimé.');
        }
        return $this->redirectToRoute('admin_creneau_index');
    }
}
