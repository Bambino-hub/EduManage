<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Staff\Enum\Sexe;
use App\Staff\Enum\TypePersonnel;
use App\Staff\Form\EnseignantImportUploadType;
use App\Staff\Service\EnseignantImporter;
use App\Staff\Service\Import\DocxEnseignantReader;
use App\Staff\Service\Import\PdfEnseignantReader;
use App\Staff\Service\Import\XlsxEnseignantReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import en masse d'enseignants/personnel depuis un document Word/Excel/PDF au format
 * fixe "liste du personnel" (mêmes colonnes que le document déjà importé via la
 * commande `app:staff:import-enseignants`). Flux en 2 temps : upload → aperçu éditable
 * (rien n'est enregistré tant que l'admin n'a pas confirmé) → import réel.
 */
#[Route('/admin/enseignants/import', name: 'admin_enseignant_import_')]
class EnseignantImportController extends AbstractController
{
    public function __construct(
        private readonly DocxEnseignantReader $docxReader,
        private readonly XlsxEnseignantReader $xlsxReader,
        private readonly PdfEnseignantReader $pdfReader,
        private readonly EnseignantImporter $importer,
    ) {
    }

    #[Route('', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        $form = $this->createForm(EnseignantImportUploadType::class);

        return $this->render('admin/enseignant/import_new.html.twig', ['form' => $form]);
    }

    #[Route('/apercu', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $form = $this->createForm(EnseignantImportUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/enseignant/import_new.html.twig', ['form' => $form]);
        }

        /** @var UploadedFile $fichier */
        $fichier   = $form->get('fichier')->getData();
        $extension = strtolower($fichier->getClientOriginalExtension());

        try {
            $lignes = match ($extension) {
                'docx'  => $this->docxReader->lire($fichier->getPathname()),
                'xlsx'  => $this->xlsxReader->lire($fichier->getPathname()),
                'pdf'   => $this->pdfReader->lire($fichier->getPathname()),
                default => throw new \RuntimeException("Format non pris en charge : .{$extension}"),
            };
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Impossible de lire ce fichier : '.$e->getMessage());

            return $this->redirectToRoute('admin_enseignant_import_new');
        }

        if ($lignes === []) {
            $this->addFlash('error', 'Aucune ligne exploitable trouvée dans ce fichier — vérifiez qu\'il respecte la structure attendue.');

            return $this->redirectToRoute('admin_enseignant_import_new');
        }

        // E-mail purement indicatif pour la relecture : recalculé pour de vrai à la confirmation
        // (si le nom/prénom est corrigé ici, l'e-mail affiché doit rester cohérent avec ça).
        $emailsApercu = [];
        foreach ($lignes as &$ligne) {
            $ligne['emailApercu'] = $this->importer->genererEmail($ligne['nom'], $ligne['prenom'], $emailsApercu);
        }
        unset($ligne);

        return $this->render('admin/enseignant/import_preview.html.twig', [
            'lignes'        => $lignes,
            'extension'     => $extension,
            'sexes'         => Sexe::cases(),
            'typesPersonnel' => TypePersonnel::cases(),
        ]);
    }

    #[Route('/confirmer', name: 'confirm', methods: ['POST'])]
    public function confirm(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('enseignant_import', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');

            return $this->redirectToRoute('admin_enseignant_import_new');
        }

        $emailsUtilises = [];
        $created        = 0;
        $updated        = 0;

        foreach ($request->getPayload()->all('enseignants') as $donnees) {
            $nom = trim((string) ($donnees['nom'] ?? ''));
            if ($nom === '') {
                continue; // ligne vidée par l'admin en relecture : on l'ignore, pas une erreur
            }

            $ligne = [
                'nom'        => $nom,
                'prenom'     => trim((string) ($donnees['prenom'] ?? '')),
                'sexe'       => Sexe::tryFrom((string) ($donnees['sexe'] ?? '')),
                'matricule'  => $this->videVersNull($donnees['matricule'] ?? null),
                'poste'      => $this->videVersNull($donnees['poste'] ?? null),
                'specialite' => $this->videVersNull($donnees['specialite'] ?? null),
                'cycle'      => $this->videVersNull($donnees['cycle'] ?? null),
                'telephone'  => trim((string) ($donnees['telephone'] ?? '')),
                'type'       => TypePersonnel::tryFrom((string) ($donnees['type'] ?? '')) ?? TypePersonnel::INTERNE,
            ];

            $enseignant = $this->importer->importerLigne($ligne, $emailsUtilises);
            $enseignant->getId() === null ? $created++ : $updated++;
        }

        $em->flush();

        $this->addFlash('success', sprintf('%d enseignant(s)/personnel créé(s), %d mis à jour.', $created, $updated));

        return $this->redirectToRoute('admin_enseignant_index');
    }

    private function videVersNull(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur !== '' ? $valeur : null;
    }
}
