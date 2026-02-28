<?php

namespace App\Service;

class BotHistoryService
{
    private string $filePath;
    private array $events = [];

    public function __construct()
    {
        $this->filePath = __DIR__ . '/../../var/bot_history.json';
        if (file_exists($this->filePath)) {
            $content = file_get_contents($this->filePath);
            $this->events = json_decode($content, true) ?? [];
        }
    }

    /**
     * Записать событие в историю бота.
     */
    public function log(string $type, array $payload): void
    {
        $event = array_merge([
            'id' => uniqid($type . '_', true),
            'type' => $type,
            'timestamp' => date('c'),
        ], $payload);

        $this->events[] = $event;

        // Обрезаем историю: не старше 14 дней и не более 1000 записей
        $since = new \DateTimeImmutable('-14 days');
        $filtered = [];
        foreach ($this->events as $e) {
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
        $this->events = $filtered;

        $this->save();
    }

    /**
     * События за последние N дней (по умолчанию 7).
     */
    public function getRecentEvents(int $days = 7): array
    {
        $since = new \DateTimeImmutable("-{$days} days");
        return array_values(array_filter($this->events, function (array $e) use ($since): bool {
            if (empty($e['timestamp'])) {
                return false;
            }
            try {
                $ts = new \DateTimeImmutable($e['timestamp']);
            } catch (\Exception) {
                return false;
            }
            return $ts >= $since;
        }));
    }

    /**
     * Последнее событие указанного типа.
     */
    public function getLastEventOfType(string $type): ?array
    {
        for ($i = count($this->events) - 1; $i >= 0; $i--) {
            if (($this->events[$i]['type'] ?? '') === $type) {
                return $this->events[$i];
            }
        }
        return null;
    }

    /**
     * Краткая сводка для промптов ИИ: по символам и исходам.
     */
    public function getWeeklySummaryText(): string
    {
        $events = $this->getRecentEvents(7);
        if (empty($events)) {
            return 'No prior bot decisions or trade outcomes are available for the last 7 days.';
        }

        $perSymbol = [];
        foreach ($events as $e) {
            $sym = $e['symbol'] ?? 'UNKNOWN';
            $outcome = $e['outcome'] ?? null; // 'win' | 'loss' | 'error' | null
            $perSymbol[$sym] ??= [
                'total' => 0,
                'wins' => 0,
                'losses' => 0,
                'errors' => 0,
            ];
            $perSymbol[$sym]['total']++;
            if ($outcome === 'win') {
                $perSymbol[$sym]['wins']++;
            } elseif ($outcome === 'loss') {
                $perSymbol[$sym]['losses']++;
            } elseif ($outcome === 'error') {
                $perSymbol[$sym]['errors']++;
            }
        }

        uasort($perSymbol, fn($a, $b) => ($b['total'] <=> $a['total']));
        $top = array_slice($perSymbol, 0, 10, true);

        $lines = ["Recent bot performance over the last 7 days:"];
        foreach ($top as $sym => $stats) {
            $lines[] = sprintf(
                "%s: total=%d, wins=%d, losses=%d, errors=%d",
                $sym,
                $stats['total'],
                $stats['wins'],
                $stats['losses'],
                $stats['errors']
            );
        }

        return implode("\n", $lines);
    }

    private function save(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->filePath, json_encode($this->events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

