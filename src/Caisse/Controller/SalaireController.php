<?php

declare(strict_types=1);

namespace App\Caisse\Controller;

use App\Salaire\Entity\LignePaiementSalaire;
use App\Salaire\Entity\PaiementSalaire;
use App\Salaire\Form\PaiementSalaireType;
use App\Salaire\Repository\PaiementSalaireRepository;
use App\Salaire\Service\NumeroPaiementSalaireGenerator;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use App\Shared\Repository\EtablissementRepository;
use App\Staff\Entity\Enseignant;
use App\Staff\Repository\EnseignantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Paiement des salaires du personnel : versements libres (montant + libellé), reçu PDF
 * distinct de celui de l'écolage — pas de tarif/solde ici (voir Salaire\Entity\PaiementSalaire).
 */
#[Route('/caisse/salaires', name: 'caisse_salaire_')]
class SalaireController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(EnseignantRepository $enseignantRepo): Response
    {
        return $this->render('caisse/salaire/index.html.twig', [
            'personnel' => $enseignantRepo->findHorsStagiaires(),
        ]);
    }

    #[Route('/{id}', name: 'historique')]
    public function historique(Enseignant $enseignant, PaiementSalaireRepository $paiementRepo): Response
    {
        return $this->render('caisse/salaire/historique.html.twig', [
            'enseignant' => $enseignant,
            'paiements'  => $paiementRepo->findByEnseignant($enseignant),
        ]);
    }

    #[Route('/{id}/nouveau-paiement', name: 'nouveau')]
    public function nouveau(
        Enseignant $enseignant,
        Request $request,
        NumeroPaiementSalaireGenerator $numeroGenerator,
        EntityManagerInterface $em,
    ): Response {
        $paiement = new PaiementSalaire();
        $paiement->addLigne(new LignePaiementSalaire());

        $form = $this->createForm(PaiementSalaireType::class, $paiement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $paiement->setEnseignant($enseignant);
            $paiement->setDateEmission(new \DateTimeImmutable());
            $paiement->setNumero($numeroGenerator->generer());

            $em->persist($paiement);
            $em->flush();

            $this->addFlash('success', 'Paiement n°'.$paiement->getNumero().' enregistré.');
            return $this->redirectToRoute('caisse_salaire_historique', ['id' => $enseignant->getId()]);
        }

        return $this->render('caisse/salaire/nouveau_paiement.html.twig', [
            'form'       => $form,
            'enseignant' => $enseignant,
        ]);
    }

    #[Route('/paiements/{id}/pdf', name: 'pdf')]
    public function pdf(
        PaiementSalaire $paiement,
        Request $request,
        EtablissementRepository $etablissementRepo,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $html = $this->renderView('caisse/salaire/pdf/paiement.html.twig', [
            'paiement'      => $paiement,
            'avecEntete'    => $request->query->getBoolean('entete_college', true),
            'etablissement' => $etablissementRepo->getOuCreer(),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'salaire-'.$paiement->getNumero().'-'.$paiement->getEnseignant()->getNomComplet(),
        )->lower().'.pdf';

        // Format ticket de caisse étroit (80mm) — même moteur/gabarit que le reçu d'écolage.
        $papier = [0, 0, self::LARGEUR_TICKET_PT, max(320, 260 + \count($paiement->getLignes()) * 14)];

        return $this->reponsePdf($exporter->exporter($html, 'portrait', $papier), $nomFichier);
    }

    /** Largeur ticket 80mm, comme une imprimante à reçus. */
    private const LARGEUR_TICKET_PT = 226.77;

    #[Route('/paiements/{id}/supprimer', name: 'supprimer', methods: ['POST'])]
    public function supprimer(PaiementSalaire $paiement, Request $request, EntityManagerInterface $em): Response
    {
        $enseignantId = $paiement->getEnseignant()->getId();

        if (!$this->isCsrfTokenValid('supprimer_paiement_salaire_'.$paiement->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('caisse_salaire_historique', ['id' => $enseignantId]);
        }

        $em->remove($paiement);
        $em->flush();

        $this->addFlash('success', 'Paiement supprimé.');
        return $this->redirectToRoute('caisse_salaire_historique', ['id' => $enseignantId]);
    }

    private function reponsePdf(string $contenu, string $nomFichier): Response
    {
        return new Response($contenu, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nomFichier.'"',
        ]);
    }
}
