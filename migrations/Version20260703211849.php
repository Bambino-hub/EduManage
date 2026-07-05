<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703211849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute RegroupementClasse (fusion de classes pour certaines matières, mêmes créneaux imposés).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE regroupement_classe (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE regroupement_classe_classes (regroupement_classe_id INT NOT NULL, classe_id INT NOT NULL, INDEX IDX_53CE606CE835FFEC (regroupement_classe_id), INDEX IDX_53CE606C8F5EA509 (classe_id), PRIMARY KEY (regroupement_classe_id, classe_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE regroupement_classe_matieres (regroupement_classe_id INT NOT NULL, matiere_id INT NOT NULL, INDEX IDX_22CC4B80E835FFEC (regroupement_classe_id), INDEX IDX_22CC4B80F46CD258 (matiere_id), PRIMARY KEY (regroupement_classe_id, matiere_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE regroupement_classe_classes ADD CONSTRAINT FK_53CE606CE835FFEC FOREIGN KEY (regroupement_classe_id) REFERENCES regroupement_classe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE regroupement_classe_classes ADD CONSTRAINT FK_53CE606C8F5EA509 FOREIGN KEY (classe_id) REFERENCES classe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE regroupement_classe_matieres ADD CONSTRAINT FK_22CC4B80E835FFEC FOREIGN KEY (regroupement_classe_id) REFERENCES regroupement_classe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE regroupement_classe_matieres ADD CONSTRAINT FK_22CC4B80F46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE regroupement_classe_classes DROP FOREIGN KEY FK_53CE606CE835FFEC');
        $this->addSql('ALTER TABLE regroupement_classe_classes DROP FOREIGN KEY FK_53CE606C8F5EA509');
        $this->addSql('ALTER TABLE regroupement_classe_matieres DROP FOREIGN KEY FK_22CC4B80E835FFEC');
        $this->addSql('ALTER TABLE regroupement_classe_matieres DROP FOREIGN KEY FK_22CC4B80F46CD258');
        $this->addSql('DROP TABLE regroupement_classe');
        $this->addSql('DROP TABLE regroupement_classe_classes');
        $this->addSql('DROP TABLE regroupement_classe_matieres');
    }
}
