<?php

declare(strict_types=1);

namespace App\Academic\Command;

use App\Academic\Repository\AnneeScolaireRepository;
use App\Academic\Repository\SalleRepository;
use App\Scheduling\Entity\Seance;
use App\Scheduling\Repository\RegroupementClasseRepository;
use App\Scheduling\Repository\SeanceRepository;
use App\Scheduling\Service\EmploiDuTempsPersonnalisationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Réaligne la salle de chaque séance existante sur la salle standard de sa classe
 * (décision du 2026-09-16 : une classe = une salle standard, partagée par toutes ses
 * matières simultanées y compris les matières parallèles ALL/ESP ou TM/EM — plus de
 * salle spécialisée ni de salle "flottante", cf. EmploiDuTempsGenerator::resoudreSalles()).
 *
 * Ne touche JAMAIS au créneau ni au verrouillage d'une séance — uniquement sa salle.
 * Sûr à exécuter plusieurs fois (idempotent : une séance déjà correcte n'est pas
 * modifiée) et sur un emploi du temps qui a pu diverger de celui d'un autre
 * environnement (ne rejoue aucun historique, ne fait que corriger l'état actuel).
 *
 * Toujours lancer d'abord SANS --appliquer pour relire le résumé, puis relancer avec
 * --appliquer une fois vérifié.
 */
#[AsCommand(
    name: 'app:academic:corriger-salles-classes',
    description: 'Réaligne la salle de chaque séance sur la salle standard de sa classe, sans jamais toucher au créneau ni au verrouillage',
)]
final class CorrigerSallesClassesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnneeScolaireRepository $anneeRepo,
        private readonly SeanceRepository $seanceRepo,
        private readonly RegroupementClasseRepository $regroupementRepo,
        private readonly EmploiDuTempsPersonnalisationService $personnalisationService,
        private readonly SalleRepository $salleRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('appliquer', null, InputOption::VALUE_NONE, 'Enregistre les changements (sans cette option : dry-run, rien n\'est modifié)')
            ->addOption('annee', null, InputOption::VALUE_REQUIRED, 'Id de l\'année scolaire à traiter (défaut : année active)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $appliquer = (bool) $input->getOption('appliquer');

        $anneeId = $input->getOption('annee');
        $annee   = $anneeId !== null
            ? $this->anneeRepo->find((int) $anneeId)
            : $this->anneeRepo->findOneBy(['active' => true]);

        if ($annee === null) {
            $io->error('Année scolaire introuvable.');
            return Command::FAILURE;
        }

        $seances = $this->seanceRepo->findByAnneeScolaire((int) $annee->getId());
        if ($seances === []) {
            $io->note('Aucune séance pour cette année.');
            return Command::SUCCESS;
        }

        $regroupementParClasseEtMatiere = $this->regroupementRepo->indexerParClasseEtMatiere();
        $regroupementIdParSeance = static function (Seance $s) use ($regroupementParClasseEtMatiere): ?int {
            $a = $s->getAttribution();
            return $regroupementParClasseEtMatiere[$a->getClasse()->getId()][$a->getMatiere()->getId()] ?? null;
        };

        // Regroupe par unité cascade (fusion / parallèle / isolée) — même logique que
        // EmploiDuTempsPersonnalisationService::groupeDeCascade(), appliquée d'un coup à
        // toute l'année plutôt qu'à une seule séance.
        $groupes = [];
        foreach ($seances as $s) {
            $a               = $s->getAttribution();
            $regroupementId  = $regroupementIdParSeance($s);
            $groupeOptionnel = $a->getMatiere()->getGroupeOptionnel()?->value;

            if ($regroupementId !== null) {
                $cle = 'fusion:' . $regroupementId . ':' . $a->getMatiere()->getId() . ':' . $s->getCreneau()->getId();
            } elseif ($groupeOptionnel !== null) {
                $cle = 'parallele:' . $a->getClasse()->getId() . ':' . $groupeOptionnel . ':' . $s->getCreneau()->getId();
            } else {
                $cle = 'seul:' . $s->getId();
            }
            $groupes[$cle][] = $s;
        }

        $creneauAvant = [];
        $verrouilleAvant = [];
        foreach ($seances as $s) {
            $creneauAvant[$s->getId()]    = $s->getCreneau()->getId();
            $verrouilleAvant[$s->getId()] = $s->isVerrouille();
        }

        $nomParSalleId = [];
        foreach ($this->salleRepo->findBy([]) as $salle) {
            $nomParSalleId[$salle->getId()] = $salle->getNom();
        }

        $nbGroupesModifies = 0;
        $changements       = [];
        foreach ($groupes as $groupe) {
            $avant = array_map(static fn (Seance $s) => $s->getSalle()->getId(), $groupe);
            $this->personnalisationService->corrigerSalleIncoherente($groupe);
            $apres = array_map(static fn (Seance $s) => $s->getSalle()->getId(), $groupe);

            if ($avant !== $apres) {
                $nbGroupesModifies++;
                foreach ($groupe as $i => $s) {
                    if ($avant[$i] !== $apres[$i]) {
                        $changements[] = sprintf(
                            '%s / %s — %s : %s -> %s',
                            $s->getAttribution()->getClasse()->getNom(),
                            $s->getAttribution()->getMatiere()->getCode(),
                            $s->getCreneau()->getLabel(),
                            $nomParSalleId[$avant[$i]] ?? ('#' . $avant[$i]),
                            $nomParSalleId[$apres[$i]] ?? ('#' . $apres[$i]),
                        );
                    }
                }
            }
        }

        if ($appliquer) {
            $this->em->flush();
        } else {
            $this->em->clear();
        }

        // Garde-fou : jamais de créneau ni de verrouillage modifié par cette commande.
        $anomalies = 0;
        foreach ($seances as $s) {
            if (!$appliquer) {
                continue; // entités détachées après clear(), impossible à revérifier proprement en dry-run
            }
            if ($s->getCreneau()->getId() !== $creneauAvant[$s->getId()] || $s->isVerrouille() !== $verrouilleAvant[$s->getId()]) {
                $anomalies++;
            }
        }

        $io->writeln(sprintf('Groupes modifiés : %d / %d', $nbGroupesModifies, count($groupes)));
        foreach ($changements as $c) {
            $io->writeln('  ' . $c);
        }
        $io->writeln(sprintf('Anomalies créneau/verrouille (doit être 0) : %d', $anomalies));

        if ($appliquer) {
            $io->success('Corrections enregistrées.');
        } else {
            $io->note('Dry-run : rien n\'a été enregistré. Relancer avec --appliquer pour valider.');
        }

        return Command::SUCCESS;
    }
}
