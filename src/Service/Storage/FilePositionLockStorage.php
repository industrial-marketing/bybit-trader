<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\AtomicFileStorage;

class FilePositionLockStorage implements PositionLockStorageInterface
{
    private string $filePath;
    /** @var array<string,bool>|null */
    private ?array $cache = null;

    public function __construct(string $projectDir)
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($projectDir . DIRECTORY_SEPARATOR . 'var');
        $this->filePath = rtrim($varDir, '/\\') . DIRECTORY_SEPARATOR . 'position_locks.json';
    }

    private function key(string $symbol, string $side): string
    {
        return strtoupper($symbol) . '|' . ucfirst(strtolower($side));
    }

    public function isLocked(string $symbol, string $side): bool
    {
        return (bool) ($this->all()[$this->key($symbol, $side)] ?? false);
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

        $this->cache = $result;
    }

    public function getLocks(): array
    {
        $this->cache = null;
        return $this->all();
    }

    /** @return array<string,bool> */
    private function all(): array
    {
        if ($this->cache === null) {
            $this->cache = AtomicFileStorage::read($this->filePath);
        }
        return is_array($this->cache) ? $this->cache : [];
    }
}
