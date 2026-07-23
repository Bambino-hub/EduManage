<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260719105907 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bulletin conforme au modèle réel : 3 composantes (interrogation/devoir/composition), rang par matière, bilans domaine/classe, historique annuel, décision du conseil';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bulletin_bilan_domaine (id INT AUTO_INCREMENT NOT NULL, domaine VARCHAR(20) NOT NULL, moyenne NUMERIC(4, 2) DEFAULT NULL, appreciation VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, bulletin_id INT NOT NULL, INDEX IDX_7A8FAE55D1AAB236 (bulletin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bulletin_bilan_domaine ADD CONSTRAINT FK_7A8FAE55D1AAB236 FOREIGN KEY (bulletin_id) REFERENCES bulletin (id)');
        $this->addSql('ALTER TABLE annee_scolaire ADD poids_interrogation NUMERIC(4, 2) DEFAULT \'1.00\' NOT NULL, CHANGE poids_devoirs poids_devoirs NUMERIC(4, 2) DEFAULT \'1.00\' NOT NULL, CHANGE poids_composition poids_composition NUMERIC(4, 2) DEFAULT \'1.00\' NOT NULL');
        $this->addSql('UPDATE annee_scolaire SET poids_devoirs = \'1.00\', poids_composition = \'1.00\'');
        $this->addSql('ALTER TABLE bulletin ADD moyenne_annuelle NUMERIC(4, 2) DEFAULT NULL, ADD rang_annuel INT DEFAULT NULL, ADD moyenne_classe_faible NUMERIC(4, 2) DEFAULT NULL, ADD moyenne_classe_forte NUMERIC(4, 2) DEFAULT NULL, ADD moyenne_classe_generale NUMERIC(4, 2) DEFAULT NULL, ADD decision_conseil VARCHAR(150) DEFAULT NULL, ADD appreciation_professeur_principal VARCHAR(150) DEFAULT NULL, ADD mentions JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bulletin_matiere ADD moyenne_interrogation NUMERIC(4, 2) DEFAULT NULL, ADD enseignant_nom VARCHAR(150) NOT NULL, ADD rang INT DEFAULT NULL, ADD appreciation VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bulletin_bilan_domaine DROP FOREIGN KEY FK_7A8FAE55D1AAB236');
        $this->addSql('DROP TABLE bulletin_bilan_domaine');
        $this->addSql('ALTER TABLE annee_scolaire DROP poids_interrogation, CHANGE poids_devoirs poids_devoirs NUMERIC(4, 2) DEFAULT \'0.50\' NOT NULL, CHANGE poids_composition poids_composition NUMERIC(4, 2) DEFAULT \'0.50\' NOT NULL');
        $this->addSql('ALTER TABLE bulletin DROP moyenne_annuelle, DROP rang_annuel, DROP moyenne_classe_faible, DROP moyenne_classe_forte, DROP moyenne_classe_generale, DROP decision_conseil, DROP appreciation_professeur_principal, DROP mentions');
        $this->addSql('ALTER TABLE bulletin_matiere DROP moyenne_interrogation, DROP enseignant_nom, DROP rang, DROP appreciation');
    }
}
