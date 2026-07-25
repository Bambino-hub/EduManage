<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Academic\Repository\MatiereRepository;
use App\Academic\Repository\NiveauRepository;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Form\ExamenBlancType;
use App\ExamenBlanc\Repository\ExamenBlancRepository;
use App\ExamenBlanc\Repository\ExamenBlancReleveRepository;
use App\ExamenBlanc\Service\ExamenBlancCompletudeChecker;
use App\ExamenBlanc\Service\ExamenBlancMatieresResolver;
use App\ExamenBlanc\Service\ExamenBlancReleveGenerator;
use App\Scheduling\Service\Export\EmploiDuTempsPdfExporter;
use App\Shared\Repository\EtablissementRepository;
use App\Student\Repository\InscriptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Sessions d'examen blanc : CRUD, grille de complétude matière × niveau (voir
 * ExamenBlancCompletudeChecker) et génération/impression des relevés (voir
 * ExamenBlancReleveGenerator). La saisie des notes elle-même vit dans
 * ExamenBlancNoteController (une fiche niveau/matière à la fois, toutes classes du niveau
 * fusionnées) et ExamenBlancNoteImportController (OCR).
 */
#[Route('/admin/examens-blancs', name: 'admin_examen_blanc_')]
class ExamenBlancController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(AnneeScolaireRepository $anneeRepo, ExamenBlancRepository $examenBlancRepo): Response
    {
        $annee = $anneeRepo->findActive();

        return $this->render('admin/examen_blanc/index.html.twig', [
            'examensBlancs' => $annee ? $examenBlancRepo->findByAnneeScolaire($annee) : [],
            'annee'         => $annee,
        ]);
    }

    #[Route('/nouveau', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, AnneeScolaireRepository $anneeRepo, MatiereRepository $matiereRepo, MatiereNiveauRepository $matiereNiveauRepo): Response
    {
        $annee = $anneeRepo->findActive();
        if ($annee === null) {
            $this->addFlash('error', 'Aucune année scolaire active — activez-en une avant de créer un examen blanc.');
            return $this->redirectToRoute('admin_examen_blanc_index');
        }

        $examenBlanc = new ExamenBlanc();
        $examenBlanc->setAnneeScolaire($annee);
        // Présélection : toutes les matières évaluables par défaut, l'admin décoche celles
        // qui ne sont pas testées à cet examen plutôt que de partir d'une liste vide.
        $examenBlanc->setMatieres(new ArrayCollection($matiereRepo->findEvaluablesParDefaut()));

        $form = $this->createForm(ExamenBlancType::class, $examenBlanc);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($examenBlanc);
            $em->flush();
            $this->addFlash('success', 'Examen blanc créé.');
            return $this->redirectToRoute('admin_examen_blanc_show', ['id' => $examenBlanc->getId()]);
        }

        return $this->render('admin/examen_blanc/form.html.twig', [
            'examenBlanc'        => $examenBlanc,
            'form'               => $form,
            'matiereNiveauxIds'  => self::matiereNiveauxIds($matiereNiveauRepo),
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit')]
    public function edit(ExamenBlanc $examenBlanc, Request $request, EntityManagerInterface $em, MatiereNiveauRepository $matiereNiveauRepo): Response
    {
        $form = $this->createForm(ExamenBlancType::class, $examenBlanc);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Examen blanc modifié.');
            return $this->redirectToRoute('admin_examen_blanc_show', ['id' => $examenBlanc->getId()]);
        }

        return $this->render('admin/examen_blanc/form.html.twig', [
            'examenBlanc'        => $examenBlanc,
            'form'               => $form,
            'matiereNiveauxIds'  => self::matiereNiveauxIds($matiereNiveauRepo),
        ]);
    }

    /**
     * Matière -> niveaux où elle est réellement enseignée (heuresParSemaine > 0), utilisé par
     * le formulaire pour ne proposer, une fois les niveaux cochés, que les matières qui leur
     * correspondent (voir assets/controllers/examen_blanc_matiere_filter_controller.js) — sans
     * ça, une matière propre au lycée (cycle 2) apparaissait aussi pour un examen ne couvrant
     * que le collège (cycle 1), et inversement.
     *
     * @return array<int, int[]> indexé par Matiere::getId()
     */
    private static function matiereNiveauxIds(MatiereNiveauRepository $matiereNiveauRepo): array
    {
        $niveauxIdsParMatiereId = [];
        foreach ($matiereNiveauRepo->findToutesEnseignees() as $matiereNiveau) {
            $niveauxIdsParMatiereId[$matiereNiveau->getMatiere()->getId()][] = $matiereNiveau->getNiveau()->getId();
        }

        return $niveauxIdsParMatiereId;
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(ExamenBlanc $examenBlanc, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$examenBlanc->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($examenBlanc);
            $em->flush();
            $this->addFlash('success', 'Examen blanc supprimé (notes et relevés associés emportés).');
        }

        return $this->redirectToRoute('admin_examen_blanc_index');
    }

    /**
     * Page d'aperçu de l'examen blanc : un niveau = un examen à traiter séparément (bouton
     * "Traiter" vers {@see niveauShow}) plutôt que d'empiler les grilles et relevés de tous
     * les niveaux sur une seule page comme avant.
     */
    #[Route('/{id}', name: 'show')]
    public function show(ExamenBlanc $examenBlanc, ExamenBlancCompletudeChecker $checker, ExamenBlancReleveRepository $releveRepo): Response
    {
        $rapport = $checker->verifier($examenBlanc);

        $statsParNiveauId = [];
        foreach ($rapport->niveaux as $niveau) {
            $attendues   = 0;
            $renseignees = 0;
            foreach ($rapport->matieres as $ligne) {
                $statut = $ligne->statutParNiveauId[$niveau->getId()] ?? null;
                if ($statut !== null) {
                    $attendues++;
                    if ($statut->renseignee) {
                        $renseignees++;
                    }
                }
            }

            $statsParNiveauId[$niveau->getId()] = [
                'matieresAttendues'   => $attendues,
                'matieresRenseignees' => $renseignees,
                'releves'             => count($releveRepo->findByExamenBlancEtNiveau($examenBlanc, $niveau)),
            ];
        }

        return $this->render('admin/examen_blanc/show.html.twig', [
            'examenBlanc'       => $examenBlanc,
            'niveaux'           => $rapport->niveaux,
            'statsParNiveauId'  => $statsParNiveauId,
        ]);
    }

    /** Un niveau à la fois : grille des fiches à remplir et relevés générés, pour "traiter" cet examen indépendamment des autres niveaux de la session. */
    #[Route('/{id}/niveau/{niveauId}', name: 'niveau_show', requirements: ['niveauId' => '\d+'])]
    public function niveauShow(
        ExamenBlanc $examenBlanc,
        int $niveauId,
        NiveauRepository $niveauRepo,
        ExamenBlancCompletudeChecker $checker,
        ExamenBlancReleveRepository $releveRepo,
    ): Response {
        $niveau = $niveauRepo->find($niveauId) ?? throw $this->createNotFoundException();
        if (!$examenBlanc->getNiveaux()->contains($niveau)) {
            throw $this->createNotFoundException();
        }

        $rapport = $checker->verifier($examenBlanc);
        $lignes  = array_values(array_filter(
            $rapport->matieres,
            static fn ($ligne) => isset($ligne->statutParNiveauId[$niveau->getId()]),
        ));

        return $this->render('admin/examen_blanc/niveau_show.html.twig', [
            'examenBlanc' => $examenBlanc,
            'niveau'      => $niveau,
            'lignes'      => $lignes,
            'releves'     => $releveRepo->findByExamenBlancEtNiveau($examenBlanc, $niveau),
        ]);
    }

    #[Route('/{id}/niveau/{niveauId}/generer', name: 'generer', methods: ['POST'])]
    public function generer(
        ExamenBlanc $examenBlanc,
        int $niveauId,
        Request $request,
        NiveauRepository $niveauRepo,
        ExamenBlancReleveGenerator $generator,
    ): Response {
        $niveau = $niveauRepo->find($niveauId) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('generer_releves_'.$examenBlanc->getId().'_'.$niveauId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_examen_blanc_niveau_show', ['id' => $examenBlanc->getId(), 'niveauId' => $niveauId]);
        }

        $releves = $generator->genererPourNiveau($examenBlanc, $niveau);

        if ($releves === []) {
            $this->addFlash('error', 'Tous les relevés de ce niveau sont déjà générés.');
        } else {
            $this->addFlash('success', count($releves).' relevé(s) généré(s) pour '.$niveau->getNomComplet().'.');
        }

        return $this->redirectToRoute('admin_examen_blanc_niveau_show', ['id' => $examenBlanc->getId(), 'niveauId' => $niveauId]);
    }

    /** Supprime tous les relevés générés d'un niveau — permet de les régénérer après correction des notes. */
    #[Route('/{id}/niveau/{niveauId}/releves/supprimer', name: 'releves_delete', methods: ['POST'])]
    public function relevesDelete(
        ExamenBlanc $examenBlanc,
        int $niveauId,
        Request $request,
        NiveauRepository $niveauRepo,
        ExamenBlancReleveRepository $releveRepo,
        EntityManagerInterface $em,
    ): Response {
        $niveau = $niveauRepo->find($niveauId) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('releves_delete_'.$examenBlanc->getId().'_'.$niveauId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_examen_blanc_niveau_show', ['id' => $examenBlanc->getId(), 'niveauId' => $niveauId]);
        }

        $releves = $releveRepo->findByExamenBlancEtNiveau($examenBlanc, $niveau);
        foreach ($releves as $releve) {
            $em->remove($releve);
        }
        $em->flush();

        $this->addFlash('success', count($releves).' relevé(s) supprimé(s) pour '.$niveau->getNomComplet().'.');

        return $this->redirectToRoute('admin_examen_blanc_niveau_show', ['id' => $examenBlanc->getId(), 'niveauId' => $niveauId]);
    }

    /** Supprime un relevé individuel — permet de le régénérer après correction des notes de cet élève. */
    #[Route('/releve/{releveId}/supprimer', name: 'releve_delete', methods: ['POST'])]
    public function releveDelete(int $releveId, Request $request, ExamenBlancReleveRepository $releveRepo, EntityManagerInterface $em): Response
    {
        $releve      = $releveRepo->find($releveId) ?? throw $this->createNotFoundException();
        $examenBlanc = $releve->getExamenBlanc();
        $niveau      = $releve->getNiveau();

        if ($this->isCsrfTokenValid('releve_delete_'.$releveId, $request->getPayload()->getString('_token'))) {
            $em->remove($releve);
            $em->flush();
            $this->addFlash('success', 'Relevé de '.$releve->getEleve()->getNomComplet().' supprimé.');
        }

        return $this->redirectToRoute('admin_examen_blanc_niveau_show', ['id' => $examenBlanc->getId(), 'niveauId' => $niveau->getId()]);
    }

    /** Visualisation à l'écran (PDF affiché inline, pas téléchargé) avant impression — tous les relevés d'un niveau. */
    #[Route('/{id}/niveau/{niveauId}/releves/pdf', name: 'releves_pdf')]
    public function relevesPdf(
        ExamenBlanc $examenBlanc,
        int $niveauId,
        Request $request,
        NiveauRepository $niveauRepo,
        ExamenBlancReleveRepository $releveRepo,
        EtablissementRepository $etablissementRepo,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $niveau = $niveauRepo->find($niveauId) ?? throw $this->createNotFoundException();

        $releves = $releveRepo->findByExamenBlancEtNiveau($examenBlanc, $niveau);
        if ($releves === []) {
            $this->addFlash('error', 'Aucun relevé généré pour ce niveau — générez-les d\'abord.');
            return $this->redirectToRoute('admin_examen_blanc_niveau_show', ['id' => $examenBlanc->getId(), 'niveauId' => $niveauId]);
        }

        $html = $this->renderView('admin/examen_blanc/pdf/releve.html.twig', [
            'releves'       => $releves,
            'avecEntete'    => $request->query->getBoolean('entete_college', true),
            'etablissement' => $etablissementRepo->getOuCreer(),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'releves-'.$examenBlanc->getLibelle().'-'.$niveau->getNomComplet(),
        )->lower().'.pdf';

        return new Response($exporter->exporter($html, 'portrait'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nomFichier.'"',
        ]);
    }

    /** Fiches vierges de toutes les matières évaluées d'un niveau, groupées dans un même PDF — un saut de page entre chaque, comme grading/pdf/fiches_notes_lot.html.twig. */
    #[Route('/{id}/niveau/{niveauId}/fiches/pdf', name: 'fiches_pdf')]
    public function fichesPdf(
        ExamenBlanc $examenBlanc,
        int $niveauId,
        Request $request,
        NiveauRepository $niveauRepo,
        InscriptionRepository $inscriptionRepo,
        MatiereNiveauRepository $matiereNiveauRepo,
        ExamenBlancMatieresResolver $matieresResolver,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $niveau = $niveauRepo->find($niveauId) ?? throw $this->createNotFoundException();
        $inscriptions = $inscriptionRepo->findActivesByNiveauEtAnnee($niveau, $examenBlanc->getAnneeScolaire());

        $fiches = [];
        foreach ($matieresResolver->resoudre($examenBlanc, $niveau) as $matiere) {
            $coefficient = $matiereNiveauRepo->findOneByMatiereEtNiveau($matiere, $niveau)?->getCoefficient() ?? '1.00';
            $fiches[] = ['matiere' => $matiere, 'coefficient' => $coefficient];
        }

        if ($fiches === [] || $inscriptions === []) {
            $this->addFlash('error', 'Aucune matière évaluée ou aucun élève inscrit pour ce niveau.');
            return $this->redirectToRoute('admin_examen_blanc_niveau_show', ['id' => $examenBlanc->getId(), 'niveauId' => $niveauId]);
        }

        $html = $this->renderView('admin/examen_blanc/pdf/fiches_lot.html.twig', [
            'examenBlanc'  => $examenBlanc,
            'niveau'       => $niveau,
            'fiches'       => $fiches,
            'inscriptions' => $inscriptions,
            'avecEntete'   => $request->query->getBoolean('entete_college', false),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'fiches-'.$examenBlanc->getLibelle().'-'.$niveau->getNomComplet(),
        )->lower().'.pdf';

        return new Response($exporter->exporter($html, 'landscape'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nomFichier.'"',
        ]);
    }

    /** Visualisation à l'écran (PDF affiché inline, pas téléchargé) avant impression — relevé d'un seul élève. */
    #[Route('/releve/{releveId}/pdf', name: 'releve_pdf')]
    public function relevePdf(
        int $releveId,
        Request $request,
        ExamenBlancReleveRepository $releveRepo,
        EtablissementRepository $etablissementRepo,
        EmploiDuTempsPdfExporter $exporter,
    ): Response {
        $releve = $releveRepo->find($releveId) ?? throw $this->createNotFoundException();

        $html = $this->renderView('admin/examen_blanc/pdf/releve.html.twig', [
            'releves'       => [$releve],
            'avecEntete'    => $request->query->getBoolean('entete_college', true),
            'etablissement' => $etablissementRepo->getOuCreer(),
        ]);

        $nomFichier = (new AsciiSlugger())->slug(
            'releve-'.$releve->getEleve()->getMatricule().'-'.$releve->getExamenBlanc()->getLibelle(),
        )->lower().'.pdf';

        return new Response($exporter->exporter($html, 'portrait'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nomFichier.'"',
        ]);
    }
}
