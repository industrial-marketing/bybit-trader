<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\AtomicFileStorage;

class FileBotHistoryStorage implements BotHistoryStorageInterface
{
    private string $filePath;

    public function __construct(string $projectDir)
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($projectDir . DIRECTORY_SEPARATOR . 'var');
        $this->filePath = rtrim($varDir, '/\\') . DIRECTORY_SEPARATOR . 'bot_history.json';
    }

    public function getDataFilePath(): string
    {
        return $this->filePath;
    }

    public function log(string $type, array $payload): void
    {
        $event = array_merge([
            'id'        => uniqid($type . '_', true),
            'type'      => $type,
            'timestamp' => date('c'),
        ], $payload);

        AtomicFileStorage::update($this->filePath, function (array $events) use ($event): array {
            $events[] = $event;
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
}
