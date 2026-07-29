<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728204100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute Enseignant.nbPremieresHeuresAEviter (indisponibilité premières heures, prise en compte par le générateur d\'emploi du temps)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE enseignant ADD nb_premieres_heures_aeviter INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE enseignant DROP nb_premieres_heures_aeviter');
    }
}
