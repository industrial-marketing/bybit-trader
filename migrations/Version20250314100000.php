<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add google_refresh_token to app_user for storing Google OAuth refresh token
 * (access_type=offline, prompt=consent) — enables future token refresh without re-auth.
 */
final class Version20250314100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add google_refresh_token to app_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD google_refresh_token LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP google_refresh_token');
    }
}
