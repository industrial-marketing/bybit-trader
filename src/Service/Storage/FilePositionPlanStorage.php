<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\AtomicFileStorage;

class FilePositionPlanStorage implements PositionPlanStorageInterface
{
    private string $filePath;

    public function __construct(string $projectDir)
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($projectDir . DIRECTORY_SEPARATOR . 'var');
        $this->filePath = rtrim($varDir, '/\\') . DIRECTORY_SEPARATOR . 'position_plans.json';
    }

    private function key(string $symbol, string $side): string
    {
        return strtoupper($symbol) . '|' . ucfirst(strtolower($side));
    }

    public function getAllPlans(): array
    {
        $data = AtomicFileStorage::read($this->filePath);
        return is_array($data) ? $data : [];
    }

    public function getPlan(string $symbol, string $side): ?array
    {
        $plans = $this->getAllPlans();
        $k = $this->key($symbol, $side);
        return $plans[$k] ?? null;
    }

    public function savePlan(array $plan): void
    {
        $symbol = $plan['symbol'] ?? '';
        $side = $plan['side'] ?? '';
        $k = $this->key($symbol, $side);

        AtomicFileStorage::update($this->filePath, function (array $plans) use ($k, $plan): array {
            $plans[$k] = $plan;
            return $plans;
        });
    }

    public function removePlan(string $symbol, string $side): void
    {
        $k = $this->key($symbol, $side);

        AtomicFileStorage::update($this->filePath, function (array $plans) use ($k): array {
            unset($plans[$k]);
            return $plans;
        });
    }
}
