<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Staff\Entity\Enseignant;
use App\Staff\Enum\Sexe;
use App\Staff\Enum\TypePersonnel;
use App\Staff\Repository\EnseignantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Logique partagée d'import d'un enseignant/membre du personnel : fabrication de
 * l'e-mail (absent des documents source) et création/mise à jour idempotente par e-mail.
 * Utilisé par la commande console `app:staff:import-enseignants` et par l'import web
 * (upload Word/Excel/PDF).
 */
class EnseignantImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EnseignantRepository $enseignantRepo,
    ) {
    }

    /**
     * Crée ou met à jour (par e-mail) un Enseignant à partir d'une ligne normalisée.
     * Ne flush pas — à faire une seule fois par l'appelant après la boucle d'import.
     *
     * @param array{nom: string, prenom: string, sexe: ?Sexe, matricule: ?string, poste: ?string,
     *     specialite: ?string, cycle: ?string, telephone: string, type: TypePersonnel} $ligne
     * @param string[] $emailsUtilises
     */
    public function importerLigne(array $ligne, array &$emailsUtilises): Enseignant
    {
        $email = $this->genererEmail($ligne['nom'], $ligne['prenom'], $emailsUtilises);

        $enseignant = $this->enseignantRepo->findOneBy(['email' => $email]) ?? new Enseignant();
        if ($enseignant->getId() === null) {
            $this->em->persist($enseignant);
        }

        $enseignant->setNom($ligne['nom']);
        $enseignant->setPrenom($ligne['prenom']);
        $enseignant->setSexe($ligne['sexe']);
        $enseignant->setEmail($email);
        $enseignant->setTelephone($ligne['telephone'] !== '' ? $ligne['telephone'] : null);
        $enseignant->setType($ligne['type']);
        $enseignant->setPoste($ligne['poste']);
        $enseignant->setMatricule($ligne['matricule']);
        $enseignant->setSpecialite($ligne['specialite']);
        $enseignant->setCycle($ligne['cycle']);
        $enseignant->setActif(true);

        return $enseignant;
    }

    /** @param string[] $emailsUtilises */
    public function genererEmail(string $nom, string $prenom, array &$emailsUtilises): string
    {
        // Ignore les titres (Sr/M./Mme…) et les initiales isolées (ex. "T." dans "T. Mawèki")
        // pour ne pas fabriquer un e-mail du style "t.aboa@..." — on préfère le prénom complet suivant.
        $motsAIgnorer = ['sr', 'srm', 'm', 'mme', 'mlle'];
        $premier      = '';
        foreach (preg_split('/\s+/', trim($prenom)) ?: [] as $mot) {
            $slug = $this->slug($mot);
            if ($slug !== '' && strlen($slug) > 1 && !in_array($slug, $motsAIgnorer, true)) {
                $premier = $slug;
                break;
            }
        }

        $nomSlug = $this->slug($nom);
        $base    = $premier !== '' ? "{$premier}.{$nomSlug}" : $nomSlug;

        $email = "{$base}@college-adele.tg";
        $i     = 2;
        while (in_array($email, $emailsUtilises, true)) {
            $email = "{$base}{$i}@college-adele.tg";
            $i++;
        }
        $emailsUtilises[] = $email;

        return $email;
    }

    public function slug(string $valeur): string
    {
        $valeur = iconv('UTF-8', 'ASCII//TRANSLIT', $valeur) ?: $valeur;
        $valeur = strtolower($valeur);

        return preg_replace('/[^a-z]+/', '', $valeur) ?? '';
    }
}
