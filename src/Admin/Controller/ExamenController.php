<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Cycle;
use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\CycleRepository;
use App\Exam\Entity\Examen;
use App\Exam\Form\ExamenType;
use App\Exam\Service\ExamGridBuilder;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(name: 'admin_examen_')]
class ExamenController extends AbstractController
{
    #[Route('/admin/examens', name: 'index')]
    public function index(CycleRepository $cycleRepo): Response
    {
        return $this->render('admin/examen/index.html.twig', [
            'cycles' => $cycleRepo->findBy([], ['id' => 'ASC']),
        ]);
    }

    #[Route('/admin/examens/cycle/{cycle}/tableau', name: 'tableau')]
    public function tableau(
        Cycle $cycle,
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        ExamGridBuilder $gridBuilder,
    ): Response {
        $annee  = $anneeRepo->findActive();
        $lignes = $annee ? $gridBuilder->construireLignes($cycle, $annee) : [];

        return $this->render('admin/examen/tableau.html.twig', [
            'cycle'  => $cycle,
            'annee'  => $annee,
            'lignes' => $lignes,
            'entete' => $request->query->getString('entete', ''),
        ]);
    }

    #[Route('/admin/examens/cycle/{cycle}/new', name: 'new')]
    public function new(Cycle $cycle, Request $request, EntityManagerInterface $em, AnneeScolaireRepository $anneeRepo): Response
    {
        $annee = $anneeRepo->findActive();
        if ($annee === null) {
            $this->addFlash('error', 'Aucune année scolaire active. Activez une année avant de créer un examen.');
            return $this->redirectToRoute('admin_examen_tableau', ['cycle' => $cycle->getId()]);
        }

        $examen = new Examen();
        $examen->setAnneeScolaire($annee);
        $form = $this->createForm(ExamenType::class, $examen, ['cycle' => $cycle]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($examen);
            $em->flush();
            $this->addFlash('success', 'Examen créé.');
            return $this->redirectToRoute('admin_examen_tableau', ['cycle' => $cycle->getId()]);
        }

        return $this->render('admin/examen/form.html.twig', ['form' => $form, 'cycle' => $cycle, 'examen' => $examen]);
    }

    #[Route('/admin/examens/cycle/{cycle}/{examen}/edit', name: 'edit')]
    public function edit(Cycle $cycle, Examen $examen, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifierAppartenance($cycle, $examen);

        $form = $this->createForm(ExamenType::class, $examen, ['cycle' => $cycle]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Examen modifié.');
            return $this->redirectToRoute('admin_examen_tableau', ['cycle' => $cycle->getId()]);
        }

        return $this->render('admin/examen/form.html.twig', ['form' => $form, 'cycle' => $cycle, 'examen' => $examen]);
    }

    #[Route('/admin/examens/cycle/{cycle}/{examen}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Cycle $cycle, Examen $examen, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifierAppartenance($cycle, $examen);

        if ($this->isCsrfTokenValid('delete'.$examen->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($examen);
            $em->flush();
            $this->addFlash('success', 'Examen supprimé.');
        }

        return $this->redirectToRoute('admin_examen_tableau', ['cycle' => $cycle->getId()]);
    }

    #[Route('/admin/examens/cycle/{cycle}/export-pdf', name: 'export_pdf')]
    public function exportPdf(
        Cycle $cycle,
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        ExamGridBuilder $gridBuilder,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $annee  = $anneeRepo->findActive();
        $lignes = $annee ? $gridBuilder->construireLignes($cycle, $annee) : [];

        $html = $this->renderView('admin/examen/pdf/tableau.html.twig', [
            'cycle'  => $cycle,
            'annee'  => $annee,
            'lignes' => $lignes,
            'entete' => $request->query->getString('entete', ''),
        ]);

        return new Response($exporter->exporter($html), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="programme-examens-'.$cycle->getId().'.pdf"',
        ]);
    }

    private function verifierAppartenance(Cycle $cycle, Examen $examen): void
    {
        foreach ($examen->getNiveaux() as $niveau) {
            if ($niveau->getCycle() !== $cycle) {
                throw $this->createNotFoundException();
            }
        }
    }
}
