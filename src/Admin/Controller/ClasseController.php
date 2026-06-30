<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Classe;
use App\Academic\Form\ClasseType;
use App\Academic\Repository\ClasseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/classes', name: 'admin_classe_')]
class ClasseController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(ClasseRepository $repo): Response
    {
        return $this->render('admin/classe/index.html.twig', [
            'classes' => $repo->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $classe = new Classe();
        $form   = $this->createForm(ClasseType::class, $classe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($classe);
            $em->flush();
            $this->addFlash('success', 'Classe créée.');
            return $this->redirectToRoute('admin_classe_index');
        }

        return $this->render('admin/classe/form.html.twig', ['form' => $form, 'classe' => $classe]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Classe $classe, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ClasseType::class, $classe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Classe modifiée.');
            return $this->redirectToRoute('admin_classe_index');
        }

        return $this->render('admin/classe/form.html.twig', ['form' => $form, 'classe' => $classe]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Classe $classe, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$classe->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($classe);
            $em->flush();
            $this->addFlash('success', 'Classe supprimée.');
        }
        return $this->redirectToRoute('admin_classe_index');
    }
}
