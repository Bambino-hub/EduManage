<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Scheduling\Entity\Attribution;
use App\Scheduling\Form\AttributionType;
use App\Scheduling\Repository\AttributionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/attributions', name: 'admin_attribution_')]
class AttributionController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(AttributionRepository $repo): Response
    {
        return $this->render('admin/attribution/index.html.twig', [
            'attributions' => $repo->createQueryBuilder('a')
                ->join('a.classe', 'cl')
                ->join('a.enseignant', 'e')
                ->join('a.matiere', 'm')
                ->orderBy('cl.nom', 'ASC')
                ->addOrderBy('e.nom', 'ASC')
                ->getQuery()
                ->getResult(),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $attribution = new Attribution();
        $form        = $this->createForm(AttributionType::class, $attribution);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($attribution);
            $em->flush();
            $this->addFlash('success', 'Attribution enregistrée.');
            return $this->redirectToRoute('admin_attribution_index');
        }

        return $this->render('admin/attribution/form.html.twig', ['form' => $form, 'attribution' => $attribution]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Attribution $attribution, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(AttributionType::class, $attribution);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Attribution modifiée.');
            return $this->redirectToRoute('admin_attribution_index');
        }

        return $this->render('admin/attribution/form.html.twig', ['form' => $form, 'attribution' => $attribution]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Attribution $attribution, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$attribution->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($attribution);
            $em->flush();
            $this->addFlash('success', 'Attribution supprimée.');
        }
        return $this->redirectToRoute('admin_attribution_index');
    }
}
