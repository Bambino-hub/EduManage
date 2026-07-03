<?php

declare(strict_types=1);

namespace App\Staff\Command;

use App\Staff\Enum\Sexe;
use App\Staff\Enum\TypePersonnel;
use App\Staff\Service\EnseignantImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import de la "Liste des enseignants 2025-2026" (fichier Word fourni par l'établissement).
 * Le document a 3 sections qui correspondent exactement aux 3 cas de TypePersonnel :
 * personnel principal (INTERNE), "AUTRES" non-enseignants (AUTRE), enseignants externes (EXTERNE).
 *
 * L'e-mail n'existe pas dans le document source : il est fabriqué à partir du nom
 * (prénom.nom@college-adele.tg), avec dédoublonnage automatique si collision.
 */
#[AsCommand(
    name: 'app:staff:import-enseignants',
    description: 'Importe la liste du personnel 2025-2026 (nom, matricule, poste, discipline, cycle, contact)',
)]
class ImportEnseignantsCommand extends Command
{
    /**
     * @var list<array{nom: string, prenom: string, sexe: Sexe, matricule: ?string, poste: string,
     *     specialite: ?string, cycle: ?string, telephone: string, type: TypePersonnel}>
     */
    private const array PERSONNEL = [
        // --- Personnel principal (INTERNE) ---
        ['nom' => 'KOYE', 'prenom' => 'Sr M. Epiphanie Esso-houna', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Directrice', 'specialite' => 'FHR', 'cycle' => '2', 'telephone' => '93047713', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'AGNIBA', 'prenom' => 'Sr M. Gisèle Tchilalo', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Aumônière', 'specialite' => 'FHR', 'cycle' => '1', 'telephone' => '90657234', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'KONLANI', 'prenom' => 'Sr M. Agnès Natane-Man', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Econome', 'specialite' => 'FHR, TM', 'cycle' => '1', 'telephone' => '90066294', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'PAGNA', 'prenom' => 'Sr.M. Odile', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Enseignante', 'specialite' => 'FHR, Bibliothèque', 'cycle' => '1', 'telephone' => '79703729', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'LOKOU', 'prenom' => 'Sr.M. Joëlle', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Enseignante', 'specialite' => 'FHR/TM, Foyer', 'cycle' => '1', 'telephone' => '71832420', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'PEKELISSA', 'prenom' => 'Essohonam', 'sexe' => Sexe::M, 'matricule' => '107606-Y', 'poste' => 'Censeur', 'specialite' => 'HG/FR, ECM', 'cycle' => '1', 'telephone' => '93653642', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ATCHALI', 'prenom' => 'Lélen', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Secrétaire', 'specialite' => 'OPS', 'cycle' => null, 'telephone' => '91541169', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'KIDIYO', 'prenom' => 'Tchadabalo', 'sexe' => Sexe::M, 'matricule' => '047391-H', 'poste' => 'Enseignant', 'specialite' => 'SVT, PC', 'cycle' => '1', 'telephone' => '91817310', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ABOA', 'prenom' => 'T. Mawèki', 'sexe' => Sexe::F, 'matricule' => '070795-M', 'poste' => 'Enseignante', 'specialite' => 'ANG, AGRI', 'cycle' => '1', 'telephone' => '90843450', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'TENASSE', 'prenom' => 'S. Aniyéba', 'sexe' => Sexe::F, 'matricule' => '090150-G', 'poste' => 'Enseignante', 'specialite' => 'HG/FR, ECM', 'cycle' => '1', 'telephone' => '92905952', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'KOMOU', 'prenom' => 'Padabadi', 'sexe' => Sexe::M, 'matricule' => '079507-M', 'poste' => 'Enseignant', 'specialite' => 'HG', 'cycle' => '1/2', 'telephone' => '90012347', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'YAO', 'prenom' => '', 'sexe' => Sexe::F, 'matricule' => '068632-J', 'poste' => 'Enseignant', 'specialite' => 'ANG', 'cycle' => '1', 'telephone' => '70254704', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'NAMPAGOU', 'prenom' => 'Yendouban', 'sexe' => Sexe::F, 'matricule' => '100168-J', 'poste' => 'Enseignante', 'specialite' => 'FR', 'cycle' => '2', 'telephone' => '91872447', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'HUSRU', 'prenom' => 'Essi-sylvie', 'sexe' => Sexe::F, 'matricule' => '111834C', 'poste' => 'Enseignante', 'specialite' => 'FR', 'cycle' => '2', 'telephone' => '93976007', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'AWADE', 'prenom' => 'Essowè', 'sexe' => Sexe::M, 'matricule' => '120872-S', 'poste' => 'Enseignant', 'specialite' => 'SVT', 'cycle' => '2', 'telephone' => '90455316', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ATEYO', 'prenom' => 'Abiré', 'sexe' => Sexe::F, 'matricule' => '093612E', 'poste' => 'Enseignante', 'specialite' => 'EPS', 'cycle' => '1/2', 'telephone' => '90732207', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'AGBA', 'prenom' => 'Kyzzi', 'sexe' => Sexe::F, 'matricule' => '094381-P', 'poste' => 'Enseignante', 'specialite' => 'SVT', 'cycle' => '1/2', 'telephone' => '93407643', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'SIGOE', 'prenom' => 'Essobadou', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant / Surveillant', 'specialite' => 'ANG, ECM', 'cycle' => '2', 'telephone' => '93169421', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'DERMANE', 'prenom' => 'Ikpindi', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Enseignante', 'specialite' => 'FR, ECM', 'cycle' => '1', 'telephone' => '92579759', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'PATA', 'prenom' => 'Hodabalo', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'FR', 'cycle' => '1/2', 'telephone' => '92284930', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'KAGA', 'prenom' => 'Maoumba', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'HG, FR', 'cycle' => '1/2', 'telephone' => '91360788', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ABBY', 'prenom' => 'Mazamesso', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'FR, ECM', 'cycle' => '1/2', 'telephone' => '90957750', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'AGOSSA', 'prenom' => 'Kablè', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'HG', 'cycle' => '2', 'telephone' => '92103206', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'MEDJETOU', 'prenom' => 'Massou-Awaré', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'PHILO', 'cycle' => '2', 'telephone' => '92108116', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ATTENTIRAH', 'prenom' => 'Nassou', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant / Surveillant', 'specialite' => 'HG, Bibliothèque', 'cycle' => '1', 'telephone' => '92105135', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ANAKA', 'prenom' => 'Anaharime', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'INFO', 'cycle' => '1/2', 'telephone' => '92369562', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'FALABIA', 'prenom' => 'Alongim', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Enseignante', 'specialite' => 'EM', 'cycle' => '1/2', 'telephone' => '90705729', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ALAOUI', 'prenom' => 'T. Kadégna', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Enseignante', 'specialite' => 'ANG', 'cycle' => '1/2', 'telephone' => '91963760', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'AMOUSSOU', 'prenom' => 'Edo Yadou', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'EPS', 'cycle' => '1/2', 'telephone' => '70255291', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'WATOU', 'prenom' => 'Amesiki', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'SVT, AGRI', 'cycle' => '1/2', 'telephone' => '91318727', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'LEMOU', 'prenom' => 'Essozolim', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'PC', 'cycle' => '2', 'telephone' => '92695162', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ALI-DJOBO', 'prenom' => 'Nasser', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'FR', 'cycle' => '1', 'telephone' => '70255291', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'MASSAMPOU', 'prenom' => '', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'MATHS', 'cycle' => '2', 'telephone' => '91654086', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'NADJOMBE', 'prenom' => 'Manine', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'SVT, AGRI', 'cycle' => '1', 'telephone' => '91654007', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'KOULINTE', 'prenom' => 'Edah', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'MATHS', 'cycle' => '1', 'telephone' => '92564312', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'KPASSILI', 'prenom' => 'Mawoulé Richard', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'PHILO', 'cycle' => '2', 'telephone' => '93044066', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'PAWILISSIM', 'prenom' => 'Pawoumondom', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'FR, ECM', 'cycle' => '1/2', 'telephone' => '91791983', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'ALFA', 'prenom' => 'Essohouna', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'MUSIQ', 'cycle' => '1/2', 'telephone' => '92876732', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'EDJEOU', 'prenom' => 'Mondjonesso', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'MATHS', 'cycle' => '1/2', 'telephone' => '98484885', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'N’WENA', 'prenom' => 'Pyabalo', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'MATHS', 'cycle' => '1', 'telephone' => '92919170', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'TAPE DALLE', 'prenom' => 'Gbati', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'PC', 'cycle' => '2', 'telephone' => '92194078', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'KILI', 'prenom' => '', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Enseignante', 'specialite' => 'PCT', 'cycle' => '1', 'telephone' => '92780036', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'GNAMPI', 'prenom' => 'Mohamed', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant / Surveillant', 'specialite' => 'ANG, ECM', 'cycle' => '1', 'telephone' => '91296253', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'BADJAKE', 'prenom' => 'Abidé', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Enseignante', 'specialite' => 'ANG, Bibliothèque', 'cycle' => '1/2', 'telephone' => '90514162', 'type' => TypePersonnel::INTERNE],
        ['nom' => 'TIGANKPA', 'prenom' => 'Gnogno', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'PC, MATHS', 'cycle' => '1/2', 'telephone' => '90422765', 'type' => TypePersonnel::INTERNE],

        // --- AUTRES (personnel non-enseignant) ---
        ['nom' => 'TCHARIE', 'prenom' => 'José', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Gardien de nuit', 'specialite' => null, 'cycle' => null, 'telephone' => '92674710', 'type' => TypePersonnel::AUTRE],
        ['nom' => 'ODETTE', 'prenom' => '', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => 'Librairie', 'specialite' => null, 'cycle' => null, 'telephone' => '91803366', 'type' => TypePersonnel::AUTRE],
        ['nom' => 'Séraphine', 'prenom' => '', 'sexe' => Sexe::F, 'matricule' => null, 'poste' => "Salle d'informatique", 'specialite' => 'Cyber', 'cycle' => null, 'telephone' => '90644361', 'type' => TypePersonnel::AUTRE],
        // Poste "Virgile" imprimé tel quel dans le document source — intitulé de poste inhabituel,
        // probablement une erreur de saisie de l'original ; à vérifier auprès de l'établissement.
        ['nom' => 'TOSSOU', 'prenom' => 'Yao', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Virgile (à vérifier)', 'specialite' => null, 'cycle' => null, 'telephone' => '96275122', 'type' => TypePersonnel::AUTRE],

        // --- Enseignants externes (fonctionnaires) ---
        ['nom' => 'DAYIWO', 'prenom' => 'Komlan Roméo', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'SVT', 'cycle' => '2', 'telephone' => '90659024', 'type' => TypePersonnel::EXTERNE],
        ['nom' => 'DAGOU', 'prenom' => 'Passimzouwé', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'MATHS', 'cycle' => '2', 'telephone' => '91028129', 'type' => TypePersonnel::EXTERNE],
        ['nom' => 'GOUTIA', 'prenom' => 'Tinin', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'PC', 'cycle' => '2', 'telephone' => '90769664', 'type' => TypePersonnel::EXTERNE],
        ['nom' => 'ABALO', 'prenom' => 'Kossi', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'PC', 'cycle' => '2', 'telephone' => '90364524', 'type' => TypePersonnel::EXTERNE],
        ['nom' => 'ALEKI', 'prenom' => '', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'ESP', 'cycle' => '2', 'telephone' => '91587847', 'type' => TypePersonnel::EXTERNE],
        ['nom' => 'BALLE', 'prenom' => 'Essodinam Théodor', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'MATHS', 'cycle' => '1/2', 'telephone' => '92456984', 'type' => TypePersonnel::EXTERNE],
        ['nom' => 'NAKPANE', 'prenom' => '', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'ALL', 'cycle' => '2', 'telephone' => '90707519', 'type' => TypePersonnel::EXTERNE],
        ['nom' => 'OURIA', 'prenom' => '', 'sexe' => Sexe::M, 'matricule' => null, 'poste' => 'Enseignant', 'specialite' => 'EPS', 'cycle' => '1/2', 'telephone' => '93689871', 'type' => TypePersonnel::EXTERNE],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EnseignantImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les changements sans les enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $emailsUtilises = [];
        $created        = 0;
        $updated        = 0;

        foreach (self::PERSONNEL as $ligne) {
            $enseignant = $this->importer->importerLigne($ligne, $emailsUtilises);
            $estNouveau = $enseignant->getId() === null;

            $label = "{$enseignant->getNom()} {$enseignant->getPrenom()} <{$enseignant->getEmail()}>";
            $io->writeln(($estNouveau ? '  + ' : '  ~ ').$label);

            $estNouveau ? $created++ : $updated++;
        }

        if ($dryRun) {
            $io->note('Mode --dry-run : aucune modification enregistrée.');
        } else {
            $this->em->flush();
        }

        $io->success(sprintf('%d enseignant(s)/personnel créé(s), %d déjà existant(s) mis à jour.', $created, $updated));

        return Command::SUCCESS;
    }
}
