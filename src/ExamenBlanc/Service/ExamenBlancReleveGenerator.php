<?php

declare(strict_types=1);

namespace App\ExamenBlanc\Service;

use App\Academic\Entity\Niveau;
use App\ExamenBlanc\Entity\ExamenBlanc;
use App\ExamenBlanc\Entity\ExamenBlancReleve;
use App\ExamenBlanc\Entity\ExamenBlancReleveMatiere;
use App\ExamenBlanc\Repository\ExamenBlancReleveRepository;
use App\ExamenBlanc\Service\Dto\ClassementEleveBlanc;
use App\ExamenBlanc\Service\Dto\ClassementNiveau;
use App\Grading\Service\AppreciationScale;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Convertit le classement calculé par ExamenBlancMoyenneCalculator en ExamenBlancReleve/
 * ExamenBlancReleveMatiere persistés — un snapshot figé, à l'opposé du calcul en direct.
 * genererPourNiveau() ignore silencieusement les élèves qui ont déjà un relevé pour cet
 * examen blanc : un snapshot verrouillé n'est jamais recalculé automatiquement, seule une
 * suppression explicite permet de régénérer (voir Grading\Service\BulletinGenerator, même
 * principe).
 */
final class ExamenBlancReleveGenerator
{
    public function __construct(
        private readonly ExamenBlancMoyenneCalculator $calculator,
        private readonly ExamenBlancReleveRepository $releveRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return ExamenBlancReleve[] uniquement les relevés nouvellement créés (vide si tous existaient déjà)
     */
    public function genererPourNiveau(ExamenBlanc $examenBlanc, Niveau $niveau): array
    {
        $classement = $this->calculator->calculer($examenBlanc, $niveau);

        $elevesDejaGeneres = array_map(
            static fn (ExamenBlancReleve $r): int => $r->getEleve()->getId(),
            $this->releveRepo->findByExamenBlancEtNiveau($examenBlanc, $niveau),
        );

        $releves = [];
        foreach ($classement->classement as $ligne) {
            if (in_array($ligne->moyenneEleve->eleve->getId(), $elevesDejaGeneres, true)) {
                continue;
            }
            $releves[] = $this->construireReleve($ligne, $classement);
        }

        if ($releves !== []) {
            $this->em->flush();
        }

        return $releves;
    }

    private function construireReleve(ClassementEleveBlanc $ligne, ClassementNiveau $classement): ExamenBlancReleve
    {
        $moyenneEleve = $ligne->moyenneEleve;

        $releve = new ExamenBlancReleve();
        $releve->setExamenBlanc($classement->examenBlanc);
        $releve->setEleve($moyenneEleve->eleve);
        $releve->setNiveau($classement->niveau);
        $releve->setClasse($moyenneEleve->classe);
        $releve->setMoyenneGenerale($moyenneEleve->moyenneGenerale);
        $releve->setRangNiveau($ligne->rang);
        $releve->setEffectifNiveau(count($classement->classement));
        $releve->setMoyenneNiveauFaible($classement->bilanNiveau->moyenneFaible);
        $releve->setMoyenneNiveauForte($classement->bilanNiveau->moyenneForte);
        $releve->setMoyenneNiveauGenerale($classement->bilanNiveau->moyenneNiveau);
        $releve->setMoyenneLitteraire($moyenneEleve->moyenneLitteraire);
        $releve->setMoyenneScientifique($moyenneEleve->moyenneScientifique);
        $this->em->persist($releve);

        foreach ($moyenneEleve->moyennesParMatiere as $moyenneMatiere) {
            $releveMatiere = new ExamenBlancReleveMatiere();
            $releveMatiere->setReleve($releve);
            $releveMatiere->setMatiere($moyenneMatiere->matiere);
            $releveMatiere->setCoefficient($moyenneMatiere->coefficient);
            $releveMatiere->setNote($moyenneMatiere->note);
            $releveMatiere->setEnseignantNom($moyenneMatiere->enseignantNom);
            $releveMatiere->setAppreciation(AppreciationScale::pour($moyenneMatiere->note));
            $this->em->persist($releveMatiere);
        }

        return $releve;
    }
}
