<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Academic\Entity\Classe;
use App\Grading\Entity\Bulletin;
use App\Grading\Entity\BulletinBilanDomaine;
use App\Grading\Entity\BulletinMatiere;
use App\Grading\Entity\Trimestre;
use App\Grading\Repository\BulletinRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Convertit le classement calculé par MoyenneCalculator en Bulletin/BulletinMatiere/
 * BulletinBilanDomaine persistés — un snapshot figé, à l'opposé du calcul en direct de
 * l'écran /admin/moyennes. Ne vérifie pas si des bulletins existent déjà pour cette
 * classe/trimestre : c'est au contrôleur de le faire avant d'appeler ce service (même
 * séparation que NoteSaisieService).
 */
final class BulletinGenerator
{
    public function __construct(
        private readonly MoyenneCalculator $calculator,
        private readonly BulletinRepository $bulletinRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return Bulletin[] */
    public function genererPourClasse(Classe $classe, Trimestre $trimestre): array
    {
        $classement = $this->calculator->calculer($classe, $trimestre);
        $effectif   = count($classement->classement);
        $annee      = $classe->getAnneeScolaire();

        // Passe 1 : moyennes/rangs du trimestre (déjà calculés par MoyenneCalculator).
        $bulletins = [];
        foreach ($classement->classement as $ligne) {
            $bulletin = new Bulletin();
            $bulletin->setEleve($ligne->moyenneEleve->eleve);
            $bulletin->setClasse($classe);
            $bulletin->setTrimestre($trimestre);
            $bulletin->setMoyenneGenerale($ligne->moyenneEleve->moyenneGenerale);
            $bulletin->setRang($ligne->rang);
            $bulletin->setEffectifClasse($effectif);
            $bulletin->setMoyenneClasseFaible($classement->bilanClasse->moyenneFaible);
            $bulletin->setMoyenneClasseForte($classement->bilanClasse->moyenneForte);
            $bulletin->setMoyenneClasseGenerale($classement->bilanClasse->moyenneClasse);
            $this->em->persist($bulletin);

            foreach ($ligne->moyenneEleve->moyennesParMatiere as $moyenneMatiere) {
                $bulletinMatiere = new BulletinMatiere();
                $bulletinMatiere->setBulletin($bulletin);
                $bulletinMatiere->setMatiere($moyenneMatiere->matiere);
                $bulletinMatiere->setCoefficient($moyenneMatiere->coefficient);
                $bulletinMatiere->setMoyenneInterrogation($moyenneMatiere->moyenneInterrogation);
                $bulletinMatiere->setMoyenneDevoirs($moyenneMatiere->moyenneDevoirs);
                $bulletinMatiere->setMoyenneComposition($moyenneMatiere->moyenneComposition);
                $bulletinMatiere->setMoyenne($moyenneMatiere->moyenne);
                $bulletinMatiere->setRang($moyenneMatiere->rang);
                $bulletinMatiere->setEnseignantNom($moyenneMatiere->enseignantNom);
                $bulletinMatiere->setAppreciation(AppreciationScale::pour($moyenneMatiere->moyenne));
                $this->em->persist($bulletinMatiere);
            }

            foreach ($ligne->moyenneEleve->bilansDomaine as $bilanDomaine) {
                $bulletinBilanDomaine = new BulletinBilanDomaine();
                $bulletinBilanDomaine->setBulletin($bulletin);
                $bulletinBilanDomaine->setDomaine($bilanDomaine->domaine);
                $bulletinBilanDomaine->setMoyenne($bilanDomaine->moyenne);
                $bulletinBilanDomaine->setAppreciation(AppreciationScale::pour($bilanDomaine->moyenne));
                $this->em->persist($bulletinBilanDomaine);
            }

            $bulletins[] = $bulletin;
        }

        // Flush nécessaire : la passe 2 recherche les bulletins des autres trimestres via
        // BulletinRepository, qui doit donc voir ceux qu'on vient de créer.
        $this->em->flush();

        // Passe 2 : Moyenne Générale Annuelle + rang annuel, à partir des bulletins déjà
        // verrouillés des trimestres de la même année scolaire (celui-ci compris).
        $moyennesAnnuellesParEleveId = [];
        foreach ($bulletins as $bulletin) {
            $bulletinsAnnee  = $this->bulletinRepo->findByEleveEtAnneeScolaire($bulletin->getEleve(), $annee);
            $moyenneAnnuelle = $this->moyenneSimple(array_map(
                static fn (Bulletin $b): ?string => $b->getMoyenneGenerale(),
                $bulletinsAnnee,
            ));
            $bulletin->setMoyenneAnnuelle($moyenneAnnuelle);
            $moyennesAnnuellesParEleveId[$bulletin->getEleve()->getId()] = $moyenneAnnuelle;
        }

        $rangsAnnuels = $this->rangParValeur($moyennesAnnuellesParEleveId);
        foreach ($bulletins as $bulletin) {
            $bulletin->setRangAnnuel($rangsAnnuels[$bulletin->getEleve()->getId()] ?? null);
        }

        $this->em->flush();

        return $bulletins;
    }

    /** @param array<int, ?string> $valeurs */
    private function moyenneSimple(array $valeurs): ?string
    {
        $notees = array_values(array_filter($valeurs, static fn (?string $v): bool => $v !== null));
        if ($notees === []) {
            return null;
        }

        $somme = array_sum(array_map('floatval', $notees));

        return number_format($somme / count($notees), 2, '.', '');
    }

    /**
     * Classement compétition standard (1,2,2,4), même algorithme que
     * MoyenneCalculator::rangParValeur() — pas partagé, ~15 lignes, contextes différents
     * (ici sur la moyenne annuelle, pas connue de MoyenneCalculator qui ne voit qu'un trimestre).
     *
     * @param array<int, ?string> $valeursParEleveId
     * @return array<int, ?int>
     */
    private function rangParValeur(array $valeursParEleveId): array
    {
        $notes = array_filter($valeursParEleveId, static fn (?string $v): bool => $v !== null);
        arsort($notes, SORT_NUMERIC);

        $rangs            = [];
        $rangPrecedent    = null;
        $valeurPrecedente = null;
        $index            = 0;
        foreach ($notes as $eleveId => $valeur) {
            $rang = ($valeurPrecedente !== null && $valeur === $valeurPrecedente) ? $rangPrecedent : $index + 1;
            $rangs[$eleveId]  = $rang;
            $rangPrecedent    = $rang;
            $valeurPrecedente = $valeur;
            $index++;
        }

        foreach ($valeursParEleveId as $eleveId => $valeur) {
            if ($valeur === null) {
                $rangs[$eleveId] = null;
            }
        }

        return $rangs;
    }
}
