<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Scheduling\Entity\RegroupementClasse;
use App\Scheduling\Form\RegroupementClasseType;
use App\Scheduling\Repository\RegroupementClasseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/regroupements-classes', name: 'admin_regroupement_classe_')]
class RegroupementClasseController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(RegroupementClasseRepository $repo): Response
    {
        return $this->render('admin/regroupement_classe/index.html.twig', [
            'regroupements' => $repo->findAllAvecRelations(),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $regroupement = new RegroupementClasse();
        $form         = $this->createForm(RegroupementClasseType::class, $regroupement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->validerRegroupement($form)) {
                $em->persist($regroupement);
                $em->flush();
                $this->addFlash('success', 'Regroupement créé.');
                return $this->redirectToRoute('admin_regroupement_classe_index');
            }
        }

        return $this->render('admin/regroupement_classe/form.html.twig', ['form' => $form, 'regroupement' => $regroupement]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, RegroupementClasse $regroupement, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(RegroupementClasseType::class, $regroupement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->validerRegroupement($form)) {
                $em->flush();
                $this->addFlash('success', 'Regroupement modifié.');
                return $this->redirectToRoute('admin_regroupement_classe_index');
            }
        }

        return $this->render('admin/regroupement_classe/form.html.twig', ['form' => $form, 'regroupement' => $regroupement]);
    }

    /** Au moins 2 classes et 1 matière — sinon le regroupement n'a aucun effet utile. */
    private function validerRegroupement(FormInterface $form): bool
    {
        $valide = true;

        if (\count($form->get('classes')->getData()) < 2) {
            $form->get('classes')->addError(new FormError('Choisissez au moins 2 classes.'));
            $valide = false;
        }
        if (\count($form->get('matieres')->getData()) < 1) {
            $form->get('matieres')->addError(new FormError('Choisissez au moins 1 matière.'));
            $valide = false;
        }

        return $valide;
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, RegroupementClasse $regroupement, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$regroupement->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($regroupement);
            $em->flush();
            $this->addFlash('success', 'Regroupement supprimé.');
        }
        return $this->redirectToRoute('admin_regroupement_classe_index');
    }
}
