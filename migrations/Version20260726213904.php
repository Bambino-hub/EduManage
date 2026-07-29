<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726213904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Salaires du personnel : paiement_salaire, ligne_paiement_salaire et compteur de numérotation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ligne_paiement_salaire (
              id INT AUTO_INCREMENT NOT NULL,
              libelle VARCHAR(150) NOT NULL,
              montant INT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              paiement_salaire_id INT NOT NULL,
              INDEX IDX_A244AD5FB0B7A971 (paiement_salaire_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE paiement_salaire (
              id INT AUTO_INCREMENT NOT NULL,
              numero INT NOT NULL,
              date_emission DATE NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              enseignant_id INT NOT NULL,
              UNIQUE INDEX UNIQ_26F10DE0F55AE19E (numero),
              INDEX IDX_26F10DE0E455FCC0 (enseignant_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ligne_paiement_salaire
            ADD
              CONSTRAINT FK_A244AD5FB0B7A971 FOREIGN KEY (paiement_salaire_id) REFERENCES paiement_salaire (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              paiement_salaire
            ADD
              CONSTRAINT FK_26F10DE0E455FCC0 FOREIGN KEY (enseignant_id) REFERENCES enseignant (id)
        SQL);

        // Table technique hors mapping Doctrine (compteur atomique pour NumeroPaiementSalaireGenerator).
        $this->addSql(<<<'SQL'
            CREATE TABLE caisse_compteur_paiement_salaire (
              id INT NOT NULL,
              dernier_numero INT NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('INSERT INTO caisse_compteur_paiement_salaire (id, dernier_numero) VALUES (1, 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_paiement_salaire DROP FOREIGN KEY FK_A244AD5FB0B7A971');
        $this->addSql('ALTER TABLE paiement_salaire DROP FOREIGN KEY FK_26F10DE0E455FCC0');
        $this->addSql('DROP TABLE ligne_paiement_salaire');
        $this->addSql('DROP TABLE paiement_salaire');
        $this->addSql('DROP TABLE caisse_compteur_paiement_salaire');
    }
}
