<?php

declare(strict_types=1);

namespace App\Teacher\Controller;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Repository\AnneeScolaireRepository;
use App\Exam\Entity\Examen;
use App\Exam\Repository\ExamenRepository;
use App\Exam\Repository\SurveillanceRepository;
use App\Scheduling\Repository\AttributionRepository;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Scheduling\Service\GrilleEmploiDuTempsBuilder;
use App\Security\Entity\Utilisateur;
use App\Staff\Entity\Enseignant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/enseignant', name: 'teacher_')]
class EspaceController extends AbstractController
{
    #[Route('', name: 'dashboard')]
    public function dashboard(
        SurveillanceRepository $surveillanceRepo,
        AnneeScolaireRepository $anneeRepo,
        AttributionRepository $attributionRepo,
        ExamenRepository $examenRepo,
    ): Response {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $enseignant  = $utilisateur->getEnseignant();

        $surveillances      = $enseignant ? $surveillanceRepo->findByEnseignant((int) $enseignant->getId()) : [];
        $totalSurveillances = count(array_unique(array_map(
            static fn($s) => $s->getExamen()->getId(),
            $surveillances,
        )));

        $annee         = $anneeRepo->findActive();
        $examensAVenir = $enseignant && $annee
            ? $this->examensAVenirPour($enseignant, $annee, $attributionRepo, $examenRepo)
            : [];

        return $this->render('teacher/dashboard.html.twig', [
            'enseignant'         => $enseignant,
            'surveillances'      => $surveillances,
            'totalSurveillances' => $totalSurveillances,
            'examensAVenir'      => $examensAVenir,
        ]);
    }

    /**
     * Examens à venir concernant les matières et niveaux où l'enseignant intervient (déduits de
     * ses attributions), pour qu'il puisse préparer ses épreuves à l'avance. Un examen peut
     * porter sur plusieurs niveaux à la fois (ex. Anglais 6ème+5ème) : il est retenu dès qu'AU
     * MOINS un de ses niveaux correspond à un niveau où l'enseignant donne cette même matière —
     * pas de filtre par `Examen::$publie` ici, cette liste est un outil de travail interne, pas
     * le calendrier public.
     *
     * @return Examen[]
     */
    private function examensAVenirPour(
        Enseignant $enseignant,
        AnneeScolaire $annee,
        AttributionRepository $attributionRepo,
        ExamenRepository $examenRepo,
    ): array {
        $niveauxParMatiere = [];
        foreach ($attributionRepo->findByEnseignantEtAnnee((int) $enseignant->getId(), (int) $annee->getId()) as $attribution) {
            $matiereId = $attribution->getMatiere()->getId();
            $niveauId  = $attribution->getClasse()->getNiveau()->getId();
            $niveauxParMatiere[$matiereId][$niveauId] = true;
        }

        if ($niveauxParMatiere === []) {
            return [];
        }

        $aujourdhui = new \DateTimeImmutable('today');

        return array_values(array_filter(
            $examenRepo->findByAnnee($annee),
            function (Examen $examen) use ($niveauxParMatiere, $aujourdhui): bool {
                if ($examen->getDate() < $aujourdhui) {
                    return false;
                }

                $niveauxEnseignes = $niveauxParMatiere[$examen->getMatiere()->getId()] ?? null;
                if ($niveauxEnseignes === null) {
                    return false;
                }

                foreach ($examen->getNiveaux() as $niveau) {
                    if (isset($niveauxEnseignes[$niveau->getId()])) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    #[Route('/mon-emploi-du-temps', name: 'edt')]
    public function edt(
        AnneeScolaireRepository $anneeRepo,
        SeanceRepository $seanceRepo,
        CreneauRepository $creneauRepo,
        GrilleEmploiDuTempsBuilder $grilleBuilder,
    ): Response {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $enseignant  = $utilisateur->getEnseignant();

        if ($enseignant === null) {
            throw $this->createAccessDeniedException('Ce compte n\'est lié à aucune fiche enseignant.');
        }

        $annee   = $anneeRepo->findActive();
        $seances = $annee ? $seanceRepo->findByEnseignantEtAnnee((int) $enseignant->getId(), (int) $annee->getId()) : [];

        [$creneauxParJour, $joursAffiches, $ordreMax] = $grilleBuilder->construireStructureCreneaux($creneauRepo);

        return $this->render('teacher/edt.html.twig', [
            'annee'           => $annee,
            'enseignant'      => $enseignant,
            'grille'          => $grilleBuilder->regrouperParCreneau($seances),
            'creneauxParJour' => $creneauxParJour,
            'joursAffiches'   => $joursAffiches,
            'ordreMax'        => $ordreMax,
        ]);
    }
}
