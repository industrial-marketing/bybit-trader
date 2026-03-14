<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Storage for bot history events (MySQL bot_history_event). File storage removed.
 */
interface BotHistoryStorageInterface
{
    /** Always empty for DB storage. */
    public function getDataFilePath(): string;

    public function log(string $type, array $payload): void;

    /** @return list<array> Events from the last $days days */
    public function getRecentEvents(int $days = 7): array;

    public function getLastEventOfType(string $type): ?array;
}
