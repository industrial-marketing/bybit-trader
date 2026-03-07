<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add is_bot_approved to trading_profile — admin must approve profile for bot-tick
 */
final class Version20250225140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_bot_approved column to trading_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trading_profile ADD is_bot_approved TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trading_profile DROP is_bot_approved');
    }
}
