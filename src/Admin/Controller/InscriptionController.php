<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\NiveauRepository;
use App\Student\Dto\NouvelleInscriptionInput;
use App\Student\Entity\Eleve;
use App\Student\Entity\Inscription;
use App\Student\Form\AffectationClasseType;
use App\Student\Form\ClotureInscriptionType;
use App\Student\Form\InscriptionType;
use App\Student\Form\NouvelleInscriptionType;
use App\Student\Repository\EleveRepository;
use App\Student\Repository\InscriptionRepository;
use App\Student\Service\MatriculeGenerator;
use App\Student\Service\PhotoIdentiteProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_inscription_')]
class InscriptionController extends AbstractController
{
    /** Première inscription : crée l'élève et l'affecte à un niveau (la classe se choisit ensuite). */
    #[Route('/inscriptions/nouvelle', name: 'nouvelle')]
    public function nouvelle(
        Request $request,
        EntityManagerInterface $em,
        MatriculeGenerator $matriculeGenerator,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        PhotoIdentiteProcessor $photoProcessor,
    ): Response {
        $data = new NouvelleInscriptionInput();
        $data->dateInscription = new \DateTimeImmutable();

        $form = $this->createForm(NouvelleInscriptionType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $eleve = $data->eleve;
            $eleve->setMatricule($matriculeGenerator->generer());
            EleveController::traiterPhoto($form->get('eleve')->get('photo')->getData(), $eleve, $projectDir, $photoProcessor);

            $inscription = new Inscription();
            $inscription->setEleve($eleve);
            $inscription->setNiveau($data->niveau);
            $inscription->setDateInscription($data->dateInscription);
            $inscription->setRedoublant($data->redoublant);

            $em->persist($eleve);
            $em->persist($inscription);
            $em->flush();

            $this->addFlash('success', 'Élève inscrit — reste à l\'affecter à une classe.');
            return $this->redirectToRoute('admin_eleve_show', ['id' => $eleve->getId()]);
        }

        return $this->render('admin/eleve/nouvelle_inscription.html.twig', ['form' => $form]);
    }

    /** Ré-inscription d'un élève déjà existant sans inscription en cours (transfert, redoublement après clôture). */
    #[Route('/eleves/{id}/inscrire', name: 'new')]
    public function new(Request $request, Eleve $eleve, EntityManagerInterface $em): Response
    {
        if ($eleve->getInscriptionEnCours() !== null) {
            $this->addFlash('error', 'Cet élève a déjà une inscription en cours : clôturez-la avant d\'en créer une nouvelle.');
            return $this->redirectToRoute('admin_eleve_show', ['id' => $eleve->getId()]);
        }

        $inscription = new Inscription();
        $inscription->setEleve($eleve);
        $inscription->setDateInscription(new \DateTimeImmutable());

        $form = $this->createForm(InscriptionType::class, $inscription);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($inscription);
            $em->flush();
            $this->addFlash('success', 'Élève inscrit — reste à l\'affecter à une classe.');
            return $this->redirectToRoute('admin_eleve_show', ['id' => $eleve->getId()]);
        }

        return $this->render('admin/eleve/inscrire.html.twig', ['form' => $form, 'eleve' => $eleve]);
    }

    /** Affecte une classe à une inscription en cours qui n'en a pas encore (élève seul). */
    #[Route('/inscriptions/{id}/affecter-classe', name: 'affecter_classe')]
    public function affecterClasse(Request $request, Inscription $inscription, EntityManagerInterface $em): Response
    {
        if (!$inscription->isEnCours() || $inscription->getClasse() !== null) {
            return $this->redirectToRoute('admin_eleve_show', ['id' => $inscription->getEleve()->getId()]);
        }

        $form = $this->createForm(AffectationClasseType::class, $inscription, ['niveau' => $inscription->getNiveau()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Élève affecté à sa classe.');
            return $this->redirectToRoute('admin_eleve_show', ['id' => $inscription->getEleve()->getId()]);
        }

        return $this->render('admin/eleve/affecter_classe.html.twig', ['form' => $form, 'inscription' => $inscription]);
    }

    /**
     * Écran de rentrée : affecter d'un coup plusieurs élèves inscrits à un niveau mais
     * sans classe (élève 1 → 6e A, élève 2 → 6e B…). Servira aussi de base à une future
     * campagne de passage automatique de niveau.
     */
    #[Route('/inscriptions/affectation-en-lot', name: 'affectation_lot')]
    public function affectationLot(
        Request $request,
        EleveRepository $eleveRepo,
        NiveauRepository $niveauRepo,
        ClasseRepository $classeRepo,
        InscriptionRepository $inscriptionRepo,
        EntityManagerInterface $em,
    ): Response {
        $niveauId = $request->query->getInt('niveau') ?: null;
        $niveau   = $niveauId !== null ? $niveauRepo->find($niveauId) : null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('affectation_lot', $request->getPayload()->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
                return $this->redirectToRoute('admin_inscription_affectation_lot', ['niveau' => $niveauId]);
            }

            if ($niveau === null) {
                $this->addFlash('error', 'Choisissez un niveau avant de valider.');
                return $this->redirectToRoute('admin_inscription_affectation_lot');
            }

            $classesDuNiveau = [];
            foreach ($classeRepo->findByAnneeScolaireActive() as $classe) {
                if ($classe->getNiveau() === $niveau) {
                    $classesDuNiveau[$classe->getId()] = $classe;
                }
            }

            $nbAffectes = 0;
            foreach ($request->getPayload()->all('eleves') as $eleveId => $classeId) {
                if ($classeId === '' || $classeId === null) {
                    continue;
                }

                $eleve       = $eleveRepo->find((int) $eleveId);
                $classe      = $classesDuNiveau[(int) $classeId] ?? null;
                $inscription = $eleve?->getInscriptionEnCours();

                if ($classe === null || $inscription === null || $inscription->getClasse() !== null) {
                    continue;
                }

                $inscription->setClasse($classe);
                $nbAffectes++;
            }
            $em->flush();

            $this->addFlash(
                $nbAffectes > 0 ? 'success' : 'error',
                $nbAffectes > 0 ? "$nbAffectes élève(s) affecté(s)." : 'Aucun élève affecté — choisissez une classe pour au moins un élève.',
            );
            return $this->redirectToRoute('admin_inscription_affectation_lot', ['niveau' => $niveauId]);
        }

        $classesDuNiveau = [];
        $eleves          = [];
        if ($niveau !== null) {
            foreach ($classeRepo->findByAnneeScolaireActive() as $classe) {
                if ($classe->getNiveau() === $niveau) {
                    $classesDuNiveau[] = $classe;
                }
            }
            $eleves = array_map(
                fn(Inscription $i) => $i->getEleve(),
                $inscriptionRepo->findEnCoursSansClasseByNiveau($niveau),
            );
        }

        return $this->render('admin/eleve/affectation_lot.html.twig', [
            'niveaux'         => $niveauRepo->findBy([], ['ordre' => 'ASC']),
            'niveauChoisi'    => $niveau,
            'classesDuNiveau' => $classesDuNiveau,
            'eleves'          => $eleves,
        ]);
    }

    #[Route('/inscriptions/{id}/cloturer', name: 'cloturer')]
    public function cloturer(Request $request, Inscription $inscription, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ClotureInscriptionType::class, $inscription);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Inscription clôturée.');
            return $this->redirectToRoute('admin_eleve_show', ['id' => $inscription->getEleve()->getId()]);
        }

        return $this->render('admin/eleve/cloturer.html.twig', ['form' => $form, 'inscription' => $inscription]);
    }
}
