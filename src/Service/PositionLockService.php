<?php

namespace App\Service;

/**
 * Manages user-imposed locks on open positions.
 *
 * Writes are atomic (flock + temp-rename via AtomicFileStorage::update()).
 * The in-memory cache is refreshed after every write so that callers within
 * the same request see consistent state without re-reading the file.
 */
class PositionLockService
{
    private string $filePath;
    /** @var array<string,bool> In-memory read cache, populated lazily and refreshed on write. */
    private ?array $cache = null;

    public function __construct()
    {
        $this->filePath = __DIR__ . '/../../var/position_locks.json';
    }

    private function key(string $symbol, string $side): string
    {
        return strtoupper($symbol) . '|' . ucfirst(strtolower($side));
    }

    public function isLocked(string $symbol, string $side): bool
    {
        return (bool)($this->all()[$this->key($symbol, $side)] ?? false);
    }

    public function setLock(string $symbol, string $side, bool $locked): void
    {
        $k = $this->key($symbol, $side);

        $result = AtomicFileStorage::update($this->filePath, function (array $locks) use ($k, $locked): array {
            if ($locked) {
                $locks[$k] = true;
            } else {
                unset($locks[$k]);
            }
            return $locks;
        });

        // Refresh in-memory cache to reflect the written state
        $this->cache = $result;
    }

    /** @return array<string,bool> */
    public function getLocks(): array
    {
        // Always return a fresh view for external callers
        $this->cache = null;
        return $this->all();
    }

    /** @return array<string,bool> */
    private function all(): array
    {
        if ($this->cache === null) {
            $this->cache = AtomicFileStorage::read($this->filePath);
        }
        return $this->cache;
    }
}
