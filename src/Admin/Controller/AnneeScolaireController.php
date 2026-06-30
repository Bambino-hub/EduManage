<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Form\AnneeScolaireType;
use App\Academic\Repository\AnneeScolaireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/annees', name: 'admin_annee_')]
class AnneeScolaireController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(AnneeScolaireRepository $repo): Response
    {
        return $this->render('admin/annee/index.html.twig', [
            'annees' => $repo->findBy([], ['libelle' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $annee = new AnneeScolaire();
        $form  = $this->createForm(AnneeScolaireType::class, $annee);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($annee);
            $em->flush();
            $this->addFlash('success', 'Année scolaire créée.');
            return $this->redirectToRoute('admin_annee_index');
        }

        return $this->render('admin/annee/form.html.twig', ['form' => $form, 'annee' => $annee]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, AnneeScolaire $annee, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(AnneeScolaireType::class, $annee);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Année scolaire modifiée.');
            return $this->redirectToRoute('admin_annee_index');
        }

        return $this->render('admin/annee/form.html.twig', ['form' => $form, 'annee' => $annee]);
    }

    #[Route('/{id}/activate', name: 'activate', methods: ['POST'])]
    public function activate(AnneeScolaire $annee, AnneeScolaireRepository $repo, EntityManagerInterface $em): Response
    {
        foreach ($repo->findAll() as $a) {
            $a->setActive(false);
        }
        $annee->setActive(true);
        $em->flush();
        $this->addFlash('success', "Année {$annee->getLibelle()} activée.");
        return $this->redirectToRoute('admin_annee_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, AnneeScolaire $annee, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$annee->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($annee);
            $em->flush();
            $this->addFlash('success', 'Année scolaire supprimée.');
        }
        return $this->redirectToRoute('admin_annee_index');
    }
}
