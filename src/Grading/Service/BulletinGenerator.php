<?php

declare(strict_types=1);

namespace App\Grading\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Classe;
use App\Grading\Entity\Bulletin;
use App\Grading\Entity\BulletinBilanDomaine;
use App\Grading\Entity\BulletinMatiere;
use App\Grading\Entity\Trimestre;
use App\Grading\Repository\BulletinRepository;
use App\Grading\Service\Dto\ClassementClasse;
use App\Grading\Service\Dto\ClassementEleve;
use App\Student\Entity\Eleve;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Convertit le classement calculé par MoyenneCalculator en Bulletin/BulletinMatiere/
 * BulletinBilanDomaine persistés — un snapshot figé, à l'opposé du calcul en direct de
 * l'écran /admin/moyennes. genererPourClasse() et genererPourEleve() ignorent
 * silencieusement les élèves qui ont déjà un bulletin pour ce trimestre : un snapshot
 * verrouillé n'est jamais recalculé automatiquement, seule une suppression explicite
 * (BulletinController) permet de le régénérer.
 */
final class BulletinGenerator
{
    public function __construct(
        private readonly MoyenneCalculator $calculator,
        private readonly BulletinRepository $bulletinRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Génère les bulletins de la classe qui n'existent pas encore pour ce trimestre —
     * les élèves déjà générés (individuellement ou lors d'un appel précédent) sont
     * ignorés, leur snapshot verrouillé n'est jamais recalculé.
     *
     * @return Bulletin[] uniquement les bulletins nouvellement créés (vide si tous existaient déjà)
     */
    public function genererPourClasse(Classe $classe, Trimestre $trimestre): array
    {
        $classement = $this->calculator->calculer($classe, $trimestre);

        $elevesDejaGeneres = array_map(
            static fn (Bulletin $b): int => $b->getEleve()->getId(),
            $this->bulletinRepo->findByClasseEtTrimestre($classe, $trimestre),
        );

        // Passe 1 : moyennes/rangs du trimestre (déjà calculés par MoyenneCalculator).
        $bulletins = [];
        foreach ($classement->classement as $ligne) {
            if (in_array($ligne->moyenneEleve->eleve->getId(), $elevesDejaGeneres, true)) {
                continue;
            }
            $bulletins[] = $this->construireBulletin($ligne, $classe, $trimestre, $classement);
        }

        if ($bulletins === []) {
            return [];
        }

        // Flush nécessaire : la passe 2 recherche les bulletins des autres trimestres via
        // BulletinRepository, qui doit donc voir ceux qu'on vient de créer.
        $this->em->flush();

        $this->finaliserMoyennesAnnuelles($bulletins, $classe, $trimestre);

        return $bulletins;
    }

    /**
     * Génère le bulletin d'un seul élève de la classe, sans toucher aux bulletins déjà
     * générés des autres élèves (utile pour une inscription tardive ou une correction
     * ponctuelle, sans avoir à supprimer puis régénérer toute la classe).
     *
     * Le classement (rang, effectif, bilan de classe) est recalculé sur toute la classe
     * comme pour genererPourClasse — seul l'élève demandé est persisté.
     */
    public function genererPourEleve(Classe $classe, Trimestre $trimestre, Eleve $eleve): Bulletin
    {
        $classement = $this->calculator->calculer($classe, $trimestre);

        $ligne = null;
        foreach ($classement->classement as $candidate) {
            if ($candidate->moyenneEleve->eleve->getId() === $eleve->getId()) {
                $ligne = $candidate;
                break;
            }
        }

        if ($ligne === null) {
            throw new \RuntimeException('Cet élève ne fait pas partie du classement de cette classe pour ce trimestre.');
        }

        $bulletin = $this->construireBulletin($ligne, $classe, $trimestre, $classement);
        $this->em->flush();

        $this->finaliserMoyennesAnnuelles([$bulletin], $classe, $trimestre);

        return $bulletin;
    }

    /**
     * Passe 2 : Moyenne Générale Annuelle + rang annuel de chaque bulletin de
     * $bulletins, calculés à partir de TOUS les bulletins de cette classe/trimestre
     * (ceux qu'on vient de créer et ceux déjà verrouillés) — sans jamais modifier ces
     * derniers, seulement $bulletins.
     *
     * @param Bulletin[] $bulletins
     */
    private function finaliserMoyennesAnnuelles(array $bulletins, Classe $classe, Trimestre $trimestre): void
    {
        $annee = $classe->getAnneeScolaire();
        foreach ($bulletins as $bulletin) {
            $this->calculerMoyenneAnnuelle($bulletin, $annee);
        }

        $valeursParEleveId = [];
        foreach ($this->bulletinRepo->findByClasseEtTrimestre($classe, $trimestre) as $b) {
            $valeursParEleveId[$b->getEleve()->getId()] = $b->getMoyenneAnnuelle();
        }

        $rangsAnnuels = $this->rangParValeur($valeursParEleveId);
        foreach ($bulletins as $bulletin) {
            $bulletin->setRangAnnuel($rangsAnnuels[$bulletin->getEleve()->getId()] ?? null);
        }

        $this->em->flush();
    }

    private function construireBulletin(
        ClassementEleve $ligne,
        Classe $classe,
        Trimestre $trimestre,
        ClassementClasse $classement,
    ): Bulletin {
        $bulletin = new Bulletin();
        $bulletin->setEleve($ligne->moyenneEleve->eleve);
        $bulletin->setClasse($classe);
        $bulletin->setTrimestre($trimestre);
        $bulletin->setMoyenneGenerale($ligne->moyenneEleve->moyenneGenerale);
        $bulletin->setRang($ligne->rang);
        $bulletin->setEffectifClasse(count($classement->classement));
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

        return $bulletin;
    }

    /** Moyenne Générale Annuelle à partir des bulletins déjà verrouillés de l'année (celui-ci compris) — ne modifie que $bulletin. */
    private function calculerMoyenneAnnuelle(Bulletin $bulletin, AnneeScolaire $annee): ?string
    {
        $bulletinsAnnee  = $this->bulletinRepo->findByEleveEtAnneeScolaire($bulletin->getEleve(), $annee);
        $moyenneAnnuelle = $this->moyenneSimple(array_map(
            static fn (Bulletin $b): ?string => $b->getMoyenneGenerale(),
            $bulletinsAnnee,
        ));
        $bulletin->setMoyenneAnnuelle($moyenneAnnuelle);

        return $moyenneAnnuelle;
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
