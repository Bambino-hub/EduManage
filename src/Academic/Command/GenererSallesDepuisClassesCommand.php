<?php

declare(strict_types=1);

namespace App\Academic\Command;

use App\Academic\Entity\Salle;
use App\Academic\Enum\TypeSalle;
use App\Academic\Repository\ClasseRepository;
use App\Academic\Repository\SalleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Chaque classe a sa propre salle attitrée (les élèves restent en place, les
 * enseignants circulent) : cette commande crée une salle standard par classe
 * de l'année active, du même nom, plutôt que de les ressaisir à la main.
 */
#[AsCommand(
    name: 'app:academic:generer-salles-depuis-classes',
    description: 'Crée une salle standard par classe de l\'année active (même nom, capacité = effectif max)',
)]
class GenererSallesDepuisClassesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClasseRepository $classeRepo,
        private readonly SalleRepository $salleRepo,
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

        foreach ($this->classeRepo->findByAnneeScolaireActive() as $classe) {
            if ($this->salleRepo->findOneBy(['nom' => $classe->getNom()])) {
                $unchanged++;
                continue;
            }

            $io->writeln("  + {$classe->getNom()} (capacité {$classe->getEffectifMax()})");

            $salle = new Salle();
            $salle->setNom($classe->getNom());
            $salle->setType(TypeSalle::STANDARD);
            $salle->setCapacite($classe->getEffectifMax());
            $this->em->persist($salle);
            $created++;
        }

        if ($dryRun) {
            $io->note('Mode --dry-run : aucune modification enregistrée.');
        } else {
            $this->em->flush();
        }

        $io->success(sprintf('%d salle(s) créée(s), %d déjà existante(s).', $created, $unchanged));

        return Command::SUCCESS;
    }
}
