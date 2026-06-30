<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Cycle;
use App\Academic\Form\CycleType;
use App\Academic\Repository\CycleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/cycles', name: 'admin_cycle_')]
class CycleController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(CycleRepository $repo): Response
    {
        return $this->render('admin/cycle/index.html.twig', [
            'cycles' => $repo->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $cycle = new Cycle();
        $form  = $this->createForm(CycleType::class, $cycle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($cycle);
            $em->flush();
            $this->addFlash('success', 'Cycle créé.');
            return $this->redirectToRoute('admin_cycle_index');
        }

        return $this->render('admin/cycle/form.html.twig', ['form' => $form, 'cycle' => $cycle]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Cycle $cycle, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CycleType::class, $cycle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Cycle modifié.');
            return $this->redirectToRoute('admin_cycle_index');
        }

        return $this->render('admin/cycle/form.html.twig', ['form' => $form, 'cycle' => $cycle]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Cycle $cycle, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$cycle->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($cycle);
            $em->flush();
            $this->addFlash('success', 'Cycle supprimé.');
        }
        return $this->redirectToRoute('admin_cycle_index');
    }
}
