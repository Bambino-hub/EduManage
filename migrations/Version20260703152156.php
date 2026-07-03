<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703152156 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Table de jointure classe_matiere_optionnelle : matières à choix (Allemand/Espagnol...) réellement suivies par chaque classe";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE classe_matiere_optionnelle (classe_id INT NOT NULL, matiere_id INT NOT NULL, INDEX IDX_71796ED08F5EA509 (classe_id), INDEX IDX_71796ED0F46CD258 (matiere_id), PRIMARY KEY (classe_id, matiere_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE classe_matiere_optionnelle ADD CONSTRAINT FK_71796ED08F5EA509 FOREIGN KEY (classe_id) REFERENCES classe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classe_matiere_optionnelle ADD CONSTRAINT FK_71796ED0F46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE classe_matiere_optionnelle DROP FOREIGN KEY FK_71796ED08F5EA509');
        $this->addSql('ALTER TABLE classe_matiere_optionnelle DROP FOREIGN KEY FK_71796ED0F46CD258');
        $this->addSql('DROP TABLE classe_matiere_optionnelle');
    }
}
