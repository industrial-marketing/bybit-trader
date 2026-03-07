<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Settings\ProfileContext;

class CircuitBreakerStorage implements CircuitBreakerStorageInterface
{
    public function __construct(
        private readonly ProfileContext $profileContext,
        private readonly FileCircuitBreakerStorage $fileStorage,
        private readonly DbCircuitBreakerStorage $dbStorage,
    ) {
    }

    private function getStorage(): CircuitBreakerStorageInterface
    {
        return $this->profileContext->useFileSettings()
            ? $this->fileStorage
            : $this->dbStorage;
    }

    public function getState(): array
    {
        return $this->getStorage()->getState();
    }

    public function updateState(callable $callback): array
    {
        return $this->getStorage()->updateState($callback);
    }
}
