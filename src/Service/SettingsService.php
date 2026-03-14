<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Settings\DatabaseSettingsSource;

/**
 * Управляет настройками приложения. Всегда использует DatabaseSettingsSource (профили в БД).
 */
class SettingsService
{
    public function __construct(
        private readonly DatabaseSettingsSource $databaseSource,
    ) {
    }

    public function getSettings(): array
    {
        return $this->databaseSource->getSettings();
    }

    public function getBybitSettings(): array
    {
        return $this->databaseSource->getBybitSettings();
    }

    public function getChatGPTSettings(): array
    {
        return $this->databaseSource->getChatGPTSettings();
    }

    public function getDeepseekSettings(): array
    {
        return $this->databaseSource->getDeepseekSettings();
    }

    public function getTradingSettings(): array
    {
        return $this->databaseSource->getTradingSettings();
    }

    public function getAlertsSettings(): array
    {
        return $this->databaseSource->getAlertsSettings();
    }

    public function getStrategiesSettings(): array
    {
        return $this->databaseSource->getStrategiesSettings();
    }

    public function updateSettings(array $settings): void
    {
        $this->databaseSource->updateSettings($settings);
    }

    public function updateBybitSettings(array $settings): void
    {
        $this->databaseSource->updateBybitSettings($settings);
    }

    public function updateChatGPTSettings(array $settings): void
    {
        $this->databaseSource->updateChatGPTSettings($settings);
    }

    public function updateDeepseekSettings(array $settings): void
    {
        $this->databaseSource->updateDeepseekSettings($settings);
    }

    public function updateTradingSettings(array $settings): void
    {
        $this->databaseSource->updateTradingSettings($settings);
    }

    public function updateAlertsSettings(array $settings): void
    {
        $this->databaseSource->updateAlertsSettings($settings);
    }

    public function updateStrategiesSettings(array $settings): void
    {
        $this->databaseSource->updateStrategiesSettings($settings);
    }
}
