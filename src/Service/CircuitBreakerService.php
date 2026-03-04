<?php

namespace App\Service;

/**
 * Circuit Breaker — автоматическая пауза торговли при серии сбоев.
 *
 * Три независимых автомата:
 *  - 'bybit'       : ошибки Bybit API
 *  - 'llm'         : сбои LLM (нет ответа / таймаут)
 *  - 'llm_invalid' : невалидные ответы LLM (плохой JSON / нарушение схемы)
 *
 * Жизненный цикл:
 *  CLOSED  → consecutive failures < threshold    (нормальная работа)
 *  OPEN    → threshold превышен, cooldown активен  (торговля заблокирована)
 *  → auto-CLOSE после истечения cooldown_minutes  (TTL-based recovery)
 *  → ручной reset через reset() или API
 *
 * Состояние хранится в var/circuit_breaker.json (AtomicFileStorage).
 */
class CircuitBreakerService
{
    public const TYPE_BYBIT       = 'bybit';
    public const TYPE_LLM         = 'llm';
    public const TYPE_LLM_INVALID = 'llm_invalid';

    private const ALL_TYPES = [self::TYPE_BYBIT, self::TYPE_LLM, self::TYPE_LLM_INVALID];

    private const STATE_FILE = 'var/circuit_breaker.json';

    private const DEFAULTS = [
        'consecutive'    => 0,
        'tripped_at'     => null,
        'cooldown_until' => null,
        'reason'         => '',
        'last_failure_at'=> null,
    ];

    public function __construct(
        private readonly SettingsService $settingsService,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────

    /**
     * Record one failure of the given type.
     * Returns true if this failure tripped the circuit.
     */
    public function recordFailure(string $type, string $reason = ''): bool
    {
        if (!in_array($type, self::ALL_TYPES, true)) {
            return false;
        }

        $cfg       = $this->cfg();
        $threshold = $this->thresholdFor($type, $cfg);

        return AtomicFileStorage::update(self::STATE_FILE, function (array $state) use ($type, $reason, $threshold, $cfg): array {
            $entry = array_merge(self::DEFAULTS, $state[$type] ?? []);

            $now = time();

            // If already tripped and cooldown still active → just update last_failure_at
            if ($entry['cooldown_until'] !== null && $now < (int)$entry['cooldown_until']) {
                $entry['last_failure_at'] = $now;
                $state[$type] = $entry;
                return $state;
            }

            // Cooldown expired → reset trip state but keep counting
            if ($entry['cooldown_until'] !== null && $now >= (int)$entry['cooldown_until']) {
                $entry['tripped_at']     = null;
                $entry['cooldown_until'] = null;
                $entry['consecutive']    = 0;
            }

            $entry['consecutive']     = ($entry['consecutive'] ?? 0) + 1;
            $entry['last_failure_at'] = $now;
            if ($reason !== '') {
                $entry['reason'] = $reason;
            }

            // Trip?
            if ($entry['consecutive'] >= $threshold) {
                $cooldownSec             = max(1, (int)($cfg['cb_cooldown_minutes'] ?? 30)) * 60;
                $entry['tripped_at']     = $now;
                $entry['cooldown_until'] = $now + $cooldownSec;
                $entry['reason']         = $reason !== ''
                    ? $reason
                    : "{$entry['consecutive']} consecutive {$type} failures";
            }

            $state[$type] = $entry;
            return $state;
        }) !== [];
    }

    /**
     * Record a success — resets consecutive counter (only while NOT tripped).
     */
    public function recordSuccess(string $type): void
    {
        if (!in_array($type, self::ALL_TYPES, true)) {
            return;
        }

        AtomicFileStorage::update(self::STATE_FILE, function (array $state) use ($type): array {
            $entry = array_merge(self::DEFAULTS, $state[$type] ?? []);

            // Never reset while circuit is still open (respect cooldown)
            if ($entry['cooldown_until'] !== null && time() < (int)$entry['cooldown_until']) {
                return $state;
            }

            // Reset consecutive failures
            $entry['consecutive']    = 0;
            $entry['tripped_at']     = null;
            $entry['cooldown_until'] = null;
            $state[$type] = $entry;
            return $state;
        });
    }

    /**
     * Returns true if ANY circuit is currently open (tripped + cooldown active).
     */
    public function isOpen(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        foreach (self::ALL_TYPES as $type) {
            if ($this->isTypeOpen($type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Full status array for API/UI.
     */
    public function getStatus(): array
    {
        $cfg     = $this->cfg();
        $enabled = $this->isEnabled();
        $state   = AtomicFileStorage::read(self::STATE_FILE);
        $now     = time();

        $breakers    = [];
        $anyOpen     = false;
        $openReasons = [];

        foreach (self::ALL_TYPES as $type) {
            $entry        = array_merge(self::DEFAULTS, $state[$type] ?? []);
            $coolUntil    = $entry['cooldown_until'] !== null ? (int)$entry['cooldown_until'] : null;
            $isOpen       = $enabled && $coolUntil !== null && $now < $coolUntil;
            $remainingSec = $isOpen ? ($coolUntil - $now) : 0;

            if ($isOpen) {
                $anyOpen       = true;
                $openReasons[] = "[{$type}] " . ($entry['reason'] ?: "{$entry['consecutive']} failures");
            }

            $breakers[$type] = [
                'open'              => $isOpen,
                'consecutive'       => (int)($entry['consecutive'] ?? 0),
                'threshold'         => $this->thresholdFor($type, $cfg),
                'tripped_at'        => $entry['tripped_at'] !== null
                    ? date('Y-m-d H:i:s', (int)$entry['tripped_at'])
                    : null,
                'cooldown_until'    => $coolUntil !== null
                    ? date('Y-m-d H:i:s', $coolUntil)
                    : null,
                'remaining_sec'     => $remainingSec,
                'remaining_human'   => $remainingSec > 0 ? $this->humanDuration($remainingSec) : null,
                'reason'            => $entry['reason'] ?: '',
                'last_failure_at'   => $entry['last_failure_at'] !== null
                    ? date('Y-m-d H:i:s', (int)$entry['last_failure_at'])
                    : null,
            ];
        }

        $message = '';
        if (!$enabled) {
            $message = 'Circuit breaker disabled.';
        } elseif ($anyOpen) {
            $longestRemaining = max(array_column(array_values($breakers), 'remaining_sec'));
            $message          = sprintf(
                'Paused by circuit breaker: %s. Resume in %s.',
                implode('; ', $openReasons),
                $this->humanDuration($longestRemaining)
            );
        }

        return [
            'enabled'         => $enabled,
            'is_open'         => $anyOpen,
            'message'         => $message,
            'breakers'        => $breakers,
            'cooldown_minutes'=> (int)($cfg['cb_cooldown_minutes'] ?? 30),
        ];
    }

    /**
     * Manually reset one or all circuits.
     */
    public function reset(?string $type = null): void
    {
        AtomicFileStorage::update(self::STATE_FILE, function (array $state) use ($type): array {
            $types = $type !== null ? [$type] : self::ALL_TYPES;
            foreach ($types as $t) {
                $state[$t] = self::DEFAULTS;
            }
            return $state;
        });
    }

    // ──────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────

    private function isTypeOpen(string $type): bool
    {
        $state = AtomicFileStorage::read(self::STATE_FILE);
        $entry = array_merge(self::DEFAULTS, $state[$type] ?? []);
        if ($entry['cooldown_until'] === null) {
            return false;
        }
        return time() < (int)$entry['cooldown_until'];
    }

    private function isEnabled(): bool
    {
        return (bool)($this->cfg()['cb_enabled'] ?? true);
    }

    private function cfg(): array
    {
        return $this->settingsService->getTradingSettings();
    }

    private function thresholdFor(string $type, array $cfg): int
    {
        return match ($type) {
            self::TYPE_BYBIT       => max(1, (int)($cfg['cb_bybit_threshold']       ?? 5)),
            self::TYPE_LLM         => max(1, (int)($cfg['cb_llm_threshold']          ?? 3)),
            self::TYPE_LLM_INVALID => max(1, (int)($cfg['cb_llm_invalid_threshold']  ?? 5)),
            default                => 5,
        };
    }

    private function humanDuration(int $sec): string
    {
        if ($sec >= 3600) {
            return round($sec / 3600, 1) . 'h';
        }
        if ($sec >= 60) {
            return round($sec / 60) . 'm';
        }
        return $sec . 's';
    }
}
