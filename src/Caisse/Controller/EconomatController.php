<?php

declare(strict_types=1);

namespace App\Caisse\Controller;

use App\Academic\Entity\Niveau;
use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\NiveauRepository;
use App\Economat\Entity\LigneRecu;
use App\Economat\Entity\Recu;
use App\Economat\Entity\Tarif;
use App\Economat\Form\RecuType;
use App\Economat\Form\TarifType;
use App\Economat\Repository\RecuRepository;
use App\Economat\Repository\TarifRepository;
use App\Economat\Service\NumeroRecuGenerator;
use App\Economat\Service\SoldeCalculator;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use App\Shared\Repository\EtablissementRepository;
use App\Student\Entity\Eleve;
use App\Student\Repository\InscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Économat : tarifs par niveau, encaissement des versements d'écolage (reçus multi-lignes,
 * libellé et montant libres) et génération PDF des reçus. Voir Economat\Entity\{Tarif,Recu,LigneRecu}.
 */
#[Route('/caisse/economat', name: 'caisse_economat_')]
class EconomatController extends AbstractController
{
    #[Route('/tarifs', name: 'tarifs')]
    public function tarifs(
        NiveauRepository $niveauRepo,
        AnneeScolaireRepository $anneeRepo,
        TarifRepository $tarifRepo,
    ): Response {
        $anneeActive = $anneeRepo->findActive();
        $niveaux     = $niveauRepo->findBy([], ['ordre' => 'ASC']);

        $tarifsParNiveauId = [];
        if ($anneeActive !== null) {
            foreach ($tarifRepo->findAllPourAnnee($anneeActive) as $tarif) {
                $tarifsParNiveauId[$tarif->getNiveau()->getId()] = $tarif;
            }
        }

        return $this->render('caisse/economat/tarifs.html.twig', [
            'anneeActive'        => $anneeActive,
            'niveaux'            => $niveaux,
            'tarifsParNiveauId'  => $tarifsParNiveauId,
        ]);
    }

    #[Route('/tarifs/{id}/editer', name: 'tarif_editer')]
    public function tarifEditer(
        Niveau $niveau,
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        TarifRepository $tarifRepo,
        EntityManagerInterface $em,
    ): Response {
        $anneeActive = $anneeRepo->findActive() ?? throw $this->createNotFoundException('Aucune année scolaire active.');

        $tarif = $tarifRepo->findOneByNiveauEtAnnee($niveau, $anneeActive);
        $nouveau = $tarif === null;
        if ($tarif === null) {
            $tarif = (new Tarif())->setNiveau($niveau)->setAnneeScolaire($anneeActive);
        }

        $form = $this->createForm(TarifType::class, $tarif);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($nouveau) {
                $em->persist($tarif);
            }
            $em->flush();
            $this->addFlash('success', 'Tarif de '.$niveau->getNomComplet().' enregistré.');
            return $this->redirectToRoute('caisse_economat_tarifs');
        }

        return $this->render('caisse/economat/tarif_form.html.twig', [
            'form'   => $form,
            'niveau' => $niveau,
        ]);
    }

    #[Route('', name: 'index')]
    public function index(
        Request $request,
        NiveauRepository $niveauRepo,
        InscriptionRepository $inscriptionRepo,
        AnneeScolaireRepository $anneeRepo,
        SoldeCalculator $soldeCalculator,
    ): Response {
        $niveaux     = $niveauRepo->findBy([], ['ordre' => 'ASC']);
        $anneeActive = $anneeRepo->findActive();

        $niveauId = $request->query->getInt('niveau', 0);
        $niveau   = $niveauId > 0 ? $niveauRepo->find($niveauId) : null;

        $lignes = [];
        if ($niveau !== null && $anneeActive !== null) {
            $inscriptions = array_merge(
                $inscriptionRepo->findActivesByNiveauEtAnnee($niveau, $anneeActive),
                $inscriptionRepo->findEnCoursSansClasseByNiveau($niveau),
            );
            usort($inscriptions, fn ($a, $b) => $a->getEleve()->getNomComplet() <=> $b->getEleve()->getNomComplet());

            foreach ($inscriptions as $inscription) {
                $lignes[] = [
                    'inscription' => $inscription,
                    'solde'       => $soldeCalculator->calculer($inscription->getEleve(), $niveau, $anneeActive),
                ];
            }
        }

        return $this->render('caisse/economat/index.html.twig', [
            'niveaux'     => $niveaux,
            'niveau'      => $niveau,
            'anneeActive' => $anneeActive,
            'lignes'      => $lignes,
        ]);
    }

    #[Route('/eleves/{id}', name: 'historique_eleve')]
    public function historiqueEleve(
        Eleve $eleve,
        AnneeScolaireRepository $anneeRepo,
        RecuRepository $recuRepo,
        SoldeCalculator $soldeCalculator,
    ): Response {
        $inscription = $eleve->getInscriptionEnCours();
        $anneeActive = $anneeRepo->findActive();

        $solde = null;
        if ($inscription !== null && $anneeActive !== null) {
            $solde = $soldeCalculator->calculer($eleve, $inscription->getNiveau(), $anneeActive);
        }

        $recusParAnnee = [];
        foreach ($recuRepo->findAnneesAvecRecuPourEleve($eleve) as $annee) {
            $recusParAnnee[] = [
                'annee' => $annee,
                'recus' => $recuRepo->findByEleveEtAnnee($eleve, $annee),
            ];
        }

        return $this->render('caisse/economat/historique_eleve.html.twig', [
            'eleve'         => $eleve,
            'inscription'   => $inscription,
            'anneeActive'   => $anneeActive,
            'solde'         => $solde,
            'recusParAnnee' => $recusParAnnee,
        ]);
    }

    #[Route('/eleves/{id}/nouveau-recu', name: 'nouveau_recu')]
    public function nouveauRecu(
        Eleve $eleve,
        Request $request,
        AnneeScolaireRepository $anneeRepo,
        NumeroRecuGenerator $numeroGenerator,
        EntityManagerInterface $em,
    ): Response {
        $inscription = $eleve->getInscriptionEnCours();
        if ($inscription === null) {
            $this->addFlash('error', $eleve->getNomComplet().' n\'a aucune inscription en cours — impossible d\'émettre un reçu.');
            return $this->redirectToRoute('caisse_economat_index');
        }

        $anneeActive = $anneeRepo->findActive() ?? throw $this->createNotFoundException('Aucune année scolaire active.');

        $recu = new Recu();
        $recu->setAnneeScolaire($anneeActive);
        $recu->addLigne(new LigneRecu());

        $form = $this->createForm(RecuType::class, $recu);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $recu->setEleve($eleve);
            $recu->setInscription($inscription);
            $recu->setDateEmission(new \DateTimeImmutable());
            $recu->setNumero($numeroGenerator->generer());

            $em->persist($recu);
            $em->flush();

            $this->addFlash('success', 'Reçu n°'.$recu->getNumero().' enregistré.');
            return $this->redirectToRoute('caisse_economat_historique_eleve', ['id' => $eleve->getId()]);
        }

        return $this->render('caisse/economat/nouveau_recu.html.twig', [
            'form'        => $form,
            'eleve'       => $eleve,
            'inscription' => $inscription,
        ]);
    }

    #[Route('/recus/{id}/pdf', name: 'recu_pdf')]
    public function recuPdf(
        Recu $recu,
        Request $request,
        EtablissementRepository $etablissementRepo,
        SoldeCalculator $soldeCalculator,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $solde = $soldeCalculator->calculer($recu->getEleve(), $recu->getInscription()->getNiveau(), $recu->getAnneeScolaire());

        $html = $this->renderView('caisse/economat/pdf/recu.html.twig', [
            'recu'          => $recu,
            'solde'         => $solde,
            'avecEntete'    => $request->query->getBoolean('entete_college', true),
            'etablissement' => $etablissementRepo->getOuCreer(),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'recu-'.$recu->getNumero().'-'.$recu->getEleve()->getNomComplet(),
        )->lower().'.pdf';

        // Format ticket de caisse étroit (80mm, comme une imprimante à reçus) plutôt qu'une
        // pleine page A4 — voir EmploiDuTempsPdfExporter::exporter() pour le paramètre $paper.
        // Hauteur calculée sur le nombre de lignes pour éviter un ticket majoritairement blanc.
        $papier = [0, 0, self::LARGEUR_TICKET_PT, max(400, 340 + \count($recu->getLignes()) * 14)];

        return $this->reponsePdf($exporter->exporter($html, 'portrait', $papier), $nomFichier);
    }

    /** Largeur ticket 80mm, comme une imprimante à reçus. */
    private const LARGEUR_TICKET_PT = 226.77;

    #[Route('/recus/{id}/supprimer', name: 'recu_supprimer', methods: ['POST'])]
    public function recuSupprimer(Recu $recu, Request $request, EntityManagerInterface $em): Response
    {
        $eleveId = $recu->getEleve()->getId();

        if (!$this->isCsrfTokenValid('supprimer_recu_'.$recu->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('caisse_economat_historique_eleve', ['id' => $eleveId]);
        }

        $em->remove($recu);
        $em->flush();

        $this->addFlash('success', 'Reçu supprimé.');
        return $this->redirectToRoute('caisse_economat_historique_eleve', ['id' => $eleveId]);
    }

    /** "inline" : le PDF s'ouvre dans le navigateur (aperçu avant impression) — même convention que BulletinController. */
    private function reponsePdf(string $contenu, string $nomFichier): Response
    {
        return new Response($contenu, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nomFichier.'"',
        ]);
    }
}
