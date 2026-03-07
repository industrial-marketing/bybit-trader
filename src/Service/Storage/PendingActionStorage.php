<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Settings\ProfileContext;

class PendingActionStorage implements PendingActionStorageInterface
{
    public function __construct(
        private readonly ProfileContext $profileContext,
        private readonly FilePendingActionStorage $fileStorage,
        private readonly DbPendingActionStorage $dbStorage,
    ) {
    }

    private function getStorage(): PendingActionStorageInterface
    {
        return $this->profileContext->useFileSettings()
            ? $this->fileStorage
            : $this->dbStorage;
    }

    public function getAll(): array
    {
        return $this->getStorage()->getAll();
    }

    public function add(array $action): string
    {
        return $this->getStorage()->add($action);
    }

    public function resolve(string $id, bool $confirm): ?array
    {
        return $this->getStorage()->resolve($id, $confirm);
    }

    public function hasPending(string $symbol, string $action): bool
    {
        return $this->getStorage()->hasPending($symbol, $action);
    }
}
