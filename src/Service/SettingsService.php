<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Settings\DatabaseSettingsSource;
use App\Service\Settings\FileSettingsSource;
use App\Service\Settings\ProfileContext;
use App\Service\Settings\SettingsSourceInterface;

/**
 * Управляет настройками приложения.
 *
 * Источник: FileSettingsSource (var/settings.json) при ProfileContext.useFileSettings(),
 * иначе DatabaseSettingsSource (MySQL по активному профилю).
 *
 * Приоритет API-ключей (от высшего к низшему):
 *   1. Переменные окружения (.env.local): BYBIT_API_KEY, BYBIT_API_SECRET, CHATGPT_API_KEY, DEEPSEEK_API_KEY
 *   2. Настройки из выбранного источника (файл или БД)
 */
class SettingsService
{
    public function __construct(
        private readonly ProfileContext $profileContext,
        private readonly FileSettingsSource $fileSource,
        private readonly DatabaseSettingsSource $databaseSource,
    ) {
    }

    private function getSource(): SettingsSourceInterface
    {
        return $this->profileContext->useFileSettings()
            ? $this->fileSource
            : $this->databaseSource;
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
