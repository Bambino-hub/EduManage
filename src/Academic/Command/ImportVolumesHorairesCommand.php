<?php

declare(strict_types=1);

namespace App\Academic\Command;

use App\Academic\Entity\MatiereNiveau;
use App\Academic\Repository\MatiereRepository;
use App\Academic\Repository\NiveauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:academic:import-volumes-horaires',
    description: 'Importe le volume horaire hebdomadaire par matière x niveau',
)]
class ImportVolumesHorairesCommand extends Command
{
    /**
     * Grille horaire hebdomadaire par niveau (et série pour le lycée).
     *
     * Source : Volumes_horaires_par_classe_2025_2026.pdf, qui donnait les heures
     * par classe (A/B/C...). Agrégée ici par niveau car les classes d'un même
     * niveau ont un horaire identique, à trois exceptions près :
     * - "DEVOI" / "PLEINAIRE" (6ème A) et "MUSIQUE" (3ème A) : présentes dans une
     *   seule classe sur trois et absentes du catalogue matière → ignorées.
     * - "2nde CD" : la source distingue CD1/CD2/CD3 avec des volumes différents ;
     *   CD3 a été retenue comme référence (décision validée avec l'établissement).
     * - "ALL/ESP" (allemand ou espagnol au choix) et "TM/EM" (travail manuel /
     *   enseignement ménager) : dupliquées sur les deux matières du catalogue
     *   avec le même volume, plutôt que de créer des matières combinées.
     *
     * @var list<array{niveau: string, serie: ?string, heures: array<string, int>}>
     */
    private const array GRILLE = [
        ['niveau' => '6ème', 'serie' => null, 'heures' => [
            'AGRI' => 1, 'ANG' => 4, 'BIBLIO' => 1, 'DESSIN' => 1, 'ECM' => 2, 'EPS' => 2,
            'FHR' => 1, 'FR' => 6, 'HG' => 2, 'INFO' => 1, 'MATHS' => 4, 'PCT' => 3,
            'SVT' => 2, 'TM' => 1, 'EM' => 1,
        ]],
        ['niveau' => '5ème', 'serie' => null, 'heures' => [
            'AGRI' => 1, 'ANG' => 4, 'BIBLIO' => 1, 'DESSIN' => 1, 'ECM' => 1, 'EPS' => 2,
            'FHR' => 1, 'FR' => 6, 'HG' => 2, 'INFO' => 1, 'MATHS' => 4, 'MUSIQ' => 1,
            'PCT' => 3, 'SVT' => 2, 'TM' => 1, 'EM' => 1,
        ]],
        ['niveau' => '4ème', 'serie' => null, 'heures' => [
            'AGRI' => 1, 'ANG' => 4, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1, 'FR' => 6,
            'HG' => 2, 'INFO' => 1, 'MATHS' => 4, 'MUSIQ' => 1, 'PCT' => 4, 'SVT' => 3,
            'TM' => 1, 'EM' => 1,
        ]],
        ['niveau' => '3ème', 'serie' => null, 'heures' => [
            'ANG' => 4, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1, 'FR' => 6, 'HG' => 3,
            'INFO' => 1, 'MATHS' => 4, 'PCT' => 4, 'SVT' => 4, 'TM' => 1, 'EM' => 1,
        ]],
        ['niveau' => '2nde', 'serie' => 'CD', 'heures' => [
            'ANG' => 3, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1, 'FR' => 4, 'HG' => 3,
            'INFO' => 1, 'MATHS' => 6, 'MUSIQ' => 1, 'PC' => 5, 'PHILO' => 2, 'SVT' => 3,
            'TM' => 1, 'EM' => 1,
        ]],
        ['niveau' => '2nde', 'serie' => 'A4', 'heures' => [
            'ALL' => 4, 'ESP' => 4, 'ANG' => 4, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1,
            'FR' => 4, 'HG' => 4, 'INFO' => 1, 'MATHS' => 2, 'MUSIQ' => 1, 'PC' => 3,
            'PHILO' => 3, 'SVT' => 2, 'TM' => 1, 'EM' => 1,
        ]],
        ['niveau' => '1ere', 'serie' => 'D', 'heures' => [
            'ANG' => 3, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1, 'FR' => 5, 'HG' => 4,
            'INFO' => 1, 'MATHS' => 5, 'PC' => 5, 'PHILO' => 2, 'SVT' => 4,
        ]],
        ['niveau' => '1ere', 'serie' => 'A4', 'heures' => [
            'ALL' => 4, 'ESP' => 4, 'ANG' => 4, 'ECM' => 2, 'EPS' => 2, 'FHR' => 1,
            'FR' => 6, 'HG' => 4, 'INFO' => 1, 'MATHS' => 3, 'PC' => 2, 'PHILO' => 2,
            'SVT' => 2,
        ]],
        ['niveau' => '1ere', 'serie' => 'C', 'heures' => [
            'ANG' => 3, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1, 'FR' => 5, 'HG' => 4,
            'INFO' => 1, 'MATHS' => 7, 'PC' => 5, 'PHILO' => 2, 'SVT' => 2,
        ]],
        ['niveau' => 'Tle', 'serie' => 'D', 'heures' => [
            'ANG' => 2, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1, 'FR' => 2, 'HG' => 4,
            'INFO' => 1, 'MATHS' => 6, 'PC' => 5, 'PHILO' => 3, 'SVT' => 6,
        ]],
        ['niveau' => 'Tle', 'serie' => 'A4', 'heures' => [
            'ALL' => 3, 'ESP' => 3, 'ANG' => 3, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1,
            'FR' => 4, 'HG' => 4, 'INFO' => 1, 'MATHS' => 2, 'MUSIQ' => 1, 'PC' => 2,
            'PHILO' => 7, 'SVT' => 2,
        ]],
        ['niveau' => 'Tle', 'serie' => 'C', 'heures' => [
            'ANG' => 2, 'ECM' => 1, 'EPS' => 2, 'FHR' => 1, 'FR' => 2, 'HG' => 4,
            'INFO' => 1, 'MATHS' => 8, 'MUSIQ' => 1, 'PC' => 6, 'PHILO' => 3, 'SVT' => 2,
        ]],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NiveauRepository $niveauRepo,
        private readonly MatiereRepository $matiereRepo,
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

        $mnRepo = $this->em->getRepository(MatiereNiveau::class);

        $created   = 0;
        $updated   = 0;
        $unchanged = 0;
        $errors    = [];

        foreach (self::GRILLE as $ligne) {
            $label  = trim($ligne['niveau'].' '.($ligne['serie'] ?? ''));
            $niveau = $this->niveauRepo->findOneBy(['nom' => $ligne['niveau'], 'serie' => $ligne['serie']]);

            if (!$niveau) {
                $errors[] = sprintf('Niveau introuvable : %s', $label);
                continue;
            }

            foreach ($ligne['heures'] as $code => $heures) {
                $matiere = $this->matiereRepo->findOneBy(['code' => $code]);

                if (!$matiere) {
                    $errors[] = sprintf('Matière introuvable : %s (%s)', $code, $label);
                    continue;
                }

                $mn = $mnRepo->findOneBy(['matiere' => $matiere, 'niveau' => $niveau]);
                if (!$mn) {
                    $mn = new MatiereNiveau();
                    $mn->setMatiere($matiere);
                    $mn->setNiveau($niveau);
                    $this->em->persist($mn);
                    $created++;
                }

                $heuresStr = number_format($heures, 2, '.', '');
                if ($mn->getHeuresParSemaine() !== $heuresStr) {
                    if ($mn->getId() !== null) {
                        $io->writeln(sprintf(
                            '  ~ %s · %s : %s -> %sh',
                            $label,
                            $code,
                            $mn->getHeuresParSemaine(),
                            $heuresStr,
                        ));
                        $updated++;
                    }
                    $mn->setHeuresParSemaine($heuresStr);
                } else {
                    $unchanged++;
                }
            }
        }

        if ($errors) {
            $io->error($errors);
        }

        if ($dryRun) {
            $io->note('Mode --dry-run : aucune modification enregistrée.');
        } else {
            $this->em->flush();
        }

        $io->success(sprintf('%d créés, %d mis à jour, %d inchangés.', $created, $updated, $unchanged));

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
