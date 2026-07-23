<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\ExamenNational\Entity\CandidatExamenNational;
use App\ExamenNational\Entity\NoteMatiereCandidat;
use App\ExamenNational\Entity\SessionExamenNational;
use App\ExamenNational\Enum\StatutSessionExamenNational;
use App\ExamenNational\Enum\TypeEpreuveExamenNational;
use App\ExamenNational\Enum\TypeExamenNational;
use App\ExamenNational\Form\ExamenNationalUploadType;
use App\ExamenNational\Service\ReleveControleService;
use App\ExamenNational\Service\ReleveExtractionService;
use App\ExamenNational\Service\RelevePdfSplitter;
use App\Staff\Enum\Sexe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import d'un relevé officiel d'examen (BEPC/BAC1/BAC2) scanné en PDF : une page par
 * candidat. Traité par petits lots de pages (voir RelevePdfSplitter/ReleveExtractionService)
 * via des appels successifs depuis la page de progression (polling JS), pour rester dans les
 * temps d'une requête web même sur un relevé de plusieurs dizaines de pages — pas de worker
 * asynchrone dans cette appli. La session reste BROUILLON (invisible dans les statistiques)
 * tant que l'admin n'a pas relu l'aperçu et confirmé.
 */
#[Route('/admin/examens-nationaux/import', name: 'admin_releve_national_import_')]
class ExamenNationalImportController extends AbstractController
{
    private const TAILLE_LOT_DEFAUT = 3;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/examens_nationaux')]
        private readonly string $dossierStockage,
    ) {
    }

    #[Route('', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('admin/examen_national/import_new.html.twig', [
            'form' => $this->createForm(ExamenNationalUploadType::class),
        ]);
    }

    #[Route('/televerser', name: 'upload', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $em, RelevePdfSplitter $splitter): Response
    {
        $form = $this->createForm(ExamenNationalUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/examen_national/import_new.html.twig', ['form' => $form]);
        }

        /** @var TypeExamenNational $type */
        $type = $form->get('type')->getData();
        /** @var UploadedFile $fichier */
        $fichier = $form->get('fichier')->getData();

        $session = new SessionExamenNational();
        $session->setType($type);
        $em->persist($session);
        $em->flush(); // id nécessaire pour nommer le fichier stocké

        if (!is_dir($this->dossierStockage)) {
            mkdir($this->dossierStockage, 0775, true);
        }
        $nomFichier = $session->getId().'.pdf';
        $fichier->move($this->dossierStockage, $nomFichier);
        $cheminComplet = $this->dossierStockage.'/'.$nomFichier;

        try {
            $totalPages = $splitter->compterPages($cheminComplet);
        } catch (\Throwable $e) {
            @unlink($cheminComplet);
            $em->remove($session);
            $em->flush();
            $this->addFlash('error', 'Impossible de lire ce PDF : '.$e->getMessage());
            return $this->redirectToRoute('admin_releve_national_import_new');
        }

        $session->setCheminFichierTemporaire($nomFichier);
        $session->setTotalPages($totalPages);
        $session->setTaillePagesLot(self::TAILLE_LOT_DEFAUT);
        $em->flush();

        return $this->redirectToRoute('admin_releve_national_import_progression', ['id' => $session->getId()]);
    }

    #[Route('/{id}/progression', name: 'progression', methods: ['GET'])]
    public function progression(SessionExamenNational $session): Response
    {
        if ($session->getStatut() !== StatutSessionExamenNational::BROUILLON) {
            return $this->redirectToRoute('admin_releve_national_show', ['id' => $session->getId()]);
        }
        if ($session->estTermine()) {
            return $this->redirectToRoute('admin_releve_national_import_apercu', ['id' => $session->getId()]);
        }

        return $this->render('admin/examen_national/import_progression.html.twig', ['session' => $session]);
    }

    #[Route('/{id}/lot', name: 'lot', methods: ['POST'])]
    public function traiterLot(
        SessionExamenNational $session,
        Request $request,
        EntityManagerInterface $em,
        RelevePdfSplitter $splitter,
        ReleveExtractionService $extractionService,
        ReleveControleService $controleService,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('examen_national_lot'.$session->getId(), $request->getPayload()->getString('_token'))) {
            return $this->json(['erreur' => 'Jeton de sécurité invalide.'], 403);
        }

        if ($session->estTermine() || $session->getCheminFichierTemporaire() === null) {
            return $this->json(['traite' => $session->getPagesTraitees(), 'total' => $session->getTotalPages(), 'termine' => true]);
        }

        $tailleLot  = $session->getTaillePagesLot() ?? self::TAILLE_LOT_DEFAUT;
        $pageDebut  = $session->getPagesTraitees() + 1;
        $pageFin    = min($session->getPagesTraitees() + $tailleLot, $session->getTotalPages());
        $premierLot = $session->getPagesTraitees() === 0;

        $cheminSource = $this->dossierStockage.'/'.$session->getCheminFichierTemporaire();
        $cheminLot    = $splitter->extraireLot($cheminSource, $pageDebut, $pageFin);

        try {
            $candidatsExtraits = $extractionService->extraire($cheminLot);
        } catch (\Throwable $e) {
            @unlink($cheminLot);
            return $this->json(['erreur' => $e->getMessage()], 500);
        }
        @unlink($cheminLot);

        foreach ($candidatsExtraits as $index => $candidatExtrait) {
            $candidat = new CandidatExamenNational();
            $candidat->setSession($session);
            $candidat->setNom($candidatExtrait->nom);
            $candidat->setPrenoms($candidatExtrait->prenoms);
            $candidat->setSexe($candidatExtrait->sexe !== null ? Sexe::tryFrom($candidatExtrait->sexe) : null);
            $candidat->setDateNaissance($this->parserDate($candidatExtrait->dateNaissance));
            $candidat->setLieuNaissance($candidatExtrait->lieuNaissance);
            $candidat->setNumeroJury($candidatExtrait->numeroJury);
            $candidat->setNumeroTable($candidatExtrait->numeroTable);
            $candidat->setDecisionJury($candidatExtrait->decisionJury);
            $candidat->setMoyenneGlobaleAffichee($this->versDecimal($candidatExtrait->moyenneGlobaleAffichee));
            $candidat->setTotalPointsEcritesAffiche($this->versDecimal($candidatExtrait->totalPointsEcritesAffiche));
            $candidat->setPageNumero($pageDebut + $index);

            $controle = $controleService->controler($candidatExtrait);
            $candidat->setControleArithmetiqueOk($controle['ok']);
            $candidat->setEcartControle($this->versDecimal($controle['ecart']));

            $em->persist($candidat);

            foreach ($candidatExtrait->notes as $noteExtraite) {
                $note = new NoteMatiereCandidat();
                $note->setCandidat($candidat);
                $note->setTypeEpreuve(TypeEpreuveExamenNational::from($noteExtraite->typeEpreuve));
                $note->setMatiereLibelle($noteExtraite->matiere);
                $note->setNote($this->versDecimal($noteExtraite->note));
                $note->setCoefficient($this->versDecimal($noteExtraite->coefficient));
                $note->setPointsObtenus($this->versDecimal($noteExtraite->pointsObtenus));
                $em->persist($note);
            }

            if ($premierLot && $index === 0) {
                $session->setSerie($candidatExtrait->serie ?? '');
                $session->setLibelleSerie($candidatExtrait->libelleSerie);
                $session->setCentreExamen($candidatExtrait->centreExamen);
                $session->setAnneeSession($this->parserAnnee($candidatExtrait->session));
            }
        }

        $session->setPagesTraitees($pageFin);
        $em->flush();

        return $this->json([
            'traite'  => $session->getPagesTraitees(),
            'total'   => $session->getTotalPages(),
            'termine' => $session->estTermine(),
        ]);
    }

    #[Route('/{id}/apercu', name: 'apercu', methods: ['GET'])]
    public function apercu(SessionExamenNational $session): Response
    {
        if (!$session->estTermine()) {
            return $this->redirectToRoute('admin_releve_national_import_progression', ['id' => $session->getId()]);
        }

        $candidats     = $session->getCandidats();
        $ok            = 0;
        $pagesPresentes = [];
        foreach ($candidats as $candidat) {
            if ($candidat->isControleArithmetiqueOk()) {
                $ok++;
            }
            $pagesPresentes[$candidat->getPageNumero()] = true;
        }

        $pagesManquantes = [];
        for ($page = 1; $page <= $session->getTotalPages(); $page++) {
            if (!isset($pagesPresentes[$page])) {
                $pagesManquantes[] = $page;
            }
        }

        return $this->render('admin/examen_national/import_apercu.html.twig', [
            'session'          => $session,
            'candidats'        => $candidats,
            'nbOk'             => $ok,
            'nbTotal'          => count($candidats),
            'pagesManquantes'  => $pagesManquantes,
        ]);
    }

    #[Route('/{id}/confirmer', name: 'confirm', methods: ['POST'])]
    public function confirmer(SessionExamenNational $session, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('examen_national_import'.$session->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_releve_national_import_apercu', ['id' => $session->getId()]);
        }
        if (!$session->estTermine()) {
            return $this->redirectToRoute('admin_releve_national_import_progression', ['id' => $session->getId()]);
        }

        if ($session->getCheminFichierTemporaire() !== null) {
            @unlink($this->dossierStockage.'/'.$session->getCheminFichierTemporaire());
            $session->setCheminFichierTemporaire(null);
        }
        $session->setStatut(StatutSessionExamenNational::VALIDE);
        $em->flush();

        $this->addFlash('success', sprintf('%d candidat(s) enregistré(s).', count($session->getCandidats())));

        return $this->redirectToRoute('admin_releve_national_show', ['id' => $session->getId()]);
    }

    #[Route('/{id}/abandonner', name: 'abandon', methods: ['POST'])]
    public function abandonner(SessionExamenNational $session, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('examen_national_abandon'.$session->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_releve_national_import_new');
        }

        if ($session->getCheminFichierTemporaire() !== null) {
            @unlink($this->dossierStockage.'/'.$session->getCheminFichierTemporaire());
        }
        $em->remove($session);
        $em->flush();

        $this->addFlash('success', 'Import abandonné.');
        return $this->redirectToRoute('admin_releve_national_import_new');
    }

    private function parserDate(?string $valeur): ?\DateTimeImmutable
    {
        if ($valeur === null) {
            return null;
        }
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, trim($valeur));
            if ($date !== false) {
                return $date;
            }
        }
        return null;
    }

    private function parserAnnee(?string $valeur): ?int
    {
        if ($valeur !== null && preg_match('/(\d{4})/', $valeur, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    private function versDecimal(?float $valeur): ?string
    {
        return $valeur !== null ? number_format($valeur, 2, '.', '') : null;
    }
}
