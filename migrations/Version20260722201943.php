<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'inscription porte désormais un niveau indépendant de la classe : un élève peut être
 * inscrit à un niveau avant qu'une classe précise ne lui soit affectée (individuellement
 * ou via l'écran d'affectation en lot). Les inscriptions existantes sont toutes déjà
 * affectées à une classe : on backfill niveau_id depuis classe.niveau_id avant de le
 * rendre obligatoire.
 */
final class Version20260722201943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inscription : classe devient facultative, ajout du niveau (indépendant de la classe)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inscription ADD niveau_id INT DEFAULT NULL, CHANGE classe_id classe_id INT DEFAULT NULL');
        $this->addSql('UPDATE inscription i JOIN classe c ON c.id = i.classe_id SET i.niveau_id = c.niveau_id');
        $this->addSql('ALTER TABLE inscription CHANGE niveau_id niveau_id INT NOT NULL');
        $this->addSql('ALTER TABLE inscription ADD CONSTRAINT FK_5E90F6D6B3E9C81 FOREIGN KEY (niveau_id) REFERENCES niveau (id)');
        $this->addSql('CREATE INDEX IDX_5E90F6D6B3E9C81 ON inscription (niveau_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inscription DROP FOREIGN KEY FK_5E90F6D6B3E9C81');
        $this->addSql('DROP INDEX IDX_5E90F6D6B3E9C81 ON inscription');
        $this->addSql('ALTER TABLE inscription DROP niveau_id, CHANGE classe_id classe_id INT NOT NULL');
    }
}
