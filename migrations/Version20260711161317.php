<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711161317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute le module Examens (Examen, examen_niveau, Surveillance) et le champ "
            ."domaine sur Matiere (priorité scientifique pour la génération auto du tableau "
            ."de surveillance).";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE examen (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, heure_debut TIME NOT NULL, heure_fin TIME NOT NULL, nombre_surveillants_par_classe INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, matiere_id INT NOT NULL, annee_scolaire_id INT NOT NULL, INDEX IDX_514C8FECF46CD258 (matiere_id), INDEX IDX_514C8FEC9331C741 (annee_scolaire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE examen_niveau (examen_id INT NOT NULL, niveau_id INT NOT NULL, INDEX IDX_77B8A3095C8659A (examen_id), INDEX IDX_77B8A309B3E9C81 (niveau_id), PRIMARY KEY (examen_id, niveau_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE surveillance (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, examen_id INT NOT NULL, classe_id INT NOT NULL, enseignant_id INT NOT NULL, INDEX IDX_C17BAD5B5C8659A (examen_id), INDEX IDX_C17BAD5B8F5EA509 (classe_id), INDEX IDX_C17BAD5BE455FCC0 (enseignant_id), UNIQUE INDEX UNIQ_C17BAD5B5C8659A8F5EA509E455FCC0 (examen_id, classe_id, enseignant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE examen ADD CONSTRAINT FK_514C8FECF46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id)');
        $this->addSql('ALTER TABLE examen ADD CONSTRAINT FK_514C8FEC9331C741 FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire (id)');
        $this->addSql('ALTER TABLE examen_niveau ADD CONSTRAINT FK_77B8A3095C8659A FOREIGN KEY (examen_id) REFERENCES examen (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE examen_niveau ADD CONSTRAINT FK_77B8A309B3E9C81 FOREIGN KEY (niveau_id) REFERENCES niveau (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE surveillance ADD CONSTRAINT FK_C17BAD5B5C8659A FOREIGN KEY (examen_id) REFERENCES examen (id)');
        $this->addSql('ALTER TABLE surveillance ADD CONSTRAINT FK_C17BAD5B8F5EA509 FOREIGN KEY (classe_id) REFERENCES classe (id)');
        $this->addSql('ALTER TABLE surveillance ADD CONSTRAINT FK_C17BAD5BE455FCC0 FOREIGN KEY (enseignant_id) REFERENCES enseignant (id)');
        $this->addSql('ALTER TABLE matiere ADD domaine VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE examen DROP FOREIGN KEY FK_514C8FECF46CD258');
        $this->addSql('ALTER TABLE examen DROP FOREIGN KEY FK_514C8FEC9331C741');
        $this->addSql('ALTER TABLE examen_niveau DROP FOREIGN KEY FK_77B8A3095C8659A');
        $this->addSql('ALTER TABLE examen_niveau DROP FOREIGN KEY FK_77B8A309B3E9C81');
        $this->addSql('ALTER TABLE surveillance DROP FOREIGN KEY FK_C17BAD5B5C8659A');
        $this->addSql('ALTER TABLE surveillance DROP FOREIGN KEY FK_C17BAD5B8F5EA509');
        $this->addSql('ALTER TABLE surveillance DROP FOREIGN KEY FK_C17BAD5BE455FCC0');
        $this->addSql('DROP TABLE examen');
        $this->addSql('DROP TABLE examen_niveau');
        $this->addSql('DROP TABLE surveillance');
        $this->addSql('ALTER TABLE matiere DROP domaine');
    }
}
