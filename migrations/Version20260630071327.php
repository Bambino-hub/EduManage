<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260630071327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déplace le coefficient de matiere vers matiere_niveau (1 coefficient par matière × niveau)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE matiere_niveau (id INT AUTO_INCREMENT NOT NULL, coefficient NUMERIC(4, 2) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, matiere_id INT NOT NULL, niveau_id INT NOT NULL, INDEX IDX_6B3CD676F46CD258 (matiere_id), INDEX IDX_6B3CD676B3E9C81 (niveau_id), UNIQUE INDEX UNIQ_matiere_niveau (matiere_id, niveau_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE matiere_niveau ADD CONSTRAINT FK_6B3CD676F46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id)');
        $this->addSql('ALTER TABLE matiere_niveau ADD CONSTRAINT FK_6B3CD676B3E9C81 FOREIGN KEY (niveau_id) REFERENCES niveau (id)');

        // Préservation des données : pour chaque matière existante, créer une ligne par niveau
        // en reprenant le coefficient actuel de la matière.
        $this->addSql('
            INSERT INTO matiere_niveau (matiere_id, niveau_id, coefficient, created_at, updated_at)
            SELECT m.id, n.id, m.coefficient, NOW(), NOW()
            FROM matiere m
            CROSS JOIN niveau n
        ');

        $this->addSql('ALTER TABLE matiere DROP coefficient');
    }

    public function down(Schema $schema): void
    {
        // Restauration : on prend la moyenne des coefficients par matière
        $this->addSql('ALTER TABLE matiere ADD coefficient NUMERIC(4, 2) NOT NULL DEFAULT \'1.00\'');
        $this->addSql('
            UPDATE matiere m
            SET coefficient = (
                SELECT ROUND(AVG(mn.coefficient), 2)
                FROM matiere_niveau mn
                WHERE mn.matiere_id = m.id
            )
            WHERE EXISTS (SELECT 1 FROM matiere_niveau mn WHERE mn.matiere_id = m.id)
        ');

        $this->addSql('ALTER TABLE matiere_niveau DROP FOREIGN KEY FK_6B3CD676F46CD258');
        $this->addSql('ALTER TABLE matiere_niveau DROP FOREIGN KEY FK_6B3CD676B3E9C81');
        $this->addSql('DROP TABLE matiere_niveau');
    }
}
