<?php

namespace App\Service;

/**
 * Persistent bot event log stored in var/bot_history.json.
 *
 * All writes go through AtomicFileStorage::update() which uses flock(LOCK_EX)
 * + temp-rename to prevent JSON corruption on concurrent cron / manual runs.
 * Read-only methods go through AtomicFileStorage::read() with LOCK_SH.
 */
class BotHistoryService
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = __DIR__ . '/../../var/bot_history.json';
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
