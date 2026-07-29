<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726200627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Économat : tarifs par niveau, reçus multi-lignes et compteur de numérotation.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE ligne_recu (
              id INT AUTO_INCREMENT NOT NULL,
              libelle VARCHAR(150) NOT NULL,
              montant INT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              recu_id INT NOT NULL,
              INDEX IDX_DAD93F95A5D1C184 (recu_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE recu (
              id INT AUTO_INCREMENT NOT NULL,
              numero INT NOT NULL,
              date_emission DATE NOT NULL,
              nom_payeur VARCHAR(100) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              eleve_id INT NOT NULL,
              inscription_id INT NOT NULL,
              annee_scolaire_id INT NOT NULL,
              UNIQUE INDEX UNIQ_C0D10317F55AE19E (numero),
              INDEX IDX_C0D10317A6CC7B2 (eleve_id),
              INDEX IDX_C0D103175DAC5993 (inscription_id),
              INDEX IDX_C0D103179331C741 (annee_scolaire_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE tarif (
              id INT AUTO_INCREMENT NOT NULL,
              montant_annuel INT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              niveau_id INT NOT NULL,
              annee_scolaire_id INT NOT NULL,
              INDEX IDX_E7189C9B3E9C81 (niveau_id),
              INDEX IDX_E7189C99331C741 (annee_scolaire_id),
              UNIQUE INDEX UNIQ_E7189C9B3E9C819331C741 (niveau_id, annee_scolaire_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ligne_recu
            ADD
              CONSTRAINT FK_DAD93F95A5D1C184 FOREIGN KEY (recu_id) REFERENCES recu (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              recu
            ADD
              CONSTRAINT FK_C0D10317A6CC7B2 FOREIGN KEY (eleve_id) REFERENCES eleve (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              recu
            ADD
              CONSTRAINT FK_C0D103175DAC5993 FOREIGN KEY (inscription_id) REFERENCES inscription (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              recu
            ADD
              CONSTRAINT FK_C0D103179331C741 FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              tarif
            ADD
              CONSTRAINT FK_E7189C9B3E9C81 FOREIGN KEY (niveau_id) REFERENCES niveau (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              tarif
            ADD
              CONSTRAINT FK_E7189C99331C741 FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire (id)
        SQL);

        // Table technique hors mapping Doctrine (compteur atomique pour NumeroRecuGenerator) —
        // une seule ligne fixe (id=1), incrémentée via INSERT ... ON DUPLICATE KEY UPDATE.
        $this->addSql(<<<'SQL'
            CREATE TABLE economat_compteur_recu (
              id INT NOT NULL,
              dernier_numero INT NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('INSERT INTO economat_compteur_recu (id, dernier_numero) VALUES (1, 0)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ligne_recu DROP FOREIGN KEY FK_DAD93F95A5D1C184');
        $this->addSql('ALTER TABLE recu DROP FOREIGN KEY FK_C0D10317A6CC7B2');
        $this->addSql('ALTER TABLE recu DROP FOREIGN KEY FK_C0D103175DAC5993');
        $this->addSql('ALTER TABLE recu DROP FOREIGN KEY FK_C0D103179331C741');
        $this->addSql('ALTER TABLE tarif DROP FOREIGN KEY FK_E7189C9B3E9C81');
        $this->addSql('ALTER TABLE tarif DROP FOREIGN KEY FK_E7189C99331C741');
        $this->addSql('DROP TABLE ligne_recu');
        $this->addSql('DROP TABLE recu');
        $this->addSql('DROP TABLE tarif');
        $this->addSql('DROP TABLE economat_compteur_recu');
    }
}
