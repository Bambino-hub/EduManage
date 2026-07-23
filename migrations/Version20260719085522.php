<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260719085522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les poids devoirs/composition sur annee_scolaire (phase C, moyennes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE annee_scolaire ADD poids_devoirs NUMERIC(4, 2) DEFAULT \'0.50\' NOT NULL, ADD poids_composition NUMERIC(4, 2) DEFAULT \'0.50\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE annee_scolaire DROP poids_devoirs, DROP poids_composition');
    }
}
