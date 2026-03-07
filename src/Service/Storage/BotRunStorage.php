<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Settings\ProfileContext;

class BotRunStorage implements BotRunStorageInterface
{
    public function __construct(
        private readonly ProfileContext $profileContext,
        private readonly FileBotRunStorage $fileStorage,
        private readonly DbBotRunStorage $dbStorage,
    ) {
    }

    private function getStorage(): BotRunStorageInterface
    {
        return $this->profileContext->useFileSettings()
            ? $this->fileStorage
            : $this->dbStorage;
    }

    public function currentBucket(int $timeframeMinutes): string
    {
        return $this->getStorage()->currentBucket($timeframeMinutes);
    }

    public function tryStart(int $timeframeMinutes, int $staleSec): ?string
    {
        return $this->getStorage()->tryStart($timeframeMinutes, $staleSec);
    }

    public function finish(string $runId, string $status = 'done'): void
    {
        $this->getStorage()->finish($runId, $status);
    }

    public function getRecentRuns(int $limit = 30): array
    {
        return $this->getStorage()->getRecentRuns($limit);
    }

    public function isRunning(int $timeframeMinutes): bool
    {
        return $this->getStorage()->isRunning($timeframeMinutes);
    }
}
