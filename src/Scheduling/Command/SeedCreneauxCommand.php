<?php

declare(strict_types=1);

namespace App\Scheduling\Command;

use App\Scheduling\Entity\Creneau;
use App\Scheduling\Enum\JourSemaine;
use App\Scheduling\Repository\CreneauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scheduling:seed-creneaux',
    description: 'Crée les créneaux horaires hebdomadaires standards (Lundi-Vendredi)',
)]
class SeedCreneauxCommand extends Command
{
    /**
     * Grille horaire hebdomadaire confirmée par l'établissement :
     * 1ère heure à 7h00, périodes de 55min, pause de 25min après la 3ème heure,
     * reprise de l'après-midi à 15h00 sans pause.
     *
     * Lundi et Jeudi vont jusqu'à la 8ème heure (utilisée par le lycée uniquement —
     * le collège ne dépasse jamais la 7ème heure, règle appliquée par le générateur,
     * pas par cette grille). Mardi et Mercredi s'arrêtent après la 5ème heure puis
     * un bloc réservé tout-établissement (DEVOIR / PLEINAIRE) occupe la fin d'après-midi,
     * non attribuable à une matière. Vendredi s'arrête à la 7ème heure pour tout le monde.
     *
     * @var list<array{jour: JourSemaine, ordre: int, debut: string, fin: string, reserve: ?string}>
     */
    private const array GRILLE = [
        // LUNDI — collège 1-7, lycée 1-8
        ['jour' => JourSemaine::LUNDI, 'ordre' => 1, 'debut' => '07:00', 'fin' => '07:55', 'reserve' => null],
        ['jour' => JourSemaine::LUNDI, 'ordre' => 2, 'debut' => '07:55', 'fin' => '08:50', 'reserve' => null],
        ['jour' => JourSemaine::LUNDI, 'ordre' => 3, 'debut' => '08:50', 'fin' => '09:45', 'reserve' => null],
        ['jour' => JourSemaine::LUNDI, 'ordre' => 4, 'debut' => '10:10', 'fin' => '11:05', 'reserve' => null],
        ['jour' => JourSemaine::LUNDI, 'ordre' => 5, 'debut' => '11:05', 'fin' => '12:00', 'reserve' => null],
        ['jour' => JourSemaine::LUNDI, 'ordre' => 6, 'debut' => '15:00', 'fin' => '15:55', 'reserve' => null],
        ['jour' => JourSemaine::LUNDI, 'ordre' => 7, 'debut' => '15:55', 'fin' => '16:50', 'reserve' => null],
        ['jour' => JourSemaine::LUNDI, 'ordre' => 8, 'debut' => '16:50', 'fin' => '17:45', 'reserve' => null],

        // MARDI — 1-5 puis bloc DEVOIR
        ['jour' => JourSemaine::MARDI, 'ordre' => 1, 'debut' => '07:00', 'fin' => '07:55', 'reserve' => null],
        ['jour' => JourSemaine::MARDI, 'ordre' => 2, 'debut' => '07:55', 'fin' => '08:50', 'reserve' => null],
        ['jour' => JourSemaine::MARDI, 'ordre' => 3, 'debut' => '08:50', 'fin' => '09:45', 'reserve' => null],
        ['jour' => JourSemaine::MARDI, 'ordre' => 4, 'debut' => '10:10', 'fin' => '11:05', 'reserve' => null],
        ['jour' => JourSemaine::MARDI, 'ordre' => 5, 'debut' => '11:05', 'fin' => '12:00', 'reserve' => null],
        ['jour' => JourSemaine::MARDI, 'ordre' => 6, 'debut' => '15:00', 'fin' => '16:50', 'reserve' => 'DEVOIR'],

        // MERCREDI — 1-5 puis bloc PLEINAIRE
        ['jour' => JourSemaine::MERCREDI, 'ordre' => 1, 'debut' => '07:00', 'fin' => '07:55', 'reserve' => null],
        ['jour' => JourSemaine::MERCREDI, 'ordre' => 2, 'debut' => '07:55', 'fin' => '08:50', 'reserve' => null],
        ['jour' => JourSemaine::MERCREDI, 'ordre' => 3, 'debut' => '08:50', 'fin' => '09:45', 'reserve' => null],
        ['jour' => JourSemaine::MERCREDI, 'ordre' => 4, 'debut' => '10:10', 'fin' => '11:05', 'reserve' => null],
        ['jour' => JourSemaine::MERCREDI, 'ordre' => 5, 'debut' => '11:05', 'fin' => '12:00', 'reserve' => null],
        ['jour' => JourSemaine::MERCREDI, 'ordre' => 6, 'debut' => '15:00', 'fin' => '16:50', 'reserve' => 'PLEINAIRE'],

        // JEUDI — collège 1-7, lycée 1-8 (identique à Lundi)
        ['jour' => JourSemaine::JEUDI, 'ordre' => 1, 'debut' => '07:00', 'fin' => '07:55', 'reserve' => null],
        ['jour' => JourSemaine::JEUDI, 'ordre' => 2, 'debut' => '07:55', 'fin' => '08:50', 'reserve' => null],
        ['jour' => JourSemaine::JEUDI, 'ordre' => 3, 'debut' => '08:50', 'fin' => '09:45', 'reserve' => null],
        ['jour' => JourSemaine::JEUDI, 'ordre' => 4, 'debut' => '10:10', 'fin' => '11:05', 'reserve' => null],
        ['jour' => JourSemaine::JEUDI, 'ordre' => 5, 'debut' => '11:05', 'fin' => '12:00', 'reserve' => null],
        ['jour' => JourSemaine::JEUDI, 'ordre' => 6, 'debut' => '15:00', 'fin' => '15:55', 'reserve' => null],
        ['jour' => JourSemaine::JEUDI, 'ordre' => 7, 'debut' => '15:55', 'fin' => '16:50', 'reserve' => null],
        ['jour' => JourSemaine::JEUDI, 'ordre' => 8, 'debut' => '16:50', 'fin' => '17:45', 'reserve' => null],

        // VENDREDI — tout le monde 1-7, jamais de 8ème heure
        ['jour' => JourSemaine::VENDREDI, 'ordre' => 1, 'debut' => '07:00', 'fin' => '07:55', 'reserve' => null],
        ['jour' => JourSemaine::VENDREDI, 'ordre' => 2, 'debut' => '07:55', 'fin' => '08:50', 'reserve' => null],
        ['jour' => JourSemaine::VENDREDI, 'ordre' => 3, 'debut' => '08:50', 'fin' => '09:45', 'reserve' => null],
        ['jour' => JourSemaine::VENDREDI, 'ordre' => 4, 'debut' => '10:10', 'fin' => '11:05', 'reserve' => null],
        ['jour' => JourSemaine::VENDREDI, 'ordre' => 5, 'debut' => '11:05', 'fin' => '12:00', 'reserve' => null],
        ['jour' => JourSemaine::VENDREDI, 'ordre' => 6, 'debut' => '15:00', 'fin' => '15:55', 'reserve' => null],
        ['jour' => JourSemaine::VENDREDI, 'ordre' => 7, 'debut' => '15:55', 'fin' => '16:50', 'reserve' => null],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CreneauRepository $creneauRepo,
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

        $created   = 0;
        $unchanged = 0;

        foreach (self::GRILLE as $ligne) {
            $existant = $this->creneauRepo->findOneBy([
                'jourSemaine' => $ligne['jour'],
                'ordre'       => $ligne['ordre'],
            ]);

            if ($existant) {
                $unchanged++;
                continue;
            }

            $label = sprintf('%s %s-%s%s', $ligne['jour']->label(), $ligne['debut'], $ligne['fin'],
                $ligne['reserve'] ? " ({$ligne['reserve']})" : '');
            $io->writeln("  + {$label}");

            $creneau = new Creneau();
            $creneau->setJourSemaine($ligne['jour']);
            $creneau->setOrdre($ligne['ordre']);
            $creneau->setHeureDebut(new \DateTimeImmutable($ligne['debut']));
            $creneau->setHeureFin(new \DateTimeImmutable($ligne['fin']));
            $creneau->setLibelleReserve($ligne['reserve']);
            $this->em->persist($creneau);
            $created++;
        }

        if ($dryRun) {
            $io->note('Mode --dry-run : aucune modification enregistrée.');
        } else {
            $this->em->flush();
        }

        $io->success(sprintf('%d créneaux créés, %d déjà existants.', $created, $unchanged));

        return Command::SUCCESS;
    }
}
