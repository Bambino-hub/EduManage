<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629135501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE annee_scolaire (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(9) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_97150C2BA4D60759 (libelle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE attribution (id INT AUTO_INCREMENT NOT NULL, volume_horaire_hebdo INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, enseignant_id INT NOT NULL, matiere_id INT NOT NULL, classe_id INT NOT NULL, INDEX IDX_C751ED49E455FCC0 (enseignant_id), INDEX IDX_C751ED49F46CD258 (matiere_id), INDEX IDX_C751ED498F5EA509 (classe_id), UNIQUE INDEX UNIQ_C751ED49E455FCC0F46CD2588F5EA509 (enseignant_id, matiere_id, classe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE classe (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(30) NOT NULL, effectif_max INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, niveau_id INT NOT NULL, annee_scolaire_id INT NOT NULL, INDEX IDX_8F87BF96B3E9C81 (niveau_id), INDEX IDX_8F87BF969331C741 (annee_scolaire_id), UNIQUE INDEX UNIQ_8F87BF966C6E55B59331C741 (nom, annee_scolaire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE creneau (id INT AUTO_INCREMENT NOT NULL, jour_semaine VARCHAR(15) NOT NULL, heure_debut TIME NOT NULL, heure_fin TIME NOT NULL, ordre INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_F9668B5FDE8E3089E87031D6F1D00661 (jour_semaine, heure_debut, heure_fin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cycle (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, type VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE enseignant (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, prenom VARCHAR(80) NOT NULL, email VARCHAR(120) NOT NULL, telephone VARCHAR(20) DEFAULT NULL, type VARCHAR(20) NOT NULL, specialite VARCHAR(150) DEFAULT NULL, actif TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_81A72FA1E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE matiere (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, code VARCHAR(10) NOT NULL, coefficient NUMERIC(4, 2) NOT NULL, couleur VARCHAR(7) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_9014574A77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE niveau (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(20) NOT NULL, serie VARCHAR(10) DEFAULT NULL, ordre INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, cycle_id INT NOT NULL, INDEX IDX_4BDFF36B5EC1162 (cycle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE salle (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(30) NOT NULL, capacite INT NOT NULL, type VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_4E977E5C6C6E55B5 (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seance (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, attribution_id INT NOT NULL, salle_id INT NOT NULL, creneau_id INT NOT NULL, INDEX IDX_DF7DFD0EEEB69F7B (attribution_id), INDEX IDX_DF7DFD0EDC304035 (salle_id), INDEX IDX_DF7DFD0E7D0729A9 (creneau_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE attribution ADD CONSTRAINT FK_C751ED49E455FCC0 FOREIGN KEY (enseignant_id) REFERENCES enseignant (id)');
        $this->addSql('ALTER TABLE attribution ADD CONSTRAINT FK_C751ED49F46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id)');
        $this->addSql('ALTER TABLE attribution ADD CONSTRAINT FK_C751ED498F5EA509 FOREIGN KEY (classe_id) REFERENCES classe (id)');
        $this->addSql('ALTER TABLE classe ADD CONSTRAINT FK_8F87BF96B3E9C81 FOREIGN KEY (niveau_id) REFERENCES niveau (id)');
        $this->addSql('ALTER TABLE classe ADD CONSTRAINT FK_8F87BF969331C741 FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire (id)');
        $this->addSql('ALTER TABLE niveau ADD CONSTRAINT FK_4BDFF36B5EC1162 FOREIGN KEY (cycle_id) REFERENCES cycle (id)');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0EEEB69F7B FOREIGN KEY (attribution_id) REFERENCES attribution (id)');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0EDC304035 FOREIGN KEY (salle_id) REFERENCES salle (id)');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0E7D0729A9 FOREIGN KEY (creneau_id) REFERENCES creneau (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attribution DROP FOREIGN KEY FK_C751ED49E455FCC0');
        $this->addSql('ALTER TABLE attribution DROP FOREIGN KEY FK_C751ED49F46CD258');
        $this->addSql('ALTER TABLE attribution DROP FOREIGN KEY FK_C751ED498F5EA509');
        $this->addSql('ALTER TABLE classe DROP FOREIGN KEY FK_8F87BF96B3E9C81');
        $this->addSql('ALTER TABLE classe DROP FOREIGN KEY FK_8F87BF969331C741');
        $this->addSql('ALTER TABLE niveau DROP FOREIGN KEY FK_4BDFF36B5EC1162');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0EEEB69F7B');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0EDC304035');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0E7D0729A9');
        $this->addSql('DROP TABLE annee_scolaire');
        $this->addSql('DROP TABLE attribution');
        $this->addSql('DROP TABLE classe');
        $this->addSql('DROP TABLE creneau');
        $this->addSql('DROP TABLE cycle');
        $this->addSql('DROP TABLE enseignant');
        $this->addSql('DROP TABLE matiere');
        $this->addSql('DROP TABLE niveau');
        $this->addSql('DROP TABLE salle');
        $this->addSql('DROP TABLE seance');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
