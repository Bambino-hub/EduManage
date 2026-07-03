<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701101735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute groupe_optionnel/salle_requise sur matiere et libelle_reserve sur creneau (génération EDT)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE creneau ADD libelle_reserve VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE matiere ADD groupe_optionnel VARCHAR(20) DEFAULT NULL, ADD salle_requise VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE creneau DROP libelle_reserve');
        $this->addSql('ALTER TABLE matiere DROP groupe_optionnel, DROP salle_requise');
    }
}
