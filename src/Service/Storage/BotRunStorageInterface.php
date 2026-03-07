<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Storage for bot run idempotency (var/bot_runs.json | bot_run).
 */
interface BotRunStorageInterface
{
    public function currentBucket(int $timeframeMinutes): string;

    /** Try to start a run for the current bucket. Returns run_id or null if skip. */
    public function tryStart(int $timeframeMinutes, int $staleSec): ?string;

    /** Mark run as finished. */
    public function finish(string $runId, string $status = 'done'): void;

    /** @return list<array> Recent runs (newest first) */
    public function getRecentRuns(int $limit = 30): array;

    public function isRunning(int $timeframeMinutes): bool;
}
