<?php

declare(strict_types=1);

namespace App\Security\Command;

use App\Security\Entity\Utilisateur;
use App\Security\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée un compte administrateur (ROLE_ADMIN) — utilisé pour amorcer le tout premier
 * compte, puisqu'il n'y a pas d'auto-inscription, mais réutilisable pour créer d'autres
 * comptes admin par la suite.
 */
#[AsCommand(
    name: 'app:admin:create',
    description: 'Crée un compte administrateur (email + mot de passe saisi de façon interactive)',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurRepo,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail du compte administrateur');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        if ($this->utilisateurRepo->findOneBy(['email' => $email])) {
            $io->error(sprintf('Un compte existe déjà avec l\'adresse "%s".', $email));
            return Command::FAILURE;
        }

        $motDePasse = $io->askHidden('Mot de passe (saisie masquée)');
        $confirmation = $io->askHidden('Confirmer le mot de passe');

        if ($motDePasse === null || strlen($motDePasse) < 8) {
            $io->error('Le mot de passe doit contenir au moins 8 caractères.');
            return Command::FAILURE;
        }

        if ($motDePasse !== $confirmation) {
            $io->error('Les deux mots de passe ne correspondent pas.');
            return Command::FAILURE;
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setEmail($email);
        $utilisateur->setRoles(['ROLE_ADMIN']);
        $utilisateur->setDoitChangerMotDePasse(false);
        $utilisateur->setPassword($this->passwordHasher->hashPassword($utilisateur, $motDePasse));

        $this->em->persist($utilisateur);
        $this->em->flush();

        $io->success(sprintf('Compte administrateur créé pour "%s".', $email));

        return Command::SUCCESS;
    }
}
