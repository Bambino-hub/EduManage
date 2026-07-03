<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702153745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Une classe ne peut avoir qu'un seul enseignant par matière (unicité matière+classe sur attribution)";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_C751ED49E455FCC0F46CD2588F5EA509 ON attribution');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C751ED49F46CD2588F5EA509 ON attribution (matiere_id, classe_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_C751ED49F46CD2588F5EA509 ON attribution');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C751ED49E455FCC0F46CD2588F5EA509 ON attribution (enseignant_id, matiere_id, classe_id)');
    }
}
