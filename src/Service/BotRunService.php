<?php

namespace App\Service;

/**
 * Idempotency guard for bot ticks.
 *
 * Prevents two concurrent PHP processes (cron + manual trigger) from running
 * the same bot tick for the same timeframe window simultaneously.
 *
 * State file: var/bot_runs.json
 * All reads/writes are done under flock via AtomicFileStorage.
 *
 * Timeframe bucket:
 *   For a 5-minute timeframe, the bucket at 10:07 is "2026-02-25T10:05".
 *   Two calls within the same 5-minute window share the same bucket key.
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
    private const FILE      = '/../../var/bot_runs.json';
    private const MAX_RUNS  = 200;

    private string $filePath;

    public function __construct()
    {
        $this->filePath = __DIR__ . self::FILE;
    }

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Calculate the current timeframe bucket label.
     * E.g., 5-min timeframe at 10:07 → "2026-02-25T10:05".
     */
    public function currentBucket(int $timeframeMinutes): string
    {
        $interval = max(1, $timeframeMinutes) * 60;
        $bucket   = intdiv(time(), $interval) * $interval;
        return date('Y-m-d\TH:i', $bucket);
    }

    /**
     * Try to start a new run for the current timeframe bucket.
     *
     * Returns a run_id string on success (caller must call finish() when done).
     * Returns null if the bucket already has a running or completed entry → caller should skip.
     *
     * @param int $timeframeMinutes  Bot timeframe in minutes
     * @param int $staleSec         Seconds before a 'running' entry is considered crashed (0 = auto: 2× timeframe)
     */
    public function tryStart(int $timeframeMinutes, int $staleSec = 0): ?string
    {
        if ($staleSec <= 0) {
            $staleSec = $timeframeMinutes * 60 * 2;
        }

        $bucket = $this->currentBucket($timeframeMinutes);
        $runId  = null;

        AtomicFileStorage::update($this->filePath, function (array $runs) use ($bucket, $staleSec, &$runId): array {
            $now = time();

            foreach ($runs as &$r) {
                if (($r['timeframe_bucket'] ?? '') !== $bucket) {
                    continue;
                }

                $status = $r['status'] ?? '';

                if ($status === 'done' || $status === 'skipped') {
                    // Bucket already completed successfully → skip
                    return $runs;
                }

                if ($status === 'running') {
                    $startedAt = strtotime($r['started_at'] ?? '0');
                    if ($startedAt && ($now - $startedAt) < $staleSec) {
                        // Another process is actively running → skip
                        return $runs;
                    }
                    // Stale (crashed) — mark it and fall through to create a new entry
                    $r['status']      = 'crashed';
                    $r['finished_at'] = date('c');
                }
            }
            unset($r);

            // Create a new run entry for this bucket
            $runId  = uniqid('run_', true);
            $runs[] = [
                'run_id'           => $runId,
                'timeframe_bucket' => $bucket,
                'status'           => 'running',
                'started_at'       => date('c'),
                'finished_at'      => null,
            ];

            // Keep the file from growing unboundedly
            if (count($runs) > self::MAX_RUNS) {
                $runs = array_slice($runs, -self::MAX_RUNS);
            }

            return $runs;
        });

        return $runId;
    }

    /**
     * Mark a run as finished.
     *
     * @param string $status  'done' | 'error' | 'skipped'
     */
    public function finish(string $runId, string $status = 'done'): void
    {
        AtomicFileStorage::update($this->filePath, function (array $runs) use ($runId, $status): array {
            foreach ($runs as &$r) {
                if (($r['run_id'] ?? '') === $runId) {
                    $r['status']      = $status;
                    $r['finished_at'] = date('c');
                    break;
                }
            }
            unset($r);
            return $runs;
        });
    }

    /**
     * Return recent run entries (newest first) for diagnostics / UI.
     */
    public function getRecentRuns(int $limit = 30): array
    {
        $runs = AtomicFileStorage::read($this->filePath);
        return array_slice(array_reverse($runs), 0, $limit);
    }

    /**
     * Returns true if any run for the current bucket is in 'running' state.
     * Useful for health-check endpoints.
     */
    public function isRunning(int $timeframeMinutes): bool
    {
        $bucket = $this->currentBucket($timeframeMinutes);
        $runs   = AtomicFileStorage::read($this->filePath);
        foreach ($runs as $r) {
            if (($r['timeframe_bucket'] ?? '') === $bucket && ($r['status'] ?? '') === 'running') {
                return true;
            }
        }
        return false;
    }
}
