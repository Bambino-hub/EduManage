<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Niveau;
use App\Academic\Form\NiveauType;
use App\Academic\Repository\NiveauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/niveaux', name: 'admin_niveau_')]
class NiveauController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(NiveauRepository $repo): Response
    {
        return $this->render('admin/niveau/index.html.twig', [
            'niveaux' => $repo->findBy([], ['ordre' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $niveau = new Niveau();
        $form   = $this->createForm(NiveauType::class, $niveau);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($niveau);
            $em->flush();
            $this->addFlash('success', 'Niveau créé.');
            return $this->redirectToRoute('admin_niveau_index');
        }

        return $this->render('admin/niveau/form.html.twig', ['form' => $form, 'niveau' => $niveau]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Niveau $niveau, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(NiveauType::class, $niveau);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Niveau modifié.');
            return $this->redirectToRoute('admin_niveau_index');
        }

        return $this->render('admin/niveau/form.html.twig', ['form' => $form, 'niveau' => $niveau]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Niveau $niveau, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$niveau->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($niveau);
            $em->flush();
            $this->addFlash('success', 'Niveau supprimé.');
        }
        return $this->redirectToRoute('admin_niveau_index');
    }
}
