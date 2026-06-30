<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\MatiereNiveau;
use App\Academic\Form\MatiereType;
use App\Academic\Repository\MatiereRepository;
use App\Academic\Repository\NiveauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/matieres', name: 'admin_matiere_')]
class MatiereController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(MatiereRepository $repo): Response
    {
        return $this->render('admin/matiere/index.html.twig', [
            'matieres' => $repo->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, NiveauRepository $niveauRepo): Response
    {
        $matiere = new Matiere();
        $this->preRemplirNiveaux($matiere, $niveauRepo);

        $form = $this->createForm(MatiereType::class, $matiere);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($matiere);
            $em->flush();
            $this->addFlash('success', 'Matière créée.');
            return $this->redirectToRoute('admin_matiere_index');
        }

        return $this->render('admin/matiere/form.html.twig', ['form' => $form, 'matiere' => $matiere]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Matiere $matiere, EntityManagerInterface $em, NiveauRepository $niveauRepo): Response
    {
        $this->preRemplirNiveaux($matiere, $niveauRepo);

        $form = $this->createForm(MatiereType::class, $matiere);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Matière modifiée.');
            return $this->redirectToRoute('admin_matiere_index');
        }

        return $this->render('admin/matiere/form.html.twig', ['form' => $form, 'matiere' => $matiere]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Matiere $matiere, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$matiere->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($matiere);
            $em->flush();
            $this->addFlash('success', 'Matière supprimée.');
        }
        return $this->redirectToRoute('admin_matiere_index');
    }

    /**
     * Ajoute un MatiereNiveau pour chaque niveau qui n'en a pas encore,
     * trié par cycle puis par ordre dans le cycle.
     */
    private function preRemplirNiveaux(Matiere $matiere, NiveauRepository $niveauRepo): void
    {
        $niveauxExistants = [];
        foreach ($matiere->getMatiereNiveaux() as $mn) {
            $niveauxExistants[$mn->getNiveau()->getId()] = true;
        }

        $tousNiveaux = $niveauRepo->createQueryBuilder('n')
            ->join('n.cycle', 'c')
            ->orderBy('c.id', 'ASC')
            ->addOrderBy('n.ordre', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($tousNiveaux as $niveau) {
            if (!isset($niveauxExistants[$niveau->getId()])) {
                $mn = new MatiereNiveau();
                $mn->setNiveau($niveau);
                $matiere->addMatiereNiveau($mn);
            }
        }
    }
}
