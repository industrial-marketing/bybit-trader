<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Storage\BotRunStorageInterface;

/**
 * Idempotency guard for bot ticks.
 *
 * Prevents two concurrent PHP processes (cron + manual trigger) from running
 * the same bot tick for the same timeframe window simultaneously.
 *
 * Storage: MySQL (bot_run). File storage removed.
 *
 * Timeframe bucket:
 *   For a 5-minute timeframe at 10:07 → "2026-02-25T10:05".
 *
 * Lifecycle:
 *   tryStart()  → reserves the bucket, returns run_id (or null → caller skips)
 *   finish()    → marks the run as 'done' or 'error'
 *
 * Stale detection:
 *   If a 'running' entry exists but started > 2× timeframe ago, it is assumed
 *   the process crashed. A new run is allowed and the stale entry is marked 'crashed'.
 */
class BotRunService
{
    public function __construct(
        private readonly BotRunStorageInterface $storage,
    ) {
    }

    /**
     * Calculate the current timeframe bucket label.
     */
    public function currentBucket(int $timeframeMinutes): string
    {
        return $this->storage->currentBucket($timeframeMinutes);
    }

    /**
     * Try to start a new run for the current timeframe bucket.
     *
     * Returns a run_id string on success (caller must call finish() when done).
     * Returns null if the bucket already has a running or completed entry → caller should skip.
     *
     * @param int $staleSec  Seconds before a 'running' entry is considered crashed (0 = auto: 2× timeframe)
     */
    public function tryStart(int $timeframeMinutes, int $staleSec = 0): ?string
    {
        return $this->storage->tryStart($timeframeMinutes, $staleSec);
    }

    /**
     * Mark a run as finished.
     *
     * @param string $status  'done' | 'error' | 'skipped'
     */
    public function finish(string $runId, string $status = 'done'): void
    {
        $this->storage->finish($runId, $status);
    }

    /**
     * Return recent run entries (newest first) for diagnostics / UI.
     */
    public function getRecentRuns(int $limit = 30): array
    {
        return $this->storage->getRecentRuns($limit);
    }

    /**
     * Returns true if any run for the current bucket is in 'running' state.
     */
    public function isRunning(int $timeframeMinutes): bool
    {
        return $this->storage->isRunning($timeframeMinutes);
    }
}
