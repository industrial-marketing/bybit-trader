<?php

namespace App\Service;

class PositionLockService
{
    private string $filePath;
    /** @var array<string,bool> */
    private array $locks = [];

    public function __construct()
    {
        $this->filePath = __DIR__ . '/../../var/position_locks.json';
        if (file_exists($this->filePath)) {
            $content = file_get_contents($this->filePath);
            $data = json_decode($content, true);
            if (is_array($data)) {
                $this->locks = $data;
            }
        }
    }

    private function key(string $symbol, string $side): string
    {
        return strtoupper($symbol) . '|' . ucfirst(strtolower($side));
    }

    public function isLocked(string $symbol, string $side): bool
    {
        return (bool)($this->locks[$this->key($symbol, $side)] ?? false);
    }

    public function setLock(string $symbol, string $side, bool $locked): void
    {
        $k = $this->key($symbol, $side);
        if ($locked) {
            $this->locks[$k] = true;
        } else {
            unset($this->locks[$k]);
        }
        $this->save();
    }

    /**
     * @return array<string,bool>
     */
    public function getLocks(): array
    {
        return $this->locks;
    }

    private function save(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->filePath, json_encode($this->locks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

