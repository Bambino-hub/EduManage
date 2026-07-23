<?php

declare(strict_types=1);

namespace App\Student\Service;

use App\Academic\Repository\ClasseRepository;
use App\Staff\Enum\Sexe;
use App\Student\Entity\Eleve;
use App\Student\Entity\Inscription;
use App\Student\Repository\EleveRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Logique partagée d'import d'un élève : création/mise à jour idempotente par matricule,
 * et inscription automatique dans la classe indiquée si elle est reconnue (classe active
 * de l'année scolaire en cours). Utilisée par l'import web (upload Excel).
 */
class EleveImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EleveRepository $eleveRepo,
        private readonly ClasseRepository $classeRepo,
    ) {
    }

    /**
     * Crée ou met à jour (par matricule) un Eleve à partir d'une ligne normalisée, et
     * l'inscrit dans la classe indiquée si elle correspond à une classe active connue et
     * qu'il n'y est pas déjà inscrit. Ne flush pas — à faire une seule fois par l'appelant
     * après la boucle d'import. Le matricule est obligatoire (numérotation officielle de
     * l'établissement, pas générée par l'application) : à l'appelant de filtrer les lignes
     * sans matricule avant d'appeler cette méthode.
     *
     * @param array{matricule: ?string, nom: string, prenom: string, sexe: ?string|Sexe,
     *     dateNaissance: ?string, classe: ?string} $ligne
     * @param string[] $matriculesUtilises
     */
    public function importerLigne(array $ligne, array &$matriculesUtilises): Eleve
    {
        $matricule = trim((string) ($ligne['matricule'] ?? ''));
        $matriculesUtilises[] = $matricule;

        $eleve = $this->eleveRepo->findOneBy(['matricule' => $matricule]) ?? new Eleve();
        if ($eleve->getId() === null) {
            $this->em->persist($eleve);
        }

        $sexe = $ligne['sexe'] ?? null;
        if (is_string($sexe)) {
            $sexe = Sexe::tryFrom(strtoupper($sexe));
        }

        $eleve->setMatricule($matricule);
        $eleve->setNom($ligne['nom']);
        $eleve->setPrenom($ligne['prenom'] ?? '');
        $eleve->setSexe($sexe instanceof Sexe ? $sexe : null);
        $eleve->setDateNaissance($this->parserDate($ligne['dateNaissance'] ?? null));

        $this->inscrireSiClasseReconnue($eleve, $ligne['classe'] ?? null);

        return $eleve;
    }

    private function inscrireSiClasseReconnue(Eleve $eleve, ?string $nomClasse): void
    {
        $nomClasse = trim((string) $nomClasse);
        if ($nomClasse === '') {
            return;
        }

        $classe = null;
        foreach ($this->classeRepo->findByAnneeScolaireActive() as $candidate) {
            if (mb_strtolower($candidate->getNom(), 'UTF-8') === mb_strtolower($nomClasse, 'UTF-8')) {
                $classe = $candidate;
                break;
            }
        }
        if ($classe === null) {
            return; // classe non reconnue : l'admin inscrira manuellement depuis la fiche élève
        }

        foreach ($eleve->getInscriptions() as $inscription) {
            if ($inscription->getClasse() === $classe && $inscription->isEnCours()) {
                return; // déjà inscrit dans cette classe
            }
        }

        $inscription = new Inscription();
        $inscription->setEleve($eleve);
        $inscription->setNiveau($classe->getNiveau());
        $inscription->setClasse($classe);
        $inscription->setDateInscription(new \DateTimeImmutable());
        $this->em->persist($inscription);
    }

    private function parserDate(?string $valeur): ?\DateTimeImmutable
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $valeur);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }
}
