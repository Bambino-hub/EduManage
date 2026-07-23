<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260719093027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute bulletin et bulletin_matiere (phase D, snapshot des bulletins)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bulletin (id INT AUTO_INCREMENT NOT NULL, moyenne_generale NUMERIC(4, 2) DEFAULT NULL, rang INT DEFAULT NULL, effectif_classe INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, eleve_id INT NOT NULL, classe_id INT NOT NULL, trimestre_id INT NOT NULL, INDEX IDX_2B7D8942A6CC7B2 (eleve_id), INDEX IDX_2B7D89428F5EA509 (classe_id), INDEX IDX_2B7D8942B9DB5D9D (trimestre_id), UNIQUE INDEX UNIQ_2B7D8942A6CC7B2B9DB5D9D (eleve_id, trimestre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bulletin_matiere (id INT AUTO_INCREMENT NOT NULL, coefficient NUMERIC(4, 2) NOT NULL, moyenne_devoirs NUMERIC(4, 2) DEFAULT NULL, moyenne_composition NUMERIC(4, 2) DEFAULT NULL, moyenne NUMERIC(4, 2) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, bulletin_id INT NOT NULL, matiere_id INT NOT NULL, INDEX IDX_93C7DC8CD1AAB236 (bulletin_id), INDEX IDX_93C7DC8CF46CD258 (matiere_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bulletin ADD CONSTRAINT FK_2B7D8942A6CC7B2 FOREIGN KEY (eleve_id) REFERENCES eleve (id)');
        $this->addSql('ALTER TABLE bulletin ADD CONSTRAINT FK_2B7D89428F5EA509 FOREIGN KEY (classe_id) REFERENCES classe (id)');
        $this->addSql('ALTER TABLE bulletin ADD CONSTRAINT FK_2B7D8942B9DB5D9D FOREIGN KEY (trimestre_id) REFERENCES trimestre (id)');
        $this->addSql('ALTER TABLE bulletin_matiere ADD CONSTRAINT FK_93C7DC8CD1AAB236 FOREIGN KEY (bulletin_id) REFERENCES bulletin (id)');
        $this->addSql('ALTER TABLE bulletin_matiere ADD CONSTRAINT FK_93C7DC8CF46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bulletin DROP FOREIGN KEY FK_2B7D8942A6CC7B2');
        $this->addSql('ALTER TABLE bulletin DROP FOREIGN KEY FK_2B7D89428F5EA509');
        $this->addSql('ALTER TABLE bulletin DROP FOREIGN KEY FK_2B7D8942B9DB5D9D');
        $this->addSql('ALTER TABLE bulletin_matiere DROP FOREIGN KEY FK_93C7DC8CD1AAB236');
        $this->addSql('ALTER TABLE bulletin_matiere DROP FOREIGN KEY FK_93C7DC8CF46CD258');
        $this->addSql('DROP TABLE bulletin');
        $this->addSql('DROP TABLE bulletin_matiere');
    }
}
