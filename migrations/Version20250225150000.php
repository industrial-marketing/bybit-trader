<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Long-term memory: memory_entry table for embeddings + retrieval
 */
final class Version20250225150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create memory_entry table for long-term memory and retrieval layer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE memory_entry (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            symbol VARCHAR(32) DEFAULT NULL,
            memory_type VARCHAR(32) NOT NULL,
            event_time DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            source_entity_id VARCHAR(64) DEFAULT NULL,
            text_content LONGTEXT NOT NULL,
            json_payload JSON DEFAULT NULL,
            embedding JSON DEFAULT NULL,
            quality_score DOUBLE PRECISION DEFAULT NULL,
            outcome_score DOUBLE PRECISION DEFAULT NULL,
            tags JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_MEMORY_PROFILE_TYPE (trading_profile_id, memory_type),
            INDEX IDX_MEMORY_PROFILE_CREATED (trading_profile_id, created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE memory_entry ADD CONSTRAINT FK_MEMORY_ENTRY_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE memory_entry DROP FOREIGN KEY FK_MEMORY_ENTRY_TRADING_PROFILE');
        $this->addSql('DROP TABLE memory_entry');
    }
}
