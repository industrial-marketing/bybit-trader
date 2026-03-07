<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Settings\ProfileContext;

/**
 * Facade: delegates to File or DB storage based on ProfileContext.
 */
class PositionLockStorage implements PositionLockStorageInterface
{
    public function __construct(
        private readonly ProfileContext $profileContext,
        private readonly FilePositionLockStorage $fileStorage,
        private readonly DbPositionLockStorage $dbStorage,
    ) {
    }

    private function getStorage(): PositionLockStorageInterface
    {
        return $this->profileContext->useFileSettings()
            ? $this->fileStorage
            : $this->dbStorage;
    }

    public function isLocked(string $symbol, string $side): bool
    {
        return $this->getStorage()->isLocked($symbol, $side);
    }

    public function setLock(string $symbol, string $side, bool $locked): void
    {
        $this->getStorage()->setLock($symbol, $side, $locked);
    }

    public function getLocks(): array
    {
        return $this->getStorage()->getLocks();
    }
}
