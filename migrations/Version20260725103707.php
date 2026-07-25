<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725103707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Examens blancs : ExamenBlancNote lié à Matiere (niveau-wide) au lieu d\'Attribution, sélection des matières évaluées, appréciation sur ExamenBlancReleveMatiere.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE examen_blanc_matiere (examen_blanc_id INT NOT NULL, matiere_id INT NOT NULL, INDEX IDX_772F1865D2636B94 (examen_blanc_id), INDEX IDX_772F1865F46CD258 (matiere_id), PRIMARY KEY (examen_blanc_id, matiere_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE examen_blanc_matiere ADD CONSTRAINT FK_772F1865D2636B94 FOREIGN KEY (examen_blanc_id) REFERENCES examen_blanc (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE examen_blanc_matiere ADD CONSTRAINT FK_772F1865F46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE examen_blanc_note DROP FOREIGN KEY `FK_699DC92FEEB69F7B`');
        $this->addSql('DROP INDEX UNIQ_699DC92FD2636B94EEB69F7B5DAC5993 ON examen_blanc_note');
        $this->addSql('DROP INDEX IDX_699DC92FEEB69F7B ON examen_blanc_note');
        $this->addSql('ALTER TABLE examen_blanc_note CHANGE attribution_id matiere_id INT NOT NULL');
        $this->addSql('ALTER TABLE examen_blanc_note ADD CONSTRAINT FK_699DC92FF46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id)');
        $this->addSql('CREATE INDEX IDX_699DC92FF46CD258 ON examen_blanc_note (matiere_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_699DC92FD2636B94F46CD2585DAC5993 ON examen_blanc_note (examen_blanc_id, matiere_id, inscription_id)');
        $this->addSql('ALTER TABLE examen_blanc_releve_matiere ADD appreciation VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE examen_blanc_matiere DROP FOREIGN KEY FK_772F1865D2636B94');
        $this->addSql('ALTER TABLE examen_blanc_matiere DROP FOREIGN KEY FK_772F1865F46CD258');
        $this->addSql('DROP TABLE examen_blanc_matiere');
        $this->addSql('ALTER TABLE examen_blanc_note DROP FOREIGN KEY FK_699DC92FF46CD258');
        $this->addSql('DROP INDEX IDX_699DC92FF46CD258 ON examen_blanc_note');
        $this->addSql('DROP INDEX UNIQ_699DC92FD2636B94F46CD2585DAC5993 ON examen_blanc_note');
        $this->addSql('ALTER TABLE examen_blanc_note CHANGE matiere_id attribution_id INT NOT NULL');
        $this->addSql('ALTER TABLE examen_blanc_note ADD CONSTRAINT `FK_699DC92FEEB69F7B` FOREIGN KEY (attribution_id) REFERENCES attribution (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_699DC92FD2636B94EEB69F7B5DAC5993 ON examen_blanc_note (examen_blanc_id, attribution_id, inscription_id)');
        $this->addSql('CREATE INDEX IDX_699DC92FEEB69F7B ON examen_blanc_note (attribution_id)');
        $this->addSql('ALTER TABLE examen_blanc_releve_matiere DROP appreciation');
    }
}
