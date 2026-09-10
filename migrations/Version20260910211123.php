<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la table emploi_du_temps_version : historique des emplois du temps générés
 * (instantané JSON des séances d'une année, restaurable — voir EmploiDuTempsHistorique).
 */
final class Version20260910211123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l\'historique des emplois du temps (table emploi_du_temps_version)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE emploi_du_temps_version (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(255) NOT NULL, origine VARCHAR(20) NOT NULL, nb_seances INT NOT NULL, heures_placees INT NOT NULL, heures_non_placees INT DEFAULT NULL, donnees JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, annee_scolaire_id INT NOT NULL, INDEX IDX_58D325699331C741 (annee_scolaire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE emploi_du_temps_version ADD CONSTRAINT FK_58D325699331C741 FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE emploi_du_temps_version DROP FOREIGN KEY FK_58D325699331C741');
        $this->addSql('DROP TABLE emploi_du_temps_version');
    }
}
