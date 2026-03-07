<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bybit Trader: Runtime data tables - bot_history_event, bot_run, pending_action, position_lock, position_plan, circuit_breaker_state
 */
final class Version20250225130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create runtime data tables: bot history, bot runs, pending actions, position locks, position plans, circuit breaker';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bot_history_event (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            event_id VARCHAR(64) NOT NULL,
            payload JSON NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_BOT_HISTORY_EVENT_ID (event_id),
            INDEX IDX_BOT_HISTORY_PROFILE_CREATED (trading_profile_id, created_at),
            INDEX IDX_BOT_HISTORY_PROFILE_TYPE (trading_profile_id, type),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE bot_run (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            run_id VARCHAR(64) NOT NULL,
            timeframe_bucket VARCHAR(32) NOT NULL,
            status VARCHAR(20) NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME DEFAULT NULL,
            INDEX IDX_BOT_RUN_PROFILE_BUCKET (trading_profile_id, timeframe_bucket),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE pending_action (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            external_id VARCHAR(64) NOT NULL,
            symbol VARCHAR(32) NOT NULL,
            action VARCHAR(50) NOT NULL,
            payload JSON NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_PENDING_ACTION_PROFILE (trading_profile_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE position_lock (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            symbol VARCHAR(32) NOT NULL,
            side VARCHAR(10) NOT NULL,
            locked TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE INDEX UNIQ_POSITION_LOCK_PROFILE_SYMBOL_SIDE (trading_profile_id, symbol, side),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE position_plan (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            symbol VARCHAR(32) NOT NULL,
            side VARCHAR(10) NOT NULL,
            plan_data JSON NOT NULL,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_POSITION_PLAN_PROFILE_SYMBOL_SIDE (trading_profile_id, symbol, side),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE circuit_breaker_state (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            breaker_type VARCHAR(32) NOT NULL,
            state_data JSON NOT NULL,
            UNIQUE INDEX UNIQ_CIRCUIT_BREAKER_PROFILE_TYPE (trading_profile_id, breaker_type),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE bot_history_event ADD CONSTRAINT FK_BOT_HISTORY_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bot_run ADD CONSTRAINT FK_BOT_RUN_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pending_action ADD CONSTRAINT FK_PENDING_ACTION_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE position_lock ADD CONSTRAINT FK_POSITION_LOCK_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE position_plan ADD CONSTRAINT FK_POSITION_PLAN_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE circuit_breaker_state ADD CONSTRAINT FK_CIRCUIT_BREAKER_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_history_event DROP FOREIGN KEY FK_BOT_HISTORY_TRADING_PROFILE');
        $this->addSql('ALTER TABLE bot_run DROP FOREIGN KEY FK_BOT_RUN_TRADING_PROFILE');
        $this->addSql('ALTER TABLE pending_action DROP FOREIGN KEY FK_PENDING_ACTION_TRADING_PROFILE');
        $this->addSql('ALTER TABLE position_lock DROP FOREIGN KEY FK_POSITION_LOCK_TRADING_PROFILE');
        $this->addSql('ALTER TABLE position_plan DROP FOREIGN KEY FK_POSITION_PLAN_TRADING_PROFILE');
        $this->addSql('ALTER TABLE circuit_breaker_state DROP FOREIGN KEY FK_CIRCUIT_BREAKER_TRADING_PROFILE');
        $this->addSql('DROP TABLE bot_history_event');
        $this->addSql('DROP TABLE bot_run');
        $this->addSql('DROP TABLE pending_action');
        $this->addSql('DROP TABLE position_lock');
        $this->addSql('DROP TABLE position_plan');
        $this->addSql('DROP TABLE circuit_breaker_state');
    }
}
