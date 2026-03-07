<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Storage\PendingActionStorageInterface;

/**
 * Stores actions that are awaiting user confirmation (two-phase execution).
 *
 * Used in strict mode (bot_strict_mode=true) for dangerous actions:
 * CLOSE_FULL and AVERAGE_IN_ONCE.
 *
 * Storage: file (var/pending_actions.json) or MySQL (pending_action) depending on ProfileContext.
 * TTL: 60 minutes — entries older than this are silently discarded.
 */
class PendingActionsService
{
    public function __construct(
        private readonly PendingActionStorageInterface $storage,
    ) {
    }

    /** Return all non-expired pending actions. */
    public function getAll(): array
    {
        return $this->storage->getAll();
    }

    /** Add a new pending action and return its ID. */
    public function add(array $action): string
    {
        return $this->storage->add($action);
    }

    /**
     * Confirm or reject a pending action by ID.
     * Removes the entry regardless of the decision.
     * Returns the entry that was found (or null if not found / already expired).
     */
    public function resolve(string $id, bool $confirm): ?array
    {
        return $this->storage->resolve($id, $confirm);
    }

    /** Check whether a pending action for the given symbol + action already exists. */
    public function hasPending(string $symbol, string $action): bool
    {
        return $this->storage->hasPending($symbol, $action);
    }
}
