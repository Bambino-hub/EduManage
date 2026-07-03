<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701074000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le volume horaire hebdomadaire (heures_par_semaine) par matière x niveau';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT temporaire pour peupler les lignes existantes, retiré ensuite pour rester
        // cohérent avec le mapping Doctrine (qui ne déclare pas de defaut au niveau colonne).
        $this->addSql("ALTER TABLE matiere_niveau ADD heures_par_semaine NUMERIC(4, 2) NOT NULL DEFAULT '0.00'");
        $this->addSql('ALTER TABLE matiere_niveau ALTER heures_par_semaine DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE matiere_niveau DROP heures_par_semaine');
    }
}
