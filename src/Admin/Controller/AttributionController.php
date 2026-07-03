<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\MatiereNiveauRepository;
use App\Scheduling\Entity\Attribution;
use App\Scheduling\Form\AttributionCreateType;
use App\Scheduling\Form\AttributionType;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Service\AttributionCompletudeChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/attributions', name: 'admin_attribution_')]
class AttributionController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(AttributionRepository $repo): Response
    {
        $attributions = $repo->createQueryBuilder('a')
            ->join('a.classe', 'cl')
            ->join('a.enseignant', 'e')
            ->join('a.matiere', 'm')
            ->orderBy('e.nom', 'ASC')
            ->addOrderBy('e.prenom', 'ASC')
            ->addOrderBy('m.nom', 'ASC')
            ->addOrderBy('cl.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/attribution/index.html.twig', [
            'groupes' => $this->grouperParEnseignantEtMatiere($attributions, $repo->totalHeuresParEnseignant()),
        ]);
    }

    /**
     * Regroupe les attributions par enseignant puis par matière, pour un affichage
     * en tableau où chaque ligne "enseignant + matière" liste toutes les classes
     * concernées (avec leur volume horaire propre), et où le total hebdomadaire
     * affiché est celui de l'enseignant toutes matières confondues.
     *
     * @param Attribution[] $attributions
     * @param list<array{enseignant: \App\Staff\Entity\Enseignant, total: int}> $totauxParEnseignant
     * @return list<array{enseignant: \App\Staff\Entity\Enseignant, total: int, matieres: list<array{matiere: \App\Academic\Entity\Matiere, attributions: Attribution[]}>}>
     */
    private function grouperParEnseignantEtMatiere(array $attributions, array $totauxParEnseignant): array
    {
        $totauxParEnseignantId = [];
        foreach ($totauxParEnseignant as $t) {
            $totauxParEnseignantId[$t['enseignant']->getId()] = $t['total'];
        }

        $groupes = [];
        foreach ($attributions as $a) {
            $enseignantId = $a->getEnseignant()->getId();
            $matiereId = $a->getMatiere()->getId();

            if (!isset($groupes[$enseignantId])) {
                $groupes[$enseignantId] = [
                    'enseignant' => $a->getEnseignant(),
                    'total' => $totauxParEnseignantId[$enseignantId] ?? 0,
                    'matieres' => [],
                ];
            }

            if (!isset($groupes[$enseignantId]['matieres'][$matiereId])) {
                $groupes[$enseignantId]['matieres'][$matiereId] = [
                    'matiere' => $a->getMatiere(),
                    'attributions' => [],
                ];
            }

            $groupes[$enseignantId]['matieres'][$matiereId]['attributions'][] = $a;
        }

        foreach ($groupes as &$g) {
            $g['matieres'] = array_values($g['matieres']);
        }
        unset($g);

        return array_values($groupes);
    }

    /**
     * Pour chaque niveau, signale les classes qui n'ont pas encore d'enseignant
     * dans une matière effectivement enseignée à ce niveau — permet de vérifier
     * que les attributions sont complètes avant de générer l'emploi du temps.
     */
    #[Route('/verification', name: 'verification')]
    public function verification(AnneeScolaireRepository $anneeRepo, AttributionCompletudeChecker $checker): Response
    {
        $annee = $anneeRepo->findActive();

        return $this->render('admin/attribution/verification.html.twig', [
            'annee'   => $annee,
            'rapport' => $annee ? $checker->verifier($annee) : null,
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, MatiereNiveauRepository $mnRepo, AttributionRepository $attributionRepo): Response
    {
        $form = $this->createForm(AttributionCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $enseignant = $form->get('enseignant')->getData();
            $matiere    = $form->get('matiere')->getData();
            $classes    = $form->get('classes')->getData();

            $creees   = 0;
            $ignorees = [];

            foreach ($classes as $classe) {
                if ($attributionRepo->findOneBy(['enseignant' => $enseignant, 'matiere' => $matiere, 'classe' => $classe])) {
                    $ignorees[] = $classe->getNom().' (attribution déjà existante)';
                    continue;
                }

                $conflit = $attributionRepo->findConflitMatiereClasse($matiere, $classe);
                if ($conflit !== null) {
                    $ignorees[] = sprintf(
                        '%s (déjà assignée à %s pour cette matière)',
                        $classe->getNom(),
                        $conflit->getEnseignant()->getNomComplet(),
                    );
                    continue;
                }

                $attribution = new Attribution();
                $attribution->setEnseignant($enseignant);
                $attribution->setMatiere($matiere);
                $attribution->setClasse($classe);

                $volume = $this->resoudreVolumeHoraire($attribution, $mnRepo);
                if ($volume === null) {
                    $ignorees[] = $classe->getNom().' (aucun volume horaire défini pour cette matière à ce niveau)';
                    continue;
                }

                $attribution->setVolumeHoraireHebdo($volume);
                $em->persist($attribution);
                $creees++;
            }

            if ($creees > 0) {
                $em->flush();
                $this->addFlash('success', sprintf('%d attribution(s) créée(s).', $creees));
            }
            if ($ignorees !== []) {
                $this->addFlash('warning', 'Non créées : '.implode(', ', $ignorees));
            }
            if ($creees > 0 || $ignorees !== []) {
                return $this->redirectToRoute('admin_attribution_index');
            }
        }

        return $this->render('admin/attribution/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Attribution $attribution, EntityManagerInterface $em, MatiereNiveauRepository $mnRepo, AttributionRepository $attributionRepo): Response
    {
        $form = $this->createForm(AttributionType::class, $attribution);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $conflit = $attributionRepo->findConflitMatiereClasse($attribution->getMatiere(), $attribution->getClasse(), $attribution->getId());

            if ($conflit !== null) {
                $form->get('classe')->addError(new FormError(sprintf(
                    'Cette classe a déjà %s pour cette matière. Une classe ne peut avoir qu\'un seul enseignant par matière.',
                    $conflit->getEnseignant()->getNomComplet(),
                )));
            } else {
                $volume = $this->resoudreVolumeHoraire($attribution, $mnRepo);
                if ($volume === null) {
                    $form->get('matiere')->addError(new FormError(
                        'Aucun volume horaire défini pour cette matière à ce niveau. '
                        .'Complétez la fiche matière d\'abord.'
                    ));
                } else {
                    $attribution->setVolumeHoraireHebdo($volume);
                    $em->flush();
                    $this->addFlash('success', 'Attribution modifiée.');
                    return $this->redirectToRoute('admin_attribution_index');
                }
            }
        }

        return $this->render('admin/attribution/form.html.twig', ['form' => $form, 'attribution' => $attribution]);
    }

    /**
     * Le volume horaire hebdomadaire n'est jamais saisi à la main : il est déduit
     * de la grille MatiereNiveau (matière × niveau de la classe choisie).
     */
    private function resoudreVolumeHoraire(Attribution $attribution, MatiereNiveauRepository $mnRepo): ?int
    {
        $mn = $mnRepo->findOneBy([
            'matiere' => $attribution->getMatiere(),
            'niveau'  => $attribution->getClasse()->getNiveau(),
        ]);

        if ($mn === null || (float) $mn->getHeuresParSemaine() <= 0) {
            return null;
        }

        return (int) round((float) $mn->getHeuresParSemaine());
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Attribution $attribution, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$attribution->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($attribution);
            $em->flush();
            $this->addFlash('success', 'Attribution supprimée.');
        }
        return $this->redirectToRoute('admin_attribution_index');
    }
}
