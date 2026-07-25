<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725091150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le module Examens Blancs (session, notes, relevés) et Matiere::eps.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE examen_blanc (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(100) NOT NULL, date_debut DATE DEFAULT NULL, date_fin DATE DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, annee_scolaire_id INT NOT NULL, INDEX IDX_5644FF739331C741 (annee_scolaire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE examen_blanc_niveau (examen_blanc_id INT NOT NULL, niveau_id INT NOT NULL, INDEX IDX_C8BDDB65D2636B94 (examen_blanc_id), INDEX IDX_C8BDDB65B3E9C81 (niveau_id), PRIMARY KEY (examen_blanc_id, niveau_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE examen_blanc_note (id INT AUTO_INCREMENT NOT NULL, valeur NUMERIC(4, 2) DEFAULT NULL, absent TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, examen_blanc_id INT NOT NULL, attribution_id INT NOT NULL, inscription_id INT NOT NULL, INDEX IDX_699DC92FD2636B94 (examen_blanc_id), INDEX IDX_699DC92FEEB69F7B (attribution_id), INDEX IDX_699DC92F5DAC5993 (inscription_id), UNIQUE INDEX UNIQ_699DC92FD2636B94EEB69F7B5DAC5993 (examen_blanc_id, attribution_id, inscription_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE examen_blanc_releve (id INT AUTO_INCREMENT NOT NULL, moyenne_generale NUMERIC(4, 2) DEFAULT NULL, rang_niveau INT DEFAULT NULL, effectif_niveau INT NOT NULL, moyenne_niveau_faible NUMERIC(4, 2) DEFAULT NULL, moyenne_niveau_forte NUMERIC(4, 2) DEFAULT NULL, moyenne_niveau_generale NUMERIC(4, 2) DEFAULT NULL, moyenne_litteraire NUMERIC(4, 2) DEFAULT NULL, moyenne_scientifique NUMERIC(4, 2) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, examen_blanc_id INT NOT NULL, eleve_id INT NOT NULL, niveau_id INT NOT NULL, classe_id INT NOT NULL, INDEX IDX_5EC9D78DD2636B94 (examen_blanc_id), INDEX IDX_5EC9D78DA6CC7B2 (eleve_id), INDEX IDX_5EC9D78DB3E9C81 (niveau_id), INDEX IDX_5EC9D78D8F5EA509 (classe_id), UNIQUE INDEX UNIQ_5EC9D78DD2636B94A6CC7B2 (examen_blanc_id, eleve_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE examen_blanc_releve_matiere (id INT AUTO_INCREMENT NOT NULL, coefficient NUMERIC(4, 2) NOT NULL, note NUMERIC(4, 2) DEFAULT NULL, enseignant_nom VARCHAR(150) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, releve_id INT NOT NULL, matiere_id INT NOT NULL, INDEX IDX_F3C225215712E726 (releve_id), INDEX IDX_F3C22521F46CD258 (matiere_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE examen_blanc ADD CONSTRAINT FK_5644FF739331C741 FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire (id)');
        $this->addSql('ALTER TABLE examen_blanc_niveau ADD CONSTRAINT FK_C8BDDB65D2636B94 FOREIGN KEY (examen_blanc_id) REFERENCES examen_blanc (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE examen_blanc_niveau ADD CONSTRAINT FK_C8BDDB65B3E9C81 FOREIGN KEY (niveau_id) REFERENCES niveau (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE examen_blanc_note ADD CONSTRAINT FK_699DC92FD2636B94 FOREIGN KEY (examen_blanc_id) REFERENCES examen_blanc (id)');
        $this->addSql('ALTER TABLE examen_blanc_note ADD CONSTRAINT FK_699DC92FEEB69F7B FOREIGN KEY (attribution_id) REFERENCES attribution (id)');
        $this->addSql('ALTER TABLE examen_blanc_note ADD CONSTRAINT FK_699DC92F5DAC5993 FOREIGN KEY (inscription_id) REFERENCES inscription (id)');
        $this->addSql('ALTER TABLE examen_blanc_releve ADD CONSTRAINT FK_5EC9D78DD2636B94 FOREIGN KEY (examen_blanc_id) REFERENCES examen_blanc (id)');
        $this->addSql('ALTER TABLE examen_blanc_releve ADD CONSTRAINT FK_5EC9D78DA6CC7B2 FOREIGN KEY (eleve_id) REFERENCES eleve (id)');
        $this->addSql('ALTER TABLE examen_blanc_releve ADD CONSTRAINT FK_5EC9D78DB3E9C81 FOREIGN KEY (niveau_id) REFERENCES niveau (id)');
        $this->addSql('ALTER TABLE examen_blanc_releve ADD CONSTRAINT FK_5EC9D78D8F5EA509 FOREIGN KEY (classe_id) REFERENCES classe (id)');
        $this->addSql('ALTER TABLE examen_blanc_releve_matiere ADD CONSTRAINT FK_F3C225215712E726 FOREIGN KEY (releve_id) REFERENCES examen_blanc_releve (id)');
        $this->addSql('ALTER TABLE examen_blanc_releve_matiere ADD CONSTRAINT FK_F3C22521F46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id)');
        $this->addSql('ALTER TABLE matiere ADD eps TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE examen_blanc DROP FOREIGN KEY FK_5644FF739331C741');
        $this->addSql('ALTER TABLE examen_blanc_niveau DROP FOREIGN KEY FK_C8BDDB65D2636B94');
        $this->addSql('ALTER TABLE examen_blanc_niveau DROP FOREIGN KEY FK_C8BDDB65B3E9C81');
        $this->addSql('ALTER TABLE examen_blanc_note DROP FOREIGN KEY FK_699DC92FD2636B94');
        $this->addSql('ALTER TABLE examen_blanc_note DROP FOREIGN KEY FK_699DC92FEEB69F7B');
        $this->addSql('ALTER TABLE examen_blanc_note DROP FOREIGN KEY FK_699DC92F5DAC5993');
        $this->addSql('ALTER TABLE examen_blanc_releve DROP FOREIGN KEY FK_5EC9D78DD2636B94');
        $this->addSql('ALTER TABLE examen_blanc_releve DROP FOREIGN KEY FK_5EC9D78DA6CC7B2');
        $this->addSql('ALTER TABLE examen_blanc_releve DROP FOREIGN KEY FK_5EC9D78DB3E9C81');
        $this->addSql('ALTER TABLE examen_blanc_releve DROP FOREIGN KEY FK_5EC9D78D8F5EA509');
        $this->addSql('ALTER TABLE examen_blanc_releve_matiere DROP FOREIGN KEY FK_F3C225215712E726');
        $this->addSql('ALTER TABLE examen_blanc_releve_matiere DROP FOREIGN KEY FK_F3C22521F46CD258');
        $this->addSql('DROP TABLE examen_blanc');
        $this->addSql('DROP TABLE examen_blanc_niveau');
        $this->addSql('DROP TABLE examen_blanc_note');
        $this->addSql('DROP TABLE examen_blanc_releve');
        $this->addSql('DROP TABLE examen_blanc_releve_matiere');
        $this->addSql('ALTER TABLE matiere DROP eps');
    }
}
