<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724121611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signature/cachet automatiques sur les bulletins : table etablissement (chef d\'établissement) et enseignant.signature (titulaire de classe).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE etablissement (id INT AUTO_INCREMENT NOT NULL, nom_chef_etablissement VARCHAR(150) NOT NULL, titre_chef_etablissement VARCHAR(80) NOT NULL, cachet VARCHAR(255) DEFAULT NULL, signature_chef_etablissement VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE enseignant ADD signature VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE etablissement');
        $this->addSql('ALTER TABLE enseignant DROP signature');
    }
}
