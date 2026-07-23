<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Staff\Enum\Sexe;
use App\Student\Form\EleveImportUploadType;
use App\Student\Service\EleveImporter;
use App\Student\Service\Import\XlsxEleveReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import en masse d'élèves depuis un classeur Excel. Flux en 2 temps : upload → aperçu
 * éditable (rien n'est enregistré tant que l'admin n'a pas confirmé) → import réel —
 * même principe que l'import des enseignants (voir EnseignantImportController).
 */
#[Route('/admin/eleves/import', name: 'admin_eleve_import_')]
class EleveImportController extends AbstractController
{
    public function __construct(
        private readonly XlsxEleveReader $xlsxReader,
        private readonly EleveImporter $importer,
    ) {
    }

    #[Route('', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        $form = $this->createForm(EleveImportUploadType::class);

        return $this->render('admin/eleve/import_new.html.twig', ['form' => $form]);
    }

    #[Route('/apercu', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $form = $this->createForm(EleveImportUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/eleve/import_new.html.twig', ['form' => $form]);
        }

        /** @var UploadedFile $fichier */
        $fichier = $form->get('fichier')->getData();

        try {
            $lignes = $this->xlsxReader->lire($fichier->getPathname());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Impossible de lire ce fichier : '.$e->getMessage());
            return $this->redirectToRoute('admin_eleve_import_new');
        }

        if ($lignes === []) {
            $this->addFlash('error', 'Aucune ligne exploitable trouvée dans ce fichier — vérifiez qu\'il respecte la structure attendue (Matricule, Nom, Prénom, Sexe, Date de naissance, Classe).');
            return $this->redirectToRoute('admin_eleve_import_new');
        }

        return $this->render('admin/eleve/import_preview.html.twig', [
            'lignes' => $lignes,
            'sexes'  => Sexe::cases(),
        ]);
    }

    #[Route('/confirmer', name: 'confirm', methods: ['POST'])]
    public function confirm(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('eleve_import', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_eleve_import_new');
        }

        $matriculesUtilises = [];
        $importes = 0;
        $ignores  = 0;

        foreach ($request->getPayload()->all('eleves') as $donnees) {
            $nom       = trim((string) ($donnees['nom'] ?? ''));
            $matricule = trim((string) ($donnees['matricule'] ?? ''));
            if ($nom === '' || $matricule === '') {
                $ignores++;
                continue; // ligne vidée par l'admin en relecture, ou matricule manquant
            }

            $ligne = [
                'matricule'     => $matricule,
                'nom'           => $nom,
                'prenom'        => trim((string) ($donnees['prenom'] ?? '')),
                'sexe'          => Sexe::tryFrom((string) ($donnees['sexe'] ?? '')),
                'dateNaissance' => $this->videVersNull($donnees['dateNaissance'] ?? null),
                'classe'        => $this->videVersNull($donnees['classe'] ?? null),
            ];

            $this->importer->importerLigne($ligne, $matriculesUtilises);
            $importes++;
        }

        $em->flush();

        $message = sprintf('%d élève(s) importé(s).', $importes);
        if ($ignores > 0) {
            $message .= sprintf(' %d ligne(s) ignorée(s) (nom ou matricule manquant).', $ignores);
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_eleve_index');
    }

    private function videVersNull(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        return $valeur !== '' ? $valeur : null;
    }
}
