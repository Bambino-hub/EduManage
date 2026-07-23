<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260718210626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les tables eleve et inscription (gestion des élèves, phase A).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eleve (id INT AUTO_INCREMENT NOT NULL, matricule VARCHAR(20) NOT NULL, nom VARCHAR(80) NOT NULL, prenom VARCHAR(80) NOT NULL, sexe VARCHAR(1) DEFAULT NULL, date_naissance DATE DEFAULT NULL, lieu_naissance VARCHAR(100) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, nom_tuteur VARCHAR(100) NOT NULL, telephone_tuteur VARCHAR(20) NOT NULL, email_tuteur VARCHAR(120) DEFAULT NULL, lien_tuteur VARCHAR(30) DEFAULT NULL, statut VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_ECA105F712B2DC9C (matricule), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE inscription (id INT AUTO_INCREMENT NOT NULL, date_inscription DATE NOT NULL, date_fin DATE DEFAULT NULL, motif_fin VARCHAR(20) DEFAULT NULL, redoublant TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, eleve_id INT NOT NULL, classe_id INT NOT NULL, INDEX IDX_5E90F6D6A6CC7B2 (eleve_id), INDEX IDX_5E90F6D68F5EA509 (classe_id), UNIQUE INDEX UNIQ_5E90F6D6A6CC7B28F5EA509 (eleve_id, classe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE inscription ADD CONSTRAINT FK_5E90F6D6A6CC7B2 FOREIGN KEY (eleve_id) REFERENCES eleve (id)');
        $this->addSql('ALTER TABLE inscription ADD CONSTRAINT FK_5E90F6D68F5EA509 FOREIGN KEY (classe_id) REFERENCES classe (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inscription DROP FOREIGN KEY FK_5E90F6D6A6CC7B2');
        $this->addSql('ALTER TABLE inscription DROP FOREIGN KEY FK_5E90F6D68F5EA509');
        $this->addSql('DROP TABLE eleve');
        $this->addSql('DROP TABLE inscription');
    }
}
