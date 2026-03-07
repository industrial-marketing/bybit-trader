<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\AtomicFileStorage;

class FilePendingActionStorage implements PendingActionStorageInterface
{
    private const TTL_MINUTES = 60;

    private string $filePath;

    public function __construct(string $projectDir)
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($projectDir . DIRECTORY_SEPARATOR . 'var');
        $this->filePath = rtrim($varDir, '/\\') . DIRECTORY_SEPARATOR . 'pending_actions.json';
    }

    public function getAll(): array
    {
        return AtomicFileStorage::update($this->filePath, function (array $pending): array {
            return $this->filterExpired($pending);
        });
    }

    public function add(array $action): string
    {
        $id = uniqid('pa_', true);

        AtomicFileStorage::update($this->filePath, function (array $pending) use ($action, $id): array {
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

    public function resolve(string $id, bool $confirm): ?array
    {
        $found = null;

        AtomicFileStorage::update($this->filePath, function (array $pending) use ($id, &$found): array {
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

    public function hasPending(string $symbol, string $action): bool
    {
        $pending = AtomicFileStorage::read($this->filePath);
        foreach ($pending as $item) {
            if (($item['symbol'] ?? '') === $symbol && ($item['action'] ?? '') === $action) {
                return true;
            }
        }
        return false;
    }

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
