<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute Seance.verrouille : marque une séance comme "personnalisée", figée à son
 * créneau/salle actuels — ni une génération totale (generer()) ni une réorganisation
 * (reorganiser()) ne la déplacent, cf. EmploiDuTempsGenerator.
 */
final class Version20260911074707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute Seance.verrouille (personnalisation de l\'emploi du temps par enseignant)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seance ADD verrouille TINYINT(1) DEFAULT NULL');
        $this->addSql('UPDATE seance SET verrouille = 0');
        $this->addSql('ALTER TABLE seance MODIFY verrouille TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seance DROP verrouille');
    }
}
