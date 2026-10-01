<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Surveillance des devoirs : l'éligibilité ne se déduit plus du statut et de la fonction mais
 * d'une case à cocher par personne (Enseignant.autoriseSurveillance), complétée par une période
 * de disponibilité (surveillanceDu/surveillanceAu) et une demi-charge (surveillanceMoitie),
 * cf. ExamenSurveillanceGenerator. La case est pré-cochée pour tous ceux que l'ancienne règle
 * retenait (stagiaires, internes à poste d'enseignement) : aucun changement de pool à la migration.
 */
final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute Enseignant.autoriseSurveillance, surveillanceDu/Au et surveillanceMoitie';
    }

    public function up(Schema $schema): void
    {
        // COALESCE : un interne sans fonction renseignée (poste NULL) donnerait NULL, refusé par
        // le NOT NULL qui suit — la migration s'exécute au démarrage du conteneur, elle ne doit
        // pas pouvoir échouer sur une donnée de production.
        $this->addSql('ALTER TABLE enseignant ADD autorise_surveillance TINYINT(1) DEFAULT NULL, ADD surveillance_du DATE DEFAULT NULL, ADD surveillance_au DATE DEFAULT NULL, ADD surveillance_moitie TINYINT(1) DEFAULT NULL');
        $this->addSql("UPDATE enseignant SET surveillance_moitie = 0, autorise_surveillance = COALESCE(type = 'stagiaire' OR (type = 'interne' AND poste LIKE '%enseignant%'), 0)");
        $this->addSql('ALTER TABLE enseignant MODIFY autorise_surveillance TINYINT(1) NOT NULL, MODIFY surveillance_moitie TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE enseignant DROP autorise_surveillance, DROP surveillance_du, DROP surveillance_au, DROP surveillance_moitie');
    }
}
