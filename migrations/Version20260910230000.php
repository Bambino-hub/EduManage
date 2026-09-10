<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute Enseignant.heuresApresMidiInterdites : liste d'ordres de créneaux d'après-midi
 * (parmi 6, 7, 8) à ne jamais programmer à l'enseignant — contrainte stricte prise en
 * compte par le générateur d'emploi du temps (ReglesPlacementCreneau::apresMidiInterdit).
 */
final class Version20260910230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute Enseignant.heuresApresMidiInterdites (indisponibilité après-midi paramétrable pour le générateur d\'EDT)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE enseignant ADD heures_apres_midi_interdites JSON DEFAULT NULL');
        $this->addSql("UPDATE enseignant SET heures_apres_midi_interdites = '[]'");
        $this->addSql('ALTER TABLE enseignant MODIFY heures_apres_midi_interdites JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE enseignant DROP heures_apres_midi_interdites');
    }
}
