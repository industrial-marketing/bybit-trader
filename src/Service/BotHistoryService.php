<?php

namespace App\Service;

/**
 * Persistent bot event log stored in var/bot_history.json.
 *
 * Path: project_dir/var/bot_history.json (same for web and console).
 * Override via VAR_DIR env: absolute path to var/ (ensures same file when cron runs from different cwd).
 *
 * All writes go through AtomicFileStorage::update() which uses flock(LOCK_EX)
 * + temp-rename to prevent JSON corruption on concurrent cron / manual runs.
 */
class BotHistoryService
{
    private string $filePath;

    public function __construct(string $projectDir)
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($projectDir . DIRECTORY_SEPARATOR . 'var');
        $this->filePath = rtrim($varDir, '/\\') . DIRECTORY_SEPARATOR . 'bot_history.json';
    }

    /** Path to var/bot_history.json (for diagnostics: cron vs web must use same path). */
    public function getDataFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Check if var dir is writable (for cron vs www-data permission diagnostics).
     * Returns null if OK, or an error message.
     */
    public function checkVarWritable(): ?string
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            return "var dir does not exist: {$dir}";
        }
        if (!is_writable($dir)) {
            $user = (function_exists('posix_getpwuid') && function_exists('posix_geteuid'))
                ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                : get_current_user();
            return "var dir not writable (current user: {$user}): {$dir}";
        }
        $testFile = $dir . '/.writetest_' . getmypid();
        if (@file_put_contents($testFile, '1') === false) {
            return "cannot write to var: {$dir}";
        }
        @unlink($testFile);
        return null;
    }

    /**
     * Append an event to the history (atomic read-modify-write under flock).
     */
    public function log(string $type, array $payload): void
    {
        $event = array_merge([
            'id'        => uniqid($type . '_', true),
            'type'      => $type,
            'timestamp' => date('c'),
        ], $payload);

        AtomicFileStorage::update($this->filePath, function (array $events) use ($event): array {
            $events[] = $event;

            // Trim: keep last 14 days and at most 1 000 entries
            $since    = new \DateTimeImmutable('-14 days');
            $filtered = [];
            foreach ($events as $e) {
                if (empty($e['timestamp'])) {
                    continue;
                }
                try {
                    $ts = new \DateTimeImmutable($e['timestamp']);
                } catch (\Exception) {
                    continue;
                }
                if ($ts >= $since) {
                    $filtered[] = $e;
                }
            }
            if (count($filtered) > 1000) {
                $filtered = array_slice($filtered, -1000);
            }

            return $filtered;
        });
    }

    /**
     * Return events from the last $days days (shared-lock read).
     */
    public function getRecentEvents(int $days = 7): array
    {
        $events = AtomicFileStorage::read($this->filePath);
        $since  = new \DateTimeImmutable("-{$days} days");

        return array_values(array_filter($events, function (array $e) use ($since): bool {
            if (empty($e['timestamp'])) {
                return false;
            }
            try {
                return new \DateTimeImmutable($e['timestamp']) >= $since;
            } catch (\Exception) {
                return false;
            }
        }));
    }

    /**
     * Return the most recent event of the given type, or null.
     */
    public function getLastEventOfType(string $type): ?array
    {
        $events = AtomicFileStorage::read($this->filePath);
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (($events[$i]['type'] ?? '') === $type) {
                return $events[$i];
            }
        }
        return null;
    }

    /**
     * Short performance summary for LLM prompts (last 7 days, top-10 symbols).
     */
    public function getWeeklySummaryText(): string
    {
        $events = $this->getRecentEvents(7);
        if (empty($events)) {
            return 'No prior bot decisions or trade outcomes are available for the last 7 days.';
        }

        $perSymbol = [];
        foreach ($events as $e) {
            $sym     = $e['symbol'] ?? 'UNKNOWN';
            $outcome = $e['outcome'] ?? null;
            $perSymbol[$sym] ??= ['total' => 0, 'wins' => 0, 'losses' => 0, 'errors' => 0];
            $perSymbol[$sym]['total']++;
            match ($outcome) {
                'win'   => $perSymbol[$sym]['wins']++,
                'loss'  => $perSymbol[$sym]['losses']++,
                'error' => $perSymbol[$sym]['errors']++,
                default => null,
            };
        }

        uasort($perSymbol, fn($a, $b) => $b['total'] <=> $a['total']);
        $top = array_slice($perSymbol, 0, 10, true);

        $lines = ['Recent bot performance over the last 7 days:'];
        foreach ($top as $sym => $s) {
            $lines[] = sprintf(
                '%s: total=%d, wins=%d, losses=%d, errors=%d',
                $sym, $s['total'], $s['wins'], $s['losses'], $s['errors']
            );
        }

        return implode("\n", $lines);
    }
}
