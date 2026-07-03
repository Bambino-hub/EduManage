<?php

declare(strict_types=1);

namespace App\Academic\Command;

use App\Academic\Repository\ClasseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Déduit les matières à choix suivies par chaque classe (Classe::$matieresOptionnelles)
 * à partir des attributions déjà saisies : si une classe a un enseignant pour l'Allemand,
 * on considère qu'elle "fait" l'Allemand. N'ajoute jamais de suppression (idempotente,
 * rejouable sans risque) : un choix déjà configuré à la main n'est jamais retiré, même
 * sans attribution correspondante pour l'instant.
 */
#[AsCommand(
    name: 'app:academic:backfill-options-classe',
    description: 'Déduit les matières à choix (Allemand/Espagnol...) suivies par chaque classe depuis ses attributions existantes',
)]
class BackfillOptionsClasseCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClasseRepository $classeRepo,
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

        $ajouts = 0;

        foreach ($this->classeRepo->findByAnneeScolaireActive() as $classe) {
            $dejaChoisies = [];
            foreach ($classe->getMatieresOptionnelles() as $matiereOptionnelle) {
                $dejaChoisies[$matiereOptionnelle->getId()] = true;
            }

            foreach ($classe->getAttributions() as $attribution) {
                $matiere = $attribution->getMatiere();
                if ($matiere->getGroupeOptionnel() === null || isset($dejaChoisies[$matiere->getId()])) {
                    continue;
                }

                $io->writeln(sprintf('  + %s : %s', $classe->getNom(), $matiere->getNom()));
                $classe->getMatieresOptionnelles()->add($matiere);
                $dejaChoisies[$matiere->getId()] = true;
                $ajouts++;
            }
        }

        if ($dryRun) {
            $io->note('Mode --dry-run : aucune modification enregistrée.');
        } else {
            $this->em->flush();
        }

        $io->success(sprintf('%d choix de matière ajouté(s).', $ajouts));

        return Command::SUCCESS;
    }
}
