<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\AtomicFileStorage;

class FileCircuitBreakerStorage implements CircuitBreakerStorageInterface
{
    private string $filePath;

    public function __construct(string $projectDir)
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($projectDir . \DIRECTORY_SEPARATOR . 'var');
        $this->filePath = rtrim($varDir, '/\\') . \DIRECTORY_SEPARATOR . 'circuit_breaker.json';
    }

    public function getState(): array
    {
        $data = AtomicFileStorage::read($this->filePath);
        return is_array($data) ? $data : [];
    }

    public function updateState(callable $callback): array
    {
        return AtomicFileStorage::update($this->filePath, $callback);
    }
}
