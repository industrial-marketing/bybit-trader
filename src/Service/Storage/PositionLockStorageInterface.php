<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Storage for position locks (symbol|side → locked).
 * Implementations: file (var/position_locks.json), DB (position_lock table).
 */
interface PositionLockStorageInterface
{
    public function isLocked(string $symbol, string $side): bool;

    public function setLock(string $symbol, string $side, bool $locked): void;

    /** @return array<string,bool> key = SYMBOL|Side */
    public function getLocks(): array;
}
