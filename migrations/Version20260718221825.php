<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260718221825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les tables trimestre, evaluation et note (saisie des notes, phase B).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE evaluation (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, titre VARCHAR(100) NOT NULL, date DATE NOT NULL, coefficient NUMERIC(4, 2) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, attribution_id INT NOT NULL, trimestre_id INT NOT NULL, INDEX IDX_1323A575EEB69F7B (attribution_id), INDEX IDX_1323A575B9DB5D9D (trimestre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE note (id INT AUTO_INCREMENT NOT NULL, valeur NUMERIC(4, 2) DEFAULT NULL, absent TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, evaluation_id INT NOT NULL, eleve_id INT NOT NULL, INDEX IDX_CFBDFA14456C5646 (evaluation_id), INDEX IDX_CFBDFA14A6CC7B2 (eleve_id), UNIQUE INDEX UNIQ_CFBDFA14456C5646A6CC7B2 (evaluation_id, eleve_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trimestre (id INT AUTO_INCREMENT NOT NULL, numero INT NOT NULL, libelle VARCHAR(30) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, annee_scolaire_id INT NOT NULL, INDEX IDX_5406BC489331C741 (annee_scolaire_id), UNIQUE INDEX UNIQ_5406BC489331C741F55AE19E (annee_scolaire_id, numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575EEB69F7B FOREIGN KEY (attribution_id) REFERENCES attribution (id)');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575B9DB5D9D FOREIGN KEY (trimestre_id) REFERENCES trimestre (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14456C5646 FOREIGN KEY (evaluation_id) REFERENCES evaluation (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14A6CC7B2 FOREIGN KEY (eleve_id) REFERENCES eleve (id)');
        $this->addSql('ALTER TABLE trimestre ADD CONSTRAINT FK_5406BC489331C741 FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A575EEB69F7B');
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A575B9DB5D9D');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14456C5646');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14A6CC7B2');
        $this->addSql('ALTER TABLE trimestre DROP FOREIGN KEY FK_5406BC489331C741');
        $this->addSql('DROP TABLE evaluation');
        $this->addSql('DROP TABLE note');
        $this->addSql('DROP TABLE trimestre');
    }
}
