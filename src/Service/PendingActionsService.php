<?php

namespace App\Service;

/**
 * Хранит действия, ожидающие подтверждения пользователя (двухфазное исполнение).
 *
 * Используется в строгом режиме (bot_strict_mode=true) для опасных действий:
 * CLOSE_FULL и AVERAGE_IN_ONCE.
 *
 * Файл: var/pending_actions.json
 * TTL записи: 60 минут — после этого запись считается устаревшей и удаляется.
 */
class PendingActionsService
{
    private const TTL_MINUTES = 60;
    private string $file;

    public function __construct()
    {
        $this->file = __DIR__ . '/../../var/pending_actions.json';
    }

    public function getAll(): array
    {
        $this->clearExpired();
        return $this->load();
    }

    public function add(array $action): string
    {
        $id      = uniqid('pa_', true);
        $pending = $this->load();

        $pending[] = array_merge($action, [
            'id'         => $id,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'status'     => 'pending',
        ]);

        $this->save($pending);
        return $id;
    }

    /**
     * Подтверждение или отклонение действия.
     * Возвращает запись, если найдена, null — если уже нет.
     */
    public function resolve(string $id, bool $confirm): ?array
    {
        $pending = $this->load();
        $found   = null;

        $filtered = [];
        foreach ($pending as $item) {
            if (($item['id'] ?? '') === $id) {
                $found = $item;
                // Не сохраняем — удаляем из списка независимо от confirm/reject
            } else {
                $filtered[] = $item;
            }
        }

        $this->save($filtered);
        return $found;
    }

    /** Есть ли уже ожидающее действие для данного символа+action? */
    public function hasPending(string $symbol, string $action): bool
    {
        foreach ($this->load() as $item) {
            if (($item['symbol'] ?? '') === $symbol && ($item['action'] ?? '') === $action) {
                return true;
            }
        }
        return false;
    }

    private function clearExpired(): void
    {
        $pending = $this->load();
        $now     = new \DateTimeImmutable();
        $ttl     = self::TTL_MINUTES * 60;

        $filtered = array_filter($pending, function (array $item) use ($now, $ttl): bool {
            try {
                $created = new \DateTimeImmutable($item['created_at'] ?? '');
                return ($now->getTimestamp() - $created->getTimestamp()) < $ttl;
            } catch (\Exception) {
                return false;
            }
        });

        if (count($filtered) !== count($pending)) {
            $this->save(array_values($filtered));
        }
    }

    private function load(): array
    {
        if (!file_exists($this->file)) {
            return [];
        }
        return json_decode(file_get_contents($this->file), true) ?? [];
    }

    private function save(array $data): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->file, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
