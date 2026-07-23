<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Grading\Entity\Trimestre;
use App\Grading\Form\TrimestreType;
use App\Grading\Repository\TrimestreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/trimestres', name: 'admin_trimestre_')]
class TrimestreController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(TrimestreRepository $repo): Response
    {
        return $this->render('admin/trimestre/index.html.twig', [
            'trimestres' => $repo->findBy([], ['anneeScolaire' => 'DESC', 'numero' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $trimestre = new Trimestre();
        $form      = $this->createForm(TrimestreType::class, $trimestre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($trimestre);
            $em->flush();
            $this->addFlash('success', 'Trimestre créé.');
            return $this->redirectToRoute('admin_trimestre_index');
        }

        return $this->render('admin/trimestre/form.html.twig', ['form' => $form, 'trimestre' => $trimestre]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Trimestre $trimestre, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TrimestreType::class, $trimestre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Trimestre modifié.');
            return $this->redirectToRoute('admin_trimestre_index');
        }

        return $this->render('admin/trimestre/form.html.twig', ['form' => $form, 'trimestre' => $trimestre]);
    }

    /** Un seul trimestre actif à la fois, mais seulement au sein de sa propre année scolaire. */
    #[Route('/{id}/activate', name: 'activate', methods: ['POST'])]
    public function activate(Trimestre $trimestre, TrimestreRepository $repo, EntityManagerInterface $em): Response
    {
        foreach ($repo->findByAnneeScolaire($trimestre->getAnneeScolaire()) as $t) {
            $t->setActive(false);
        }
        $trimestre->setActive(true);
        $em->flush();
        $this->addFlash('success', "{$trimestre->getLibelle()} activé.");
        return $this->redirectToRoute('admin_trimestre_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Trimestre $trimestre, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$trimestre->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($trimestre);
            $em->flush();
            $this->addFlash('success', 'Trimestre supprimé.');
        }
        return $this->redirectToRoute('admin_trimestre_index');
    }
}
