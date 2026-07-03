<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702093454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute sexe/matricule/poste/cycle sur enseignant (import liste du personnel)";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE enseignant ADD sexe VARCHAR(1) DEFAULT NULL, ADD matricule VARCHAR(20) DEFAULT NULL, ADD poste VARCHAR(60) DEFAULT NULL, ADD cycle VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE enseignant DROP sexe, DROP matricule, DROP poste, DROP cycle');
    }
}
