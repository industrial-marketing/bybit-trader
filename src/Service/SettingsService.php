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
        return $this->getSource()->getSettings();
    }

    public function getBybitSettings(): array
    {
        return $this->getSource()->getBybitSettings();
    }

    public function getChatGPTSettings(): array
    {
        return $this->getSource()->getChatGPTSettings();
    }

    public function getDeepseekSettings(): array
    {
        return $this->getSource()->getDeepseekSettings();
    }

    public function getTradingSettings(): array
    {
        return $this->getSource()->getTradingSettings();
    }

    public function getAlertsSettings(): array
    {
        return $this->getSource()->getAlertsSettings();
    }

    public function getStrategiesSettings(): array
    {
        return $this->getSource()->getStrategiesSettings();
    }

    public function updateSettings(array $settings): void
    {
        $this->getSource()->updateSettings($settings);
    }

    public function updateBybitSettings(array $settings): void
    {
        $this->getSource()->updateBybitSettings($settings);
    }

    public function updateChatGPTSettings(array $settings): void
    {
        $this->getSource()->updateChatGPTSettings($settings);
    }

    public function updateDeepseekSettings(array $settings): void
    {
        $this->getSource()->updateDeepseekSettings($settings);
    }

    public function updateTradingSettings(array $settings): void
    {
        $this->getSource()->updateTradingSettings($settings);
    }

    public function updateAlertsSettings(array $settings): void
    {
        $this->getSource()->updateAlertsSettings($settings);
    }

    public function updateStrategiesSettings(array $settings): void
    {
        $this->getSource()->updateStrategiesSettings($settings);
    }
}
