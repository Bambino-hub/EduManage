<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Salle;
use App\Academic\Enum\TypeSalle;
use App\Academic\Form\ClasseType;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\SalleRepository;
use App\Scheduling\Entity\Seance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Chaque classe a sa propre salle standard, du même nom, créée/renommée/supprimée
 * automatiquement avec elle (décision explicite de l'utilisateur, 2026-09-16, pour
 * éliminer définitivement les conflits de salle : le générateur d'emploi du temps
 * n'utilise plus QUE cette salle pour toutes les matières de la classe, y compris les
 * matières parallèles ALL/ESP ou TM/EM, qui la partagent au lieu d'en emprunter une 2ᵉ).
 */
#[Route('/admin/classes', name: 'admin_classe_')]
class ClasseController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(ClasseRepository $repo): Response
    {
        return $this->render('admin/classe/index.html.twig', [
            'classes' => $repo->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, SalleRepository $salleRepo, ClasseRepository $classeRepo): Response
    {
        $classe = new Classe();
        $form   = $this->createForm(ClasseType::class, $classe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($classe);
            $this->assurerSalleStandard($classe, $em, $salleRepo);
            $em->flush();
            $this->addFlash('success', 'Classe créée (avec sa salle standard).');
            return $this->redirectToRoute('admin_classe_index');
        }

        return $this->render('admin/classe/form.html.twig', ['form' => $form, 'classe' => $classe]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Classe $classe, EntityManagerInterface $em, SalleRepository $salleRepo, ClasseRepository $classeRepo): Response
    {
        $ancienNom = $classe->getNom();
        $form      = $this->createForm(ClasseType::class, $classe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($classe->getNom() !== $ancienNom) {
                $this->renommerSalleStandard($ancienNom, $classe, $em, $salleRepo, $classeRepo);
            }
            $this->assurerSalleStandard($classe, $em, $salleRepo);
            $em->flush();
            $this->addFlash('success', 'Classe modifiée.');
            return $this->redirectToRoute('admin_classe_index');
        }

        return $this->render('admin/classe/form.html.twig', ['form' => $form, 'classe' => $classe]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Classe $classe, EntityManagerInterface $em, SalleRepository $salleRepo, ClasseRepository $classeRepo): Response
    {
        if ($this->isCsrfTokenValid('delete'.$classe->getId(), $request->getPayload()->getString('_token'))) {
            $nom = $classe->getNom();
            $em->remove($classe);
            $this->supprimerSalleSiOrpheline($nom, $classe, $em, $salleRepo, $classeRepo);
            $em->flush();
            $this->addFlash('success', 'Classe supprimée.');
        }
        return $this->redirectToRoute('admin_classe_index');
    }

    /** Crée la salle standard homonyme si elle n'existe pas déjà (find-or-create : le nom d'une classe peut se répéter d'une année sur l'autre, la salle est une ressource physique partagée). */
    private function assurerSalleStandard(Classe $classe, EntityManagerInterface $em, SalleRepository $salleRepo): void
    {
        if ($salleRepo->findOneBy(['nom' => $classe->getNom()]) !== null) {
            return;
        }

        $salle = new Salle();
        $salle->setNom($classe->getNom());
        $salle->setType(TypeSalle::STANDARD);
        $salle->setCapacite($classe->getEffectifMax());
        $em->persist($salle);
    }

    /**
     * Si l'ancien nom n'est plus utilisé par AUCUNE autre classe (une autre année
     * scolaire peut avoir une classe du même nom, auquel cas la salle reste partagée),
     * renomme la salle standard homonyme au lieu d'en laisser une orpheline traîner.
     */
    private function renommerSalleStandard(string $ancienNom, Classe $classe, EntityManagerInterface $em, SalleRepository $salleRepo, ClasseRepository $classeRepo): void
    {
        $autresClassesMemeNom = array_filter(
            $classeRepo->findBy(['nom' => $ancienNom]),
            static fn (Classe $c) => $c->getId() !== $classe->getId(),
        );
        if ($autresClassesMemeNom !== []) {
            return; // salle encore légitimement utilisée par une classe d'une autre année
        }

        $ancienneSalle = $salleRepo->findOneBy(['nom' => $ancienNom, 'type' => TypeSalle::STANDARD]);
        if ($ancienneSalle === null || $salleRepo->findOneBy(['nom' => $classe->getNom()]) !== null) {
            return; // pas de salle à renommer, ou le nouveau nom est déjà pris par une autre salle
        }

        $ancienneSalle->setNom($classe->getNom());
    }

    /** Supprime la salle standard homonyme si plus aucune classe (aucune année) ne s'en sert et qu'aucune séance ne la référence. */
    private function supprimerSalleSiOrpheline(string $nom, Classe $classeSupprimee, EntityManagerInterface $em, SalleRepository $salleRepo, ClasseRepository $classeRepo): void
    {
        $autresClassesMemeNom = array_filter(
            $classeRepo->findBy(['nom' => $nom]),
            static fn (Classe $c) => $c->getId() !== $classeSupprimee->getId(),
        );
        if ($autresClassesMemeNom !== []) {
            return;
        }

        $salle = $salleRepo->findOneBy(['nom' => $nom, 'type' => TypeSalle::STANDARD]);
        if ($salle === null) {
            return;
        }

        $nbSeances = $em->getRepository(Seance::class)->count(['salle' => $salle]);
        if ($nbSeances > 0) {
            $this->addFlash('warning', sprintf('La salle "%s" n\'a pas été supprimée : %d séance(s) l\'utilisent encore.', $nom, $nbSeances));
            return;
        }

        $em->remove($salle);
    }
}
