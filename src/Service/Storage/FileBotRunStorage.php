<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\AtomicFileStorage;

class FileBotRunStorage implements BotRunStorageInterface
{
    private const MAX_RUNS = 200;

    private string $filePath;

    public function __construct(string $projectDir)
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($projectDir . \DIRECTORY_SEPARATOR . 'var');
        $this->filePath = rtrim($varDir, '/\\') . \DIRECTORY_SEPARATOR . 'bot_runs.json';
    }

    public function currentBucket(int $timeframeMinutes): string
    {
        $interval = max(1, $timeframeMinutes) * 60;
        $bucket = intdiv(time(), $interval) * $interval;
        return date('Y-m-d\TH:i', $bucket);
    }

    public function tryStart(int $timeframeMinutes, int $staleSec): ?string
    {
        $bucket = $this->currentBucket($timeframeMinutes);
        $runId = null;

        AtomicFileStorage::update($this->filePath, function (array $runs) use ($bucket, $staleSec, &$runId): array {
            $now = time();

            foreach ($runs as &$r) {
                if (($r['timeframe_bucket'] ?? '') !== $bucket) {
                    continue;
                }

                $status = $r['status'] ?? '';
                if ($status === 'done' || $status === 'skipped') {
                    return $runs;
                }
                if ($status === 'running') {
                    $startedAt = strtotime($r['started_at'] ?? '0');
                    if ($startedAt && ($now - $startedAt) < $staleSec) {
                        return $runs;
                    }
                    $r['status'] = 'crashed';
                    $r['finished_at'] = date('c');
                }
            }
            unset($r);

            $runId = uniqid('run_', true);
            $runs[] = [
                'run_id' => $runId,
                'timeframe_bucket' => $bucket,
                'status' => 'running',
                'started_at' => date('c'),
                'finished_at' => null,
            ];

            if (count($runs) > self::MAX_RUNS) {
                $runs = array_slice($runs, -self::MAX_RUNS);
            }

            return $runs;
        });

        return $runId;
    }

    public function finish(string $runId, string $status = 'done'): void
    {
        AtomicFileStorage::update($this->filePath, function (array $runs) use ($runId, $status): array {
            foreach ($runs as &$r) {
                if (($r['run_id'] ?? '') === $runId) {
                    $r['status'] = $status;
                    $r['finished_at'] = date('c');
                    break;
                }
            }
            unset($r);
            return $runs;
        });
    }

    public function getRecentRuns(int $limit = 30): array
    {
        $runs = AtomicFileStorage::read($this->filePath);
        return array_slice(array_reverse($runs), 0, $limit);
    }

    public function isRunning(int $timeframeMinutes): bool
    {
        $bucket = $this->currentBucket($timeframeMinutes);
        $runs = AtomicFileStorage::read($this->filePath);
        foreach ($runs as $r) {
            if (($r['timeframe_bucket'] ?? '') === $bucket && ($r['status'] ?? '') === 'running') {
                return true;
            }
        }
        return false;
    }
}
