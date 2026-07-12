<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712152429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute RegroupementSurveillance : classes qui partagent physiquement la même "
            ."salle pendant les examens et doivent recevoir le(s) même(s) surveillant(s).";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE regroupement_surveillance (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(100) NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE regroupement_surveillance_classes (
              regroupement_surveillance_id INT NOT NULL,
              classe_id INT NOT NULL,
              INDEX IDX_114951504F439E4C (regroupement_surveillance_id),
              INDEX IDX_114951508F5EA509 (classe_id),
              PRIMARY KEY (
                regroupement_surveillance_id, classe_id
              )
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              regroupement_surveillance_classes
            ADD
              CONSTRAINT FK_114951504F439E4C FOREIGN KEY (regroupement_surveillance_id) REFERENCES regroupement_surveillance (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              regroupement_surveillance_classes
            ADD
              CONSTRAINT FK_114951508F5EA509 FOREIGN KEY (classe_id) REFERENCES classe (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE regroupement_surveillance_classes DROP FOREIGN KEY FK_114951504F439E4C');
        $this->addSql('ALTER TABLE regroupement_surveillance_classes DROP FOREIGN KEY FK_114951508F5EA509');
        $this->addSql('DROP TABLE regroupement_surveillance');
        $this->addSql('DROP TABLE regroupement_surveillance_classes');
    }
}
