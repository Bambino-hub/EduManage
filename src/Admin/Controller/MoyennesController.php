<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Academic\Entity\Classe;
use App\Academic\Repository\ClasseRepository;
use App\Grading\Entity\Trimestre;
use App\Grading\Repository\BulletinRepository;
use App\Grading\Repository\TrimestreRepository;
use App\Grading\Service\MoyenneCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Moyennes et classement d'une classe pour un trimestre — lecture seule, aucune écriture. */
#[Route('/admin/moyennes', name: 'admin_moyennes_')]
class MoyennesController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(
        Request $request,
        ClasseRepository $classeRepo,
        TrimestreRepository $trimestreRepo,
        MoyenneCalculator $calculator,
        BulletinRepository $bulletinRepo,
    ): Response {
        $classes = $classeRepo->findByAnneeScolaireActive();

        $classeId = $request->query->getInt('classe') ?: null;
        $classe   = $classeId ? $classeRepo->find($classeId) : ($classes[0] ?? null);

        $trimestres = $classe ? $trimestreRepo->findByAnneeScolaire($classe->getAnneeScolaire()) : [];

        $trimestreId = $request->query->getInt('trimestre') ?: null;
        $trimestre   = $trimestreId
            ? $this->trouverTrimestre($trimestres, $trimestreId)
            : ($trimestres[0] ?? null);

        $classement = ($classe instanceof Classe && $trimestre instanceof Trimestre)
            ? $calculator->calculer($classe, $trimestre)
            : null;

        $bulletins           = ($classe instanceof Classe && $trimestre instanceof Trimestre)
            ? $bulletinRepo->findByClasseEtTrimestre($classe, $trimestre)
            : [];
        $bulletinsParEleveId = [];
        foreach ($bulletins as $bulletin) {
            $bulletinsParEleveId[$bulletin->getEleve()->getId()] = $bulletin;
        }

        return $this->render('admin/moyennes/index.html.twig', [
            'classes'             => $classes,
            'classe'              => $classe,
            'trimestres'          => $trimestres,
            'trimestre'           => $trimestre,
            'classement'          => $classement,
            'bulletins'           => $bulletins,
            'bulletinsParEleveId' => $bulletinsParEleveId,
        ]);
    }

    /** @param Trimestre[] $trimestres */
    private function trouverTrimestre(array $trimestres, int $id): ?Trimestre
    {
        foreach ($trimestres as $trimestre) {
            if ($trimestre->getId() === $id) {
                return $trimestre;
            }
        }

        return null;
    }
}
