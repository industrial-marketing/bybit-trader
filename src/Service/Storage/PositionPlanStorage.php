<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Settings\ProfileContext;

/**
 * Facade: delegates to File or DB storage based on ProfileContext.
 */
class PositionPlanStorage implements PositionPlanStorageInterface
{
    public function __construct(
        private readonly ProfileContext $profileContext,
        private readonly FilePositionPlanStorage $fileStorage,
        private readonly DbPositionPlanStorage $dbStorage,
    ) {
    }

    private function getStorage(): PositionPlanStorageInterface
    {
        return $this->profileContext->useFileSettings()
            ? $this->fileStorage
            : $this->dbStorage;
    }

    public function getAllPlans(): array
    {
        return $this->getStorage()->getAllPlans();
    }

    public function getPlan(string $symbol, string $side): ?array
    {
        return $this->getStorage()->getPlan($symbol, $side);
    }

    public function savePlan(array $plan): void
    {
        $this->getStorage()->savePlan($plan);
    }

    public function removePlan(string $symbol, string $side): void
    {
        $this->getStorage()->removePlan($symbol, $side);
    }
}
