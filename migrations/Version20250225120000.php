<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bybit Trader: User, TradingProfile, ExchangeIntegration, AiProviderConfig, BotSettings, ProfilePerformanceStats
 */
final class Version20250225120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tables for users, trading profiles, integrations, AI configs, bot settings, performance stats';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(100) NOT NULL,
            role VARCHAR(20) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_APP_USER_EMAIL (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE trading_profile (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            environment VARCHAR(50) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_by_admin TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_TRADING_PROFILE_USER (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE exchange_integration (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            exchange_name VARCHAR(50) NOT NULL,
            api_key VARCHAR(255) NOT NULL,
            api_secret VARCHAR(512) NOT NULL,
            testnet_mode TINYINT(1) NOT NULL DEFAULT 1,
            base_url VARCHAR(255) DEFAULT NULL,
            extra_config JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_EXCHANGE_TRADING_PROFILE (trading_profile_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE ai_provider_config (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            provider VARCHAR(50) NOT NULL,
            model VARCHAR(100) NOT NULL,
            api_key VARCHAR(255) NOT NULL,
            base_url VARCHAR(255) DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            timeout INT DEFAULT NULL,
            options_json JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_AI_PROVIDER_TRADING_PROFILE (trading_profile_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE bot_settings (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            risk_settings JSON DEFAULT NULL,
            strategy_settings JSON DEFAULT NULL,
            order_settings JSON DEFAULT NULL,
            ai_settings JSON DEFAULT NULL,
            notifications_settings JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_BOT_SETTINGS_TRADING_PROFILE (trading_profile_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE profile_performance_stats (
            id INT AUTO_INCREMENT NOT NULL,
            trading_profile_id INT NOT NULL,
            period_from DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            period_to DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            pnl DOUBLE PRECISION NOT NULL,
            roi DOUBLE PRECISION DEFAULT NULL,
            trades_count INT NOT NULL,
            win_rate DOUBLE PRECISION DEFAULT NULL,
            max_drawdown DOUBLE PRECISION DEFAULT NULL,
            extra_stats_json JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_PROFILE_STATS_TRADING_PROFILE (trading_profile_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE trading_profile ADD CONSTRAINT FK_TRADING_PROFILE_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exchange_integration ADD CONSTRAINT FK_EXCHANGE_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_provider_config ADD CONSTRAINT FK_AI_PROVIDER_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bot_settings ADD CONSTRAINT FK_BOT_SETTINGS_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE profile_performance_stats ADD CONSTRAINT FK_PROFILE_STATS_TRADING_PROFILE FOREIGN KEY (trading_profile_id) REFERENCES trading_profile (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trading_profile DROP FOREIGN KEY FK_TRADING_PROFILE_USER');
        $this->addSql('ALTER TABLE exchange_integration DROP FOREIGN KEY FK_EXCHANGE_TRADING_PROFILE');
        $this->addSql('ALTER TABLE ai_provider_config DROP FOREIGN KEY FK_AI_PROVIDER_TRADING_PROFILE');
        $this->addSql('ALTER TABLE bot_settings DROP FOREIGN KEY FK_BOT_SETTINGS_TRADING_PROFILE');
        $this->addSql('ALTER TABLE profile_performance_stats DROP FOREIGN KEY FK_PROFILE_STATS_TRADING_PROFILE');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE trading_profile');
        $this->addSql('DROP TABLE exchange_integration');
        $this->addSql('DROP TABLE ai_provider_config');
        $this->addSql('DROP TABLE bot_settings');
        $this->addSql('DROP TABLE profile_performance_stats');
    }
}
