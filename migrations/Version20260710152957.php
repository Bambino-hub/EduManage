<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710152957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE enseignant ADD type_stage VARCHAR(20) DEFAULT NULL, ADD etablissement_origine VARCHAR(150) DEFAULT NULL, ADD niveau_etudes VARCHAR(100) DEFAULT NULL, ADD date_debut_stage DATE DEFAULT NULL, ADD date_fin_stage DATE DEFAULT NULL, ADD convention_signee TINYINT NOT NULL, ADD tuteur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE enseignant ADD CONSTRAINT FK_81A72FA186EC68D8 FOREIGN KEY (tuteur_id) REFERENCES enseignant (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_81A72FA186EC68D8 ON enseignant (tuteur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE enseignant DROP FOREIGN KEY FK_81A72FA186EC68D8');
        $this->addSql('DROP INDEX IDX_81A72FA186EC68D8 ON enseignant');
        $this->addSql('ALTER TABLE enseignant DROP type_stage, DROP etablissement_origine, DROP niveau_etudes, DROP date_debut_stage, DROP date_fin_stage, DROP convention_signee, DROP tuteur_id');
    }
}
