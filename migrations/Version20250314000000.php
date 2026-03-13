<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix base_url for mainnet profiles: when testnet_mode=0 and base_url is null/empty,
 * set base_url to api.bybit.com so mainnet API calls work correctly.
 */
final class Version20250314000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix base_url for mainnet profiles (exchange_integration)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE exchange_integration SET base_url = 'https://api.bybit.com' WHERE testnet_mode = 0 AND (base_url IS NULL OR base_url = '')");
        $this->addSql("UPDATE exchange_integration SET base_url = 'https://api-testnet.bybit.com' WHERE testnet_mode = 1 AND (base_url IS NULL OR base_url = '')");
    }

    public function down(Schema $schema): void
    {
        // No safe rollback - leave base_url as is
    }
}
