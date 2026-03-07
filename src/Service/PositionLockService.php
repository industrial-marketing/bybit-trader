<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Storage\PositionLockStorageInterface;

/**
 * Manages user-imposed locks on open positions.
 *
 * Storage: file (var/position_locks.json) or MySQL (position_lock) depending on ProfileContext.
 */
class PositionLockService
{
    public function __construct(
        private readonly PositionLockStorageInterface $storage,
    ) {
    }

    public function isLocked(string $symbol, string $side): bool
    {
        return $this->storage->isLocked($symbol, $side);
    }

    public function setLock(string $symbol, string $side, bool $locked): void
    {
        $this->storage->setLock($symbol, $side, $locked);
    }

    /** @return array<string,bool> */
    public function getLocks(): array
    {
        return $this->storage->getLocks();
    }
}
