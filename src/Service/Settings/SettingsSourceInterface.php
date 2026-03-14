<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Abstraction for settings storage.
 * Implementation: DatabaseSettingsSource (MySQL). File storage removed.
 */
interface SettingsSourceInterface
{
    public function getSettings(): array;

    public function getBybitSettings(): array;

    public function getChatGPTSettings(): array;

    public function getDeepseekSettings(): array;

    public function getTradingSettings(): array;

    public function getAlertsSettings(): array;

    public function getStrategiesSettings(): array;

    public function updateSettings(array $settings): void;

    public function updateBybitSettings(array $settings): void;

    public function updateChatGPTSettings(array $settings): void;

    public function updateDeepseekSettings(array $settings): void;

    public function updateTradingSettings(array $settings): void;

    public function updateAlertsSettings(array $settings): void;

    public function updateStrategiesSettings(array $settings): void;

    /** Whether this source is available (e.g. DB connected, file exists) */
    public function isAvailable(): bool;
}
