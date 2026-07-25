<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\ClasseRepository;
use App\Grading\Entity\Bulletin;
use App\Grading\Enum\MentionConseil;
use App\Grading\Form\ComplementBulletinType;
use App\Grading\Repository\BulletinRepository;
use App\Grading\Repository\TrimestreRepository;
use App\Grading\Service\BulletinGenerator;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use App\Shared\Repository\EtablissementRepository;
use App\Student\Repository\EleveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Génération, suppression et export PDF des bulletins — déclenché depuis /admin/moyennes
 * (classe + trimestre déjà choisis là-bas). Snapshot figé : voir Grading\Service\BulletinGenerator.
 */
#[Route('/admin/bulletins', name: 'admin_bulletin_')]
class BulletinController extends AbstractController
{
    /** Génère les bulletins manquants de la classe — ceux déjà générés (individuellement ou non) ne sont pas touchés. */
    #[Route('/generer/{classeId}/{trimestreId}', name: 'generer', methods: ['POST'])]
    public function generer(
        Request $request,
        int $classeId,
        int $trimestreId,
        ClasseRepository $classeRepo,
        TrimestreRepository $trimestreRepo,
        BulletinGenerator $generator,
    ): Response {
        $classe    = $classeRepo->find($classeId) ?? throw $this->createNotFoundException();
        $trimestre = $trimestreRepo->find($trimestreId) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('generer_bulletins_'.$classeId.'_'.$trimestreId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_moyennes_index', ['classe' => $classeId, 'trimestre' => $trimestreId]);
        }

        $bulletinsCrees = $generator->genererPourClasse($classe, $trimestre);

        if ($bulletinsCrees === []) {
            $this->addFlash('error', 'Tous les bulletins de cette classe sont déjà générés pour ce trimestre.');
        } else {
            $this->addFlash('success', count($bulletinsCrees).' bulletin(s) généré(s).');
        }

        return $this->redirectToRoute('admin_moyennes_index', ['classe' => $classeId, 'trimestre' => $trimestreId]);
    }

    #[Route('/generer-eleve/{classeId}/{trimestreId}/{eleveId}', name: 'generer_eleve', methods: ['POST'])]
    public function genererPourEleve(
        Request $request,
        int $classeId,
        int $trimestreId,
        int $eleveId,
        ClasseRepository $classeRepo,
        TrimestreRepository $trimestreRepo,
        EleveRepository $eleveRepo,
        BulletinRepository $bulletinRepo,
        BulletinGenerator $generator,
    ): Response {
        $classe    = $classeRepo->find($classeId) ?? throw $this->createNotFoundException();
        $trimestre = $trimestreRepo->find($trimestreId) ?? throw $this->createNotFoundException();
        $eleve     = $eleveRepo->find($eleveId) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('generer_bulletin_eleve_'.$classeId.'_'.$trimestreId.'_'.$eleveId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_moyennes_index', ['classe' => $classeId, 'trimestre' => $trimestreId]);
        }

        if ($bulletinRepo->findOneBy(['eleve' => $eleve, 'trimestre' => $trimestre]) !== null) {
            $this->addFlash('error', 'Un bulletin existe déjà pour cet élève ce trimestre — supprimez-le avant d\'en générer un nouveau.');
            return $this->redirectToRoute('admin_moyennes_index', ['classe' => $classeId, 'trimestre' => $trimestreId]);
        }

        $generator->genererPourEleve($classe, $trimestre, $eleve);
        $this->addFlash('success', 'Bulletin de '.$eleve->getNomComplet().' généré.');

        return $this->redirectToRoute('admin_moyennes_index', ['classe' => $classeId, 'trimestre' => $trimestreId]);
    }

    #[Route('/supprimer/{classeId}/{trimestreId}', name: 'supprimer', methods: ['POST'])]
    public function supprimer(
        Request $request,
        int $classeId,
        int $trimestreId,
        ClasseRepository $classeRepo,
        TrimestreRepository $trimestreRepo,
        BulletinRepository $bulletinRepo,
        EntityManagerInterface $em,
    ): Response {
        $classe    = $classeRepo->find($classeId) ?? throw $this->createNotFoundException();
        $trimestre = $trimestreRepo->find($trimestreId) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('supprimer_bulletins_'.$classeId.'_'.$trimestreId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_moyennes_index', ['classe' => $classeId, 'trimestre' => $trimestreId]);
        }

        foreach ($bulletinRepo->findByClasseEtTrimestre($classe, $trimestre) as $bulletin) {
            $em->remove($bulletin);
        }
        $em->flush();

        $this->addFlash('success', 'Bulletins supprimés — vous pouvez les régénérer.');

        return $this->redirectToRoute('admin_moyennes_index', ['classe' => $classeId, 'trimestre' => $trimestreId]);
    }

    /** Supprime le bulletin d'un seul élève (contrairement à `supprimer`, qui vide toute la classe) — permet de le régénérer seul après une correction. */
    #[Route('/{id}/supprimer-eleve', name: 'supprimer_eleve', methods: ['POST'])]
    public function supprimerPourEleve(Bulletin $bulletin, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_bulletin_eleve_'.$bulletin->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
        } else {
            $classeId    = $bulletin->getClasse()->getId();
            $trimestreId = $bulletin->getTrimestre()->getId();
            $em->remove($bulletin);
            $em->flush();
            $this->addFlash('success', 'Bulletin supprimé — vous pouvez le régénérer.');

            return $this->redirectToRoute('admin_moyennes_index', ['classe' => $classeId, 'trimestre' => $trimestreId]);
        }

        return $this->redirectToRoute('admin_moyennes_index', [
            'classe'    => $bulletin->getClasse()->getId(),
            'trimestre' => $bulletin->getTrimestre()->getId(),
        ]);
    }

    #[Route('/{id}/pdf', name: 'pdf')]
    public function pdf(
        Bulletin $bulletin,
        Request $request,
        BulletinRepository $bulletinRepo,
        EtablissementRepository $etablissementRepo,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $html = $this->renderView('admin/bulletin/pdf/bulletin.html.twig', [
            'bulletins'               => [$bulletin],
            'historiqueParBulletinId' => [$bulletin->getId() => $bulletinRepo->findByEleveEtAnneeScolaire(
                $bulletin->getEleve(),
                $bulletin->getClasse()->getAnneeScolaire(),
            )],
            'mentionsDisponibles'     => MentionConseil::cases(),
            'avecEntete'              => $request->query->getBoolean('entete_college', true),
            'etablissement'           => $etablissementRepo->getOuCreer(),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'bulletin-'.$bulletin->getEleve()->getNomComplet().'-'.$bulletin->getTrimestre()->getLibelle(),
        )->lower().'.pdf';

        return $this->reponsePdf($exporter->exporter($html, 'portrait'), $nomFichier);
    }

    #[Route('/classe/{classeId}/{trimestreId}/pdf', name: 'classe_pdf')]
    public function pdfClasse(
        int $classeId,
        int $trimestreId,
        Request $request,
        ClasseRepository $classeRepo,
        TrimestreRepository $trimestreRepo,
        BulletinRepository $bulletinRepo,
        EtablissementRepository $etablissementRepo,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $classe    = $classeRepo->find($classeId) ?? throw $this->createNotFoundException();
        $trimestre = $trimestreRepo->find($trimestreId) ?? throw $this->createNotFoundException();

        $bulletins               = $bulletinRepo->findByClasseEtTrimestre($classe, $trimestre);
        $historiqueParBulletinId = [];
        foreach ($bulletins as $bulletin) {
            $historiqueParBulletinId[$bulletin->getId()] = $bulletinRepo->findByEleveEtAnneeScolaire(
                $bulletin->getEleve(),
                $classe->getAnneeScolaire(),
            );
        }

        $html = $this->renderView('admin/bulletin/pdf/bulletin.html.twig', [
            'bulletins'               => $bulletins,
            'historiqueParBulletinId' => $historiqueParBulletinId,
            'mentionsDisponibles'     => MentionConseil::cases(),
            'avecEntete'              => $request->query->getBoolean('entete_college', true),
            'etablissement'           => $etablissementRepo->getOuCreer(),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'bulletins-'.$classe->getNom().'-'.$trimestre->getLibelle(),
        )->lower().'.pdf';

        return $this->reponsePdf($exporter->exporter($html, 'portrait'), $nomFichier);
    }

    /** Décision du conseil / mentions / appréciation du professeur principal — annotations ajoutées après coup, pas de verrou. */
    #[Route('/{id}/completer', name: 'completer')]
    public function completer(Bulletin $bulletin, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ComplementBulletinType::class, $bulletin);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Bulletin complété.');
            return $this->redirectToRoute('admin_moyennes_index', [
                'classe'    => $bulletin->getClasse()->getId(),
                'trimestre' => $bulletin->getTrimestre()->getId(),
            ]);
        }

        return $this->render('admin/bulletin/completer.html.twig', [
            'form'      => $form,
            'bulletin'  => $bulletin,
        ]);
    }

    /**
     * "inline" plutôt que "attachment" : le PDF s'ouvre dans le navigateur (aperçu avant
     * impression) au lieu de se télécharger directement — l'utilisateur imprime ou
     * enregistre depuis la visionneuse PDF du navigateur s'il le souhaite.
     */
    private function reponsePdf(string $contenu, string $nomFichier): Response
    {
        return new Response($contenu, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nomFichier.'"',
        ]);
    }
}
