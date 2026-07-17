<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Website\Entity\Actualite;
use App\Website\Form\ActualiteType;
use App\Website\Repository\ActualiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/actualites', name: 'admin_actualite_')]
class ActualiteController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(ActualiteRepository $repo): Response
    {
        return $this->render('admin/actualite/index.html.twig', [
            'actualites' => $repo->findBy([], ['datePublication' => 'DESC', 'id' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): Response {
        $actualite = new Actualite();
        $form      = $this->createForm(ActualiteType::class, $actualite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->traiterImage($form->get('image')->getData(), $actualite, $projectDir);
            $em->persist($actualite);
            $em->flush();
            $this->addFlash('success', 'Actualité créée.');
            return $this->redirectToRoute('admin_actualite_index');
        }

        return $this->render('admin/actualite/form.html.twig', ['form' => $form, 'actualite' => $actualite]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(
        Request $request,
        Actualite $actualite,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): Response {
        $form = $this->createForm(ActualiteType::class, $actualite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->traiterImage($form->get('image')->getData(), $actualite, $projectDir);
            $em->flush();
            $this->addFlash('success', 'Actualité modifiée.');
            return $this->redirectToRoute('admin_actualite_index');
        }

        return $this->render('admin/actualite/form.html.twig', ['form' => $form, 'actualite' => $actualite]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Actualite $actualite,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): Response {
        if ($this->isCsrfTokenValid('delete'.$actualite->getId(), $request->getPayload()->getString('_token'))) {
            if ($actualite->getImage()) {
                @unlink($projectDir.'/public/uploads/actualites/'.$actualite->getImage());
            }
            $em->remove($actualite);
            $em->flush();
            $this->addFlash('success', 'Actualité supprimée.');
        }
        return $this->redirectToRoute('admin_actualite_index');
    }

    /**
     * Déplace l'image envoyée vers public/uploads/actualites/ sous un nom aléatoire et met à
     * jour l'entité ; supprime l'ancienne image si elle est remplacée. Ne fait rien si aucun
     * fichier n'a été envoyé (image inchangée).
     */
    private function traiterImage(?UploadedFile $fichier, Actualite $actualite, string $projectDir): void
    {
        if ($fichier === null) {
            return;
        }

        $ancienneImage = $actualite->getImage();
        $nomFichier    = bin2hex(random_bytes(8)).'.'.$fichier->guessExtension();

        $fichier->move($projectDir.'/public/uploads/actualites', $nomFichier);
        $actualite->setImage($nomFichier);

        if ($ancienneImage) {
            @unlink($projectDir.'/public/uploads/actualites/'.$ancienneImage);
        }
    }
}
