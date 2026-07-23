<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Structure de la fiche de notes en ligne (nombre d'interrogations/devoirs) fixée une
 * fois pour toutes par l'admin sur le Trimestre — appliquée automatiquement à toutes
 * les matières (voir Grading\Service\FicheNotesService::assurerColonnes()).
 */
final class Version20260722224824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute Trimestre::nbInterrogations/nbDevoirs (structure globale de la fiche de notes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trimestre ADD nb_interrogations INT DEFAULT NULL, ADD nb_devoirs INT DEFAULT NULL');
        $this->addSql('UPDATE trimestre SET nb_interrogations = 3, nb_devoirs = 2 WHERE nb_interrogations IS NULL');
        $this->addSql('ALTER TABLE trimestre CHANGE nb_interrogations nb_interrogations INT NOT NULL, CHANGE nb_devoirs nb_devoirs INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trimestre DROP nb_interrogations, DROP nb_devoirs');
    }
}
