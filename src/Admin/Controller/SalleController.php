<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Salle;
use App\Academic\Form\SalleType;
use App\Academic\Repository\SalleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/salles', name: 'admin_salle_')]
class SalleController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(SalleRepository $repo): Response
    {
        return $this->render('admin/salle/index.html.twig', [
            'salles' => $repo->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $salle = new Salle();
        $form  = $this->createForm(SalleType::class, $salle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($salle);
            $em->flush();
            $this->addFlash('success', 'Salle créée.');
            return $this->redirectToRoute('admin_salle_index');
        }

        return $this->render('admin/salle/form.html.twig', ['form' => $form, 'salle' => $salle]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Salle $salle, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SalleType::class, $salle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Salle modifiée.');
            return $this->redirectToRoute('admin_salle_index');
        }

        return $this->render('admin/salle/form.html.twig', ['form' => $form, 'salle' => $salle]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Salle $salle, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$salle->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($salle);
            $em->flush();
            $this->addFlash('success', 'Salle supprimée.');
        }
        return $this->redirectToRoute('admin_salle_index');
    }
}
