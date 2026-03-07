<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Storage for position plans (rotational grid).
 * Key format: SYMBOL|Side (e.g. BTCUSDT|Buy).
 */
interface PositionPlanStorageInterface
{
    /** @return array<string, array> Plans keyed by symbol|side */
    public function getAllPlans(): array;

    public function getPlan(string $symbol, string $side): ?array;

    public function savePlan(array $plan): void;

    public function removePlan(string $symbol, string $side): void;
}
