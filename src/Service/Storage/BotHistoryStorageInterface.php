<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Storage for bot history events.
 * File: var/bot_history.json | DB: bot_history_event
 */
interface BotHistoryStorageInterface
{
    /** Path to JSON file when using file storage; empty string when using DB. */
    public function getDataFilePath(): string;

    public function log(string $type, array $payload): void;

    /** @return list<array> Events from the last $days days */
    public function getRecentEvents(int $days = 7): array;

    public function getLastEventOfType(string $type): ?array;
}
