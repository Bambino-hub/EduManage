<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Shared\Entity\Etablissement;
use App\Shared\Form\EtablissementType;
use App\Shared\Repository\EtablissementRepository;
use App\Shared\Service\ImageTransparenceProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réglages globaux de l'établissement : identité, cachet et signature du chef
 * d'établissement, apposés automatiquement sur les bulletins (voir BulletinController et
 * templates/admin/bulletin/pdf/bulletin.html.twig).
 */
#[Route('/admin/etablissement', name: 'admin_etablissement_')]
class EtablissementController extends AbstractController
{
    #[Route('', name: 'edit')]
    public function edit(
        Request $request,
        EtablissementRepository $repo,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        ImageTransparenceProcessor $transparenceProcessor,
    ): Response {
        $etablissement = $repo->getOuCreer();
        $form          = $this->createForm(EtablissementType::class, $etablissement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('supprimerCachet')->getData()) {
                self::supprimerImage($etablissement, 'cachet', $projectDir);
            }
            if ($form->get('supprimerSignatureChefEtablissement')->getData()) {
                self::supprimerImage($etablissement, 'signatureChefEtablissement', $projectDir);
            }
            self::traiterImage($form->get('cachet')->getData(), $etablissement, 'cachet', $projectDir, $transparenceProcessor);
            self::traiterImage($form->get('signatureChefEtablissement')->getData(), $etablissement, 'signatureChefEtablissement', $projectDir, $transparenceProcessor);
            $em->flush();
            $this->addFlash('success', 'Réglages de l\'établissement enregistrés.');
            return $this->redirectToRoute('admin_etablissement_edit');
        }

        return $this->render('admin/etablissement/edit.html.twig', [
            'form'          => $form,
            'etablissement' => $etablissement,
        ]);
    }

    /**
     * Retire le fond blanc du scan envoyé (voir ImageTransparenceProcessor) et l'enregistre
     * sous public/uploads/etablissement/ sous un nom aléatoire ; supprime l'ancienne image si
     * elle est remplacée. Ne fait rien si aucun fichier n'a été envoyé. `$champ` vaut
     * "cachet" ou "signatureChefEtablissement" — mutualise le traitement des deux images.
     */
    private static function traiterImage(
        ?UploadedFile $fichier,
        Etablissement $etablissement,
        string $champ,
        string $projectDir,
        ImageTransparenceProcessor $transparenceProcessor,
    ): void {
        if ($fichier === null) {
            return;
        }

        $getter     = 'get'.ucfirst($champ);
        $setter     = 'set'.ucfirst($champ);
        $ancienne   = $etablissement->$getter();
        $dossier    = $projectDir.'/public/uploads/etablissement';
        $nomFichier = $champ.'_'.bin2hex(random_bytes(8)).'.png';

        if (!is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }

        $transparenceProcessor->traiter($fichier->getPathname(), $dossier.'/'.$nomFichier);
        $etablissement->$setter($nomFichier);

        if ($ancienne) {
            @unlink($dossier.'/'.$ancienne);
        }
    }

    /** Retire un cachet/signature existant (sans remplacement) : supprime le fichier et vide le champ. */
    private static function supprimerImage(Etablissement $etablissement, string $champ, string $projectDir): void
    {
        $getter = 'get'.ucfirst($champ);
        $setter = 'set'.ucfirst($champ);
        $ancien = $etablissement->$getter();

        if ($ancien === null) {
            return;
        }

        @unlink($projectDir.'/public/uploads/etablissement/'.$ancien);
        $etablissement->$setter(null);
    }
}
