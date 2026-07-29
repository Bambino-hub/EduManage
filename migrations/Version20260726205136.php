<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726205136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les champs de l\'économe (nom/titre/cachet/signature) sur Etablissement, pour les reçus de paiement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE
              etablissement
            ADD
              nom_econome VARCHAR(150) DEFAULT '' NOT NULL,
            ADD
              titre_econome VARCHAR(80) DEFAULT 'Caissier(ère)' NOT NULL,
            ADD
              cachet_econome VARCHAR(255) DEFAULT NULL,
            ADD
              signature_econome VARCHAR(255) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE
              etablissement
            DROP
              nom_econome,
            DROP
              titre_econome,
            DROP
              cachet_econome,
            DROP
              signature_econome
        SQL);
    }
}
