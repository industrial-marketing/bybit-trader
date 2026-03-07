<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Circuit breaker state storage.
 * State format: [type => ['consecutive'=>int, 'tripped_at'=>?int, 'cooldown_until'=>?int, 'reason'=>str, 'last_failure_at'=>?int]]
 */
interface CircuitBreakerStorageInterface
{
    /** @return array<string, array> */
    public function getState(): array;

    /**
     * Atomic read-modify-write. Callback receives current state, returns new state.
     * @return array The new state after update
     */
    public function updateState(callable $callback): array;
}
