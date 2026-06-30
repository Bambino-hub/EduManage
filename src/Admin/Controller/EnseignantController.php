<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Staff\Entity\Enseignant;
use App\Staff\Form\EnseignantType;
use App\Staff\Repository\EnseignantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/enseignants', name: 'admin_enseignant_')]
class EnseignantController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(EnseignantRepository $repo): Response
    {
        return $this->render('admin/enseignant/index.html.twig', [
            'enseignants' => $repo->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $enseignant = new Enseignant();
        $form       = $this->createForm(EnseignantType::class, $enseignant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($enseignant);
            $em->flush();
            $this->addFlash('success', 'Enseignant enregistré.');
            return $this->redirectToRoute('admin_enseignant_index');
        }

        return $this->render('admin/enseignant/form.html.twig', ['form' => $form, 'enseignant' => $enseignant]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Enseignant $enseignant, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(EnseignantType::class, $enseignant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Enseignant modifié.');
            return $this->redirectToRoute('admin_enseignant_index');
        }

        return $this->render('admin/enseignant/form.html.twig', ['form' => $form, 'enseignant' => $enseignant]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Enseignant $enseignant, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$enseignant->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($enseignant);
            $em->flush();
            $this->addFlash('success', 'Enseignant supprimé.');
        }
        return $this->redirectToRoute('admin_enseignant_index');
    }
}
