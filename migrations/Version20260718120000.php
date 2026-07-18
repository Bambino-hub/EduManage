<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Amorce le tout premier compte administrateur à partir des variables d'environnement
 * ADMIN_BOOTSTRAP_EMAIL / ADMIN_BOOTSTRAP_PASSWORD (ignoré si absentes). Nécessaire car
 * l'hébergement (Render Free) ne fournit pas de shell interactif pour lancer
 * app:admin:create manuellement.
 */
final class Version20260718120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Crée le compte administrateur initial depuis ADMIN_BOOTSTRAP_EMAIL/ADMIN_BOOTSTRAP_PASSWORD si définies.";
    }

    public function up(Schema $schema): void
    {
        $email = $this->readEnv('ADMIN_BOOTSTRAP_EMAIL');
        $password = $this->readEnv('ADMIN_BOOTSTRAP_PASSWORD');

        if ($email === null || $password === null) {
            return;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $this->addSql(
            'INSERT INTO utilisateur (email, roles, password, actif, doit_changer_mot_de_passe, created_at, updated_at) VALUES (?, ?, ?, 1, 1, NOW(), NOW())',
            [$email, '["ROLE_ADMIN"]', $hash]
        );
    }

    public function down(Schema $schema): void
    {
        $email = $this->readEnv('ADMIN_BOOTSTRAP_EMAIL');

        if ($email !== null) {
            $this->addSql('DELETE FROM utilisateur WHERE email = ?', [$email]);
        }
    }

    private function readEnv(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
