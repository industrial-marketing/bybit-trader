<?php

declare(strict_types=1);

namespace App\Service\Storage;

interface PendingActionStorageInterface
{
    /** @return list<array> Non-expired pending actions. */
    public function getAll(): array;

    /** Add a new pending action. Returns the external ID. */
    public function add(array $action): string;

    /** Resolve (confirm or reject) by ID. Removes entry, returns the found item or null. */
    public function resolve(string $id, bool $confirm): ?array;

    public function hasPending(string $symbol, string $action): bool;
}
