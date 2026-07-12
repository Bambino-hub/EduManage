<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Exam\Entity\RegroupementSurveillance;
use App\Exam\Form\RegroupementSurveillanceType;
use App\Exam\Repository\RegroupementSurveillanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/regroupements-surveillance', name: 'admin_regroupement_surveillance_')]
class RegroupementSurveillanceController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(RegroupementSurveillanceRepository $repo): Response
    {
        return $this->render('admin/regroupement_surveillance/index.html.twig', [
            'regroupements' => $repo->findAllAvecRelations(),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $regroupement = new RegroupementSurveillance();
        $form         = $this->createForm(RegroupementSurveillanceType::class, $regroupement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->validerRegroupement($form)) {
                $em->persist($regroupement);
                $em->flush();
                $this->addFlash('success', 'Regroupement créé.');
                return $this->redirectToRoute('admin_regroupement_surveillance_index');
            }
        }

        return $this->render('admin/regroupement_surveillance/form.html.twig', ['form' => $form, 'regroupement' => $regroupement]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, RegroupementSurveillance $regroupement, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(RegroupementSurveillanceType::class, $regroupement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->validerRegroupement($form)) {
                $em->flush();
                $this->addFlash('success', 'Regroupement modifié.');
                return $this->redirectToRoute('admin_regroupement_surveillance_index');
            }
        }

        return $this->render('admin/regroupement_surveillance/form.html.twig', ['form' => $form, 'regroupement' => $regroupement]);
    }

    /** Au moins 2 classes — sinon le regroupement n'a aucun effet utile. */
    private function validerRegroupement(FormInterface $form): bool
    {
        if (\count($form->get('classes')->getData()) < 2) {
            $form->get('classes')->addError(new FormError('Choisissez au moins 2 classes.'));
            return false;
        }

        return true;
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, RegroupementSurveillance $regroupement, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$regroupement->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($regroupement);
            $em->flush();
            $this->addFlash('success', 'Regroupement supprimé.');
        }
        return $this->redirectToRoute('admin_regroupement_surveillance_index');
    }
}
