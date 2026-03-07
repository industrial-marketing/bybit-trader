<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Settings\ProfileContext;

class BotHistoryStorage implements BotHistoryStorageInterface
{
    public function __construct(
        private readonly ProfileContext $profileContext,
        private readonly FileBotHistoryStorage $fileStorage,
        private readonly DbBotHistoryStorage $dbStorage,
    ) {
    }

    private function getStorage(): BotHistoryStorageInterface
    {
        return $this->profileContext->useFileSettings()
            ? $this->fileStorage
            : $this->dbStorage;
    }

    public function getDataFilePath(): string
    {
        return $this->getStorage()->getDataFilePath();
    }

    public function log(string $type, array $payload): void
    {
        $this->getStorage()->log($type, $payload);
    }

    public function getRecentEvents(int $days = 7): array
    {
        return $this->getStorage()->getRecentEvents($days);
    }

    public function getLastEventOfType(string $type): ?array
    {
        return $this->getStorage()->getLastEventOfType($type);
    }
}
