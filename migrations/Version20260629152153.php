<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629152153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE enseignant CHANGE telephone telephone VARCHAR(20) DEFAULT NULL, CHANGE specialite specialite VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE niveau CHANGE serie serie VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE enseignant CHANGE telephone telephone VARCHAR(20) DEFAULT \'NULL\', CHANGE specialite specialite VARCHAR(150) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE niveau CHANGE serie serie VARCHAR(10) DEFAULT \'NULL\'');
    }
}
