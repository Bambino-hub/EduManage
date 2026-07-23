<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Classe;
use App\Academic\Repository\ClasseRepository;
use App\Student\Entity\Eleve;
use App\Student\Form\EleveType;
use App\Student\Repository\EleveRepository;
use App\Student\Repository\InscriptionRepository;
use App\Student\Enum\StatutEleve;
use App\Student\Service\Export\ElevePdfExporter;
use App\Student\Service\Export\EleveWordExporter;
use App\Student\Service\PhotoIdentiteProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/eleves', name: 'admin_eleve_')]
class EleveController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(EleveRepository $repo): Response
    {
        return $this->render('admin/eleve/index.html.twig', [
            'eleves'  => $repo->findAllTries(),
            'statuts' => StatutEleve::cases(),
        ]);
    }

    /** Export Word de la liste d'une classe (voir écran "par classe"). */
    #[Route('/export/word/{classe}', name: 'export_word', requirements: ['classe' => '\d+'])]
    public function exportWord(Classe $classe, InscriptionRepository $inscriptionRepo, EleveWordExporter $exporter): Response
    {
        $eleves  = array_map(fn($i) => $i->getEleve(), $inscriptionRepo->findActivesByClasse($classe));
        $contenu = $exporter->exporter($eleves, 'Élèves — '.$classe->getNiveau()->getNomComplet().' — '.$classe->getNom());

        return new Response($contenu, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="eleves_'.$classe->getNom().'.docx"',
        ]);
    }

    /** Export PDF de la liste d'une classe (voir écran "par classe"). */
    #[Route('/export/pdf/{classe}', name: 'export_pdf', requirements: ['classe' => '\d+'])]
    public function exportPdf(Classe $classe, Request $request, InscriptionRepository $inscriptionRepo, ElevePdfExporter $exporter): Response
    {
        $eleves  = array_map(fn($i) => $i->getEleve(), $inscriptionRepo->findActivesByClasse($classe));
        $contenu = $exporter->exporter(
            $eleves,
            'Élèves — '.$classe->getNiveau()->getNomComplet().' — '.$classe->getNom(),
            $request->query->getBoolean('entete_college', false),
        );

        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="eleves_'.$classe->getNom().'.pdf"',
        ]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(
        Request $request,
        Eleve $eleve,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        PhotoIdentiteProcessor $photoProcessor,
    ): Response {
        $form = $this->createForm(EleveType::class, $eleve);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            self::traiterPhoto($form->get('photo')->getData(), $eleve, $projectDir, $photoProcessor);
            $em->flush();
            $this->addFlash('success', 'Élève modifié.');
            return $this->redirectToRoute('admin_eleve_index');
        }

        return $this->render('admin/eleve/form.html.twig', ['form' => $form, 'eleve' => $eleve]);
    }

    /**
     * Recadre (format photo d'identité, voir PhotoIdentiteProcessor) et enregistre la photo
     * envoyée sous public/uploads/eleves/ sous un nom aléatoire, et met à jour l'élève ;
     * supprime l'ancienne photo si elle est remplacée. Ne fait rien si aucun fichier n'a été
     * envoyé (photo inchangée). Statique et publique : réutilisée par
     * InscriptionController::nouvelle() où le champ est imbriqué dans NouvelleInscriptionType.
     */
    public static function traiterPhoto(?UploadedFile $fichier, Eleve $eleve, string $projectDir, PhotoIdentiteProcessor $photoProcessor): void
    {
        if ($fichier === null) {
            return;
        }

        $ancienne   = $eleve->getPhoto();
        $nomFichier = bin2hex(random_bytes(8)).'.jpg'; // toujours réencodée en JPEG au recadrage

        $photoProcessor->traiter($fichier->getPathname(), $projectDir.'/public/uploads/eleves/'.$nomFichier);
        $eleve->setPhoto($nomFichier);

        if ($ancienne) {
            @unlink($projectDir.'/public/uploads/eleves/'.$ancienne);
        }
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Eleve $eleve, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$eleve->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($eleve);
            $em->flush();
            $this->addFlash('success', 'Élève supprimé.');
        }
        return $this->redirectToRoute('admin_eleve_index');
    }

    /** Roster d'une classe, filtrable via le sélecteur : élèves inscrits, triés par ordre alphabétique. */
    #[Route('/par-classe', name: 'par_classe')]
    public function parClasse(Request $request, ClasseRepository $classeRepo, InscriptionRepository $inscriptionRepo): Response
    {
        $classeId = $request->query->getInt('classe') ?: null;
        $classe   = $classeId !== null ? $classeRepo->find($classeId) : null;

        return $this->render('admin/eleve/par_classe.html.twig', [
            'classes'      => $classeRepo->findByAnneeScolaireActive(),
            'classe'       => $classe,
            'inscriptions' => $classe !== null ? $inscriptionRepo->findActivesByClasse($classe) : [],
        ]);
    }

    /**
     * Fiche complète d'un élève : informations + historique des inscriptions.
     * Route générique `/{id}` placée en DERNIER, contrainte à un id numérique, pour ne
     * jamais intercepter `/new`, `/export/*`, `/import/*` ou `/par-classe/*`.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(Eleve $eleve): Response
    {
        return $this->render('admin/eleve/show.html.twig', ['eleve' => $eleve]);
    }
}
