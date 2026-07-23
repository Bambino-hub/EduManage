<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photo de l'élève (bulletin) + titulaire (professeur principal) de la classe, imprimé sous
 * la case "Appréciation du professeur principal" du bulletin.
 */
final class Version20260723090523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute Eleve::photo et Classe::titulaire (professeur principal, pour le bulletin)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE classe ADD titulaire_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE classe ADD CONSTRAINT FK_8F87BF96A10273AA FOREIGN KEY (titulaire_id) REFERENCES enseignant (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8F87BF96A10273AA ON classe (titulaire_id)');
        $this->addSql('ALTER TABLE eleve ADD photo VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE classe DROP FOREIGN KEY FK_8F87BF96A10273AA');
        $this->addSql('DROP INDEX IDX_8F87BF96A10273AA ON classe');
        $this->addSql('ALTER TABLE classe DROP titulaire_id');
        $this->addSql('ALTER TABLE eleve DROP photo');
    }
}
