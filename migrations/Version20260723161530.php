<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260723161530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les tables des statistiques d\'examens nationaux (BEPC/BAC1/BAC2) : session_examen_national, candidat_examen_national, note_matiere_candidat.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE candidat_examen_national (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, prenoms VARCHAR(120) NOT NULL, sexe VARCHAR(1) DEFAULT NULL, date_naissance DATE DEFAULT NULL, lieu_naissance VARCHAR(100) DEFAULT NULL, numero_jury VARCHAR(20) DEFAULT NULL, numero_table VARCHAR(20) DEFAULT NULL, decision_jury VARCHAR(255) DEFAULT NULL, moyenne_globale_affichee NUMERIC(5, 2) DEFAULT NULL, total_points_ecrites_affiche NUMERIC(6, 2) DEFAULT NULL, page_numero INT NOT NULL, controle_arithmetique_ok TINYINT NOT NULL, ecart_controle NUMERIC(6, 2) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, session_id INT NOT NULL, INDEX IDX_307EEA28613FECDF (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE note_matiere_candidat (id INT AUTO_INCREMENT NOT NULL, type_epreuve VARCHAR(20) NOT NULL, matiere_libelle VARCHAR(100) NOT NULL, note NUMERIC(4, 2) DEFAULT NULL, coefficient NUMERIC(4, 2) DEFAULT NULL, points_obtenus NUMERIC(6, 2) DEFAULT NULL, candidat_id INT NOT NULL, INDEX IDX_8E1D8C8D8D0EB82 (candidat_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE session_examen_national (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, serie VARCHAR(20) NOT NULL, libelle_serie VARCHAR(255) DEFAULT NULL, annee_session INT DEFAULT NULL, centre_examen VARCHAR(255) DEFAULT NULL, statut VARCHAR(20) NOT NULL, total_pages INT NOT NULL, pages_traitees INT NOT NULL, chemin_fichier_temporaire VARCHAR(255) DEFAULT NULL, taille_pages_lot VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE candidat_examen_national ADD CONSTRAINT FK_307EEA28613FECDF FOREIGN KEY (session_id) REFERENCES session_examen_national (id)');
        $this->addSql('ALTER TABLE note_matiere_candidat ADD CONSTRAINT FK_8E1D8C8D8D0EB82 FOREIGN KEY (candidat_id) REFERENCES candidat_examen_national (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidat_examen_national DROP FOREIGN KEY FK_307EEA28613FECDF');
        $this->addSql('ALTER TABLE note_matiere_candidat DROP FOREIGN KEY FK_8E1D8C8D8D0EB82');
        $this->addSql('DROP TABLE candidat_examen_national');
        $this->addSql('DROP TABLE note_matiere_candidat');
        $this->addSql('DROP TABLE session_examen_national');
    }
}
