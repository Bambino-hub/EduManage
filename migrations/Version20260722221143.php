<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la table de surcharge manuelle de Moy Interro/Moy Devoir sur la fiche de notes
 * en ligne (voir MoyenneManuelle) — prioritaire sur le calcul automatique, utile
 * notamment pour saisir directement les moyennes issues d'une fiche papier importée.
 */
final class Version20260722221143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute moyenne_manuelle (surcharge Moy Interro/Moy Devoir de la fiche de notes)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE moyenne_manuelle (
              id INT AUTO_INCREMENT NOT NULL,
              moyenne_interrogation NUMERIC(4, 2) DEFAULT NULL,
              moyenne_devoirs NUMERIC(4, 2) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              attribution_id INT NOT NULL,
              trimestre_id INT NOT NULL,
              eleve_id INT NOT NULL,
              INDEX IDX_184113A1EEB69F7B (attribution_id),
              INDEX IDX_184113A1B9DB5D9D (trimestre_id),
              INDEX IDX_184113A1A6CC7B2 (eleve_id),
              UNIQUE INDEX UNIQ_184113A1EEB69F7BB9DB5D9DA6CC7B2 (
                attribution_id, trimestre_id, eleve_id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              moyenne_manuelle
            ADD
              CONSTRAINT FK_184113A1EEB69F7B FOREIGN KEY (attribution_id) REFERENCES attribution (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              moyenne_manuelle
            ADD
              CONSTRAINT FK_184113A1B9DB5D9D FOREIGN KEY (trimestre_id) REFERENCES trimestre (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              moyenne_manuelle
            ADD
              CONSTRAINT FK_184113A1A6CC7B2 FOREIGN KEY (eleve_id) REFERENCES eleve (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moyenne_manuelle DROP FOREIGN KEY FK_184113A1EEB69F7B');
        $this->addSql('ALTER TABLE moyenne_manuelle DROP FOREIGN KEY FK_184113A1B9DB5D9D');
        $this->addSql('ALTER TABLE moyenne_manuelle DROP FOREIGN KEY FK_184113A1A6CC7B2');
        $this->addSql('DROP TABLE moyenne_manuelle');
    }
}
