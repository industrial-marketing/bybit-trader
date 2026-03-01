<?php

namespace App\Service;

/**
 * Stores actions that are awaiting user confirmation (two-phase execution).
 *
 * Used in strict mode (bot_strict_mode=true) for dangerous actions:
 * CLOSE_FULL and AVERAGE_IN_ONCE.
 *
 * File: var/pending_actions.json
 * TTL:  60 minutes — entries older than this are silently discarded.
 *
 * All mutations are atomic (flock + temp-rename via AtomicFileStorage::update()).
 */
class PendingActionsService
{
    private const TTL_MINUTES = 60;
    private string $file;

    public function __construct()
    {
        $this->file = __DIR__ . '/../../var/pending_actions.json';
    }

    /** Return all non-expired pending actions. */
    public function getAll(): array
    {
        return AtomicFileStorage::update($this->file, function (array $pending): array {
            return $this->filterExpired($pending);
        });
    }

    /** Add a new pending action and return its ID. */
    public function add(array $action): string
    {
        $id = uniqid('pa_', true);

        AtomicFileStorage::update($this->file, function (array $pending) use ($action, $id): array {
            $pending = $this->filterExpired($pending);
            $pending[] = array_merge($action, [
                'id'         => $id,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'status'     => 'pending',
            ]);
            return array_values($pending);
        });

        return $id;
    }

    /**
     * Confirm or reject a pending action by ID.
     * Removes the entry from the file regardless of the decision.
     * Returns the entry that was found (or null if not found / already expired).
     */
    public function resolve(string $id, bool $confirm): ?array
    {
        $found = null;

        AtomicFileStorage::update($this->file, function (array $pending) use ($id, &$found): array {
            $filtered = [];
            foreach ($pending as $item) {
                if (($item['id'] ?? '') === $id) {
                    $found = $item;
                } else {
                    $filtered[] = $item;
                }
            }
            return array_values($filtered);
        });

        return $found;
    }

    /** Check whether a pending action for the given symbol + action already exists. */
    public function hasPending(string $symbol, string $action): bool
    {
        $pending = AtomicFileStorage::read($this->file);
        foreach ($pending as $item) {
            if (($item['symbol'] ?? '') === $symbol && ($item['action'] ?? '') === $action) {
                return true;
            }
        }
        return false;
    }

    // ── Private helpers ────────────────────────────────────────────

    private function filterExpired(array $pending): array
    {
        $now = new \DateTimeImmutable();
        $ttl = self::TTL_MINUTES * 60;

        return array_values(array_filter($pending, function (array $item) use ($now, $ttl): bool {
            try {
                $created = new \DateTimeImmutable($item['created_at'] ?? '');
                return ($now->getTimestamp() - $created->getTimestamp()) < $ttl;
            } catch (\Exception) {
                return false;
            }
        }));
    }
}
