<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Storage\BotHistoryStorageInterface;

/**
 * Persistent bot event log.
 *
 * Storage: file (var/bot_history.json) or MySQL (bot_history_event) depending on ProfileContext.
 * Override via VAR_DIR env: absolute path to var/ (ensures same file when cron runs from different cwd).
 */
class BotHistoryService
{
    public function __construct(
        private readonly BotHistoryStorageInterface $storage,
    ) {
    }

    /** Path to var/bot_history.json when using file storage; empty when using DB. */
    public function getDataFilePath(): string
    {
        return $this->storage->getDataFilePath();
    }

    /**
     * Check if var dir is writable (for cron vs www-data permission diagnostics).
     * Returns null if OK, or an error message. When using DB storage, returns null (no file to check).
     */
    public function checkVarWritable(): ?string
    {
        $path = $this->storage->getDataFilePath();
        if ($path === '') {
            return null;
        }
        $dir = dirname($path);
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

    public function log(string $type, array $payload): void
    {
        $this->storage->log($type, $payload);
    }

    public function getRecentEvents(int $days = 7): array
    {
        return $this->storage->getRecentEvents($days);
    }

    public function getLastEventOfType(string $type): ?array
    {
        return $this->storage->getLastEventOfType($type);
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
