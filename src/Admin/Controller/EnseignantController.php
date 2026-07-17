<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Exam\Repository\SurveillanceRepository;
use App\Security\Entity\Utilisateur;
use App\Security\Repository\UtilisateurRepository;
use App\Security\Service\MotDePasseGenerator;
use App\Staff\Entity\Enseignant;
use App\Staff\Enum\TypePersonnel;
use App\Staff\Form\EnseignantType;
use App\Staff\Repository\EnseignantRepository;
use App\Staff\Service\Export\EnseignantPdfExporter;
use App\Staff\Service\Export\EnseignantWordExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/enseignants', name: 'admin_enseignant_')]
class EnseignantController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(EnseignantRepository $repo): Response
    {
        return $this->render('admin/enseignant/index.html.twig', [
            'enseignants' => $repo->findHorsStagiaires(),
            'typePersonnel' => array_filter(TypePersonnel::cases(), fn(TypePersonnel $t) => $t !== TypePersonnel::STAGIAIRE),
        ]);
    }

    #[Route('/export/word', name: 'export_word')]
    public function exportWord(EnseignantRepository $repo, EnseignantWordExporter $exporter): Response
    {
        $contenu = $exporter->exporter($repo->findHorsStagiaires());

        return new Response($contenu, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="enseignants.docx"',
        ]);
    }

    #[Route('/export/pdf', name: 'export_pdf')]
    public function exportPdf(EnseignantRepository $repo, EnseignantPdfExporter $exporter): Response
    {
        $contenu = $exporter->exporter($repo->findHorsStagiaires());

        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="enseignants.pdf"',
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $enseignant = new Enseignant();
        $form       = $this->createForm(EnseignantType::class, $enseignant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($enseignant);
            $em->flush();
            $this->addFlash('success', 'Enseignant enregistré.');
            return $this->redirectToRoute('admin_enseignant_index');
        }

        return $this->render('admin/enseignant/form.html.twig', ['form' => $form, 'enseignant' => $enseignant]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Enseignant $enseignant, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(EnseignantType::class, $enseignant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Enseignant modifié.');
            return $this->redirectToRoute('admin_enseignant_index');
        }

        return $this->render('admin/enseignant/form.html.twig', ['form' => $form, 'enseignant' => $enseignant]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Enseignant $enseignant, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$enseignant->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($enseignant);
            $em->flush();
            $this->addFlash('success', 'Enseignant supprimé.');
        }
        return $this->redirectToRoute('admin_enseignant_index');
    }

    /**
     * Fiche complète d'un enseignant : toutes ses informations + son planning de surveillance
     * (nombre total de fois qu'il surveille). Route générique `/{id}` placée en DERNIER et
     * contrainte à un id numérique pour ne jamais intercepter `/new` ou `/export/*` (routes
     * statiques déclarées avant elle).
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(
        Enseignant $enseignant,
        SurveillanceRepository $surveillanceRepo,
        UtilisateurRepository $utilisateurRepo,
    ): Response {
        $surveillances = $surveillanceRepo->findByEnseignant((int) $enseignant->getId());

        // Nombre d'EXAMENS distincts, pas de lignes : un enseignant sur un RegroupementSurveillance
        // (2 classes, même salle) a 2 lignes pour un même examen — une seule vraie surveillance.
        $totalSurveillances = count(array_unique(array_map(
            static fn($s) => $s->getExamen()->getId(),
            $surveillances,
        )));

        return $this->render('admin/enseignant/show.html.twig', [
            'enseignant'         => $enseignant,
            'surveillances'      => $surveillances,
            'totalSurveillances' => $totalSurveillances,
            'utilisateur'        => $utilisateurRepo->findOneBy(['enseignant' => $enseignant]),
        ]);
    }

    /** Crée l'accès de connexion d'un enseignant (mot de passe temporaire, affiché une seule fois). */
    #[Route('/{id}/creer-acces', name: 'creer_acces', methods: ['POST'])]
    public function creerAcces(
        Request $request,
        Enseignant $enseignant,
        UtilisateurRepository $utilisateurRepo,
        MotDePasseGenerator $motDePasseGenerator,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('creer_acces'.$enseignant->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_enseignant_show', ['id' => $enseignant->getId()]);
        }

        if (!$enseignant->getEmail()) {
            $this->addFlash('error', 'Cet enseignant n\'a pas d\'adresse e-mail : impossible de créer un accès.');
            return $this->redirectToRoute('admin_enseignant_show', ['id' => $enseignant->getId()]);
        }

        if ($utilisateurRepo->findOneBy(['enseignant' => $enseignant])) {
            $this->addFlash('error', 'Cet enseignant a déjà un accès.');
            return $this->redirectToRoute('admin_enseignant_show', ['id' => $enseignant->getId()]);
        }

        $motDePasse = $motDePasseGenerator->generer();

        $utilisateur = new Utilisateur();
        $utilisateur->setEmail($enseignant->getEmail());
        $utilisateur->setRoles(['ROLE_ENSEIGNANT']);
        $utilisateur->setEnseignant($enseignant);
        $utilisateur->setDoitChangerMotDePasse(true);
        $utilisateur->setPassword($passwordHasher->hashPassword($utilisateur, $motDePasse));

        $em->persist($utilisateur);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Accès créé pour %s. Mot de passe temporaire : %s — transmettez-le en main propre, il ne sera plus affiché.',
            $enseignant->getNomComplet(),
            $motDePasse,
        ));

        return $this->redirectToRoute('admin_enseignant_show', ['id' => $enseignant->getId()]);
    }

    /** Régénère un mot de passe temporaire (accès perdu ou oublié — pas de "mot de passe oublié" en libre-service). */
    #[Route('/{id}/reinitialiser-mot-de-passe', name: 'reinitialiser_mdp', methods: ['POST'])]
    public function reinitialiserMotDePasse(
        Request $request,
        Enseignant $enseignant,
        UtilisateurRepository $utilisateurRepo,
        MotDePasseGenerator $motDePasseGenerator,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('reinitialiser_mdp'.$enseignant->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, veuillez réessayer.');
            return $this->redirectToRoute('admin_enseignant_show', ['id' => $enseignant->getId()]);
        }

        $utilisateur = $utilisateurRepo->findOneBy(['enseignant' => $enseignant]);
        if (!$utilisateur) {
            $this->addFlash('error', 'Cet enseignant n\'a pas encore d\'accès.');
            return $this->redirectToRoute('admin_enseignant_show', ['id' => $enseignant->getId()]);
        }

        $motDePasse = $motDePasseGenerator->generer();
        $utilisateur->setPassword($passwordHasher->hashPassword($utilisateur, $motDePasse));
        $utilisateur->setDoitChangerMotDePasse(true);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Nouveau mot de passe temporaire pour %s : %s — transmettez-le en main propre, il ne sera plus affiché.',
            $enseignant->getNomComplet(),
            $motDePasse,
        ));

        return $this->redirectToRoute('admin_enseignant_show', ['id' => $enseignant->getId()]);
    }
}
