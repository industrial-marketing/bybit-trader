<?php

namespace App\Service;

/**
 * Управляет настройками приложения.
 *
 * Приоритет API-ключей (от высшего к низшему):
 *   1. Переменные окружения (.env.local): BYBIT_API_KEY, BYBIT_API_SECRET, CHATGPT_API_KEY, DEEPSEEK_API_KEY
 *   2. var/settings.json (вводятся через UI настроек)
 *
 * Торговые параметры хранятся только в var/settings.json.
 */
class SettingsService
{
    private array $settings = [];

    public function __construct()
    {
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        $settingsFile = __DIR__ . '/../../var/settings.json';
        if (file_exists($settingsFile)) {
            $content = file_get_contents($settingsFile);
            $this->settings = json_decode($content, true) ?? [];
        }

        // Значения по умолчанию
        $defaults = [
            'bybit' => [
                'api_key'    => '',
                'api_secret' => '',
                'testnet'    => true,
                'base_url'   => 'https://api-testnet.bybit.com',
            ],
            'chatgpt' => [
                'api_key' => '',
                'model'   => 'gpt-4',
                'enabled' => false,
            ],
            'deepseek' => [
                'api_key' => '',
                'model'   => 'deepseek-chat',
                'enabled' => false,
            ],
            'trading' => [
                'max_position_usdt'        => 100.0,
                'min_leverage'             => 1,
                'max_leverage'             => 5,
                'aggressiveness'           => 'balanced',
                'max_managed_positions'    => 10,
                'auto_open_min_positions'  => 5,
                'auto_open_enabled'        => false,
                'bot_timeframe'            => 5,
                'bot_history_candles'      => 60,
                // ── Risk Guards ──────────────────────────────────────────
                'trading_enabled'          => true,
                'daily_loss_limit_usdt'    => 0.0,
                'max_total_exposure_usdt'  => 0.0,
                'action_cooldown_minutes'  => 30,
                'bot_strict_mode'          => false,
            ],
            // ── Alerts ───────────────────────────────────────────────────
            'alerts' => [
                'telegram_bot_token'         => '',
                'telegram_chat_id'           => '',
                'webhook_url'                => '',
                'on_llm_failure'             => true,
                'on_invalid_response'        => true,
                'on_risk_limit'              => true,
                'on_bybit_error'             => false,
                'on_repeated_failures'       => true,
                'repeated_failure_threshold' => 3,
            ],
        ];

        $this->settings = array_replace_recursive($defaults, $this->settings);

        // Перекрываем API-ключи переменными окружения (если заданы и не пустые)
        $this->applyEnvOverrides();

        $this->saveSettings();
    }

    /**
     * Если переменная окружения задана и не пуста — она перекрывает значение из settings.json.
     * Сами ключи из env в settings.json НЕ сохраняются (saveSettings пропускает их).
     */
    private function applyEnvOverrides(): void
    {
        $envBybitKey    = $_ENV['BYBIT_API_KEY']    ?? $_SERVER['BYBIT_API_KEY']    ?? '';
        $envBybitSecret = $_ENV['BYBIT_API_SECRET'] ?? $_SERVER['BYBIT_API_SECRET'] ?? '';
        $envChatGpt     = $_ENV['CHATGPT_API_KEY']  ?? $_SERVER['CHATGPT_API_KEY']  ?? '';
        $envDeepseek    = $_ENV['DEEPSEEK_API_KEY'] ?? $_SERVER['DEEPSEEK_API_KEY'] ?? '';

        if ($envBybitKey !== '') {
            $this->settings['bybit']['api_key'] = $envBybitKey;
        }
        if ($envBybitSecret !== '') {
            $this->settings['bybit']['api_secret'] = $envBybitSecret;
        }
        if ($envChatGpt !== '') {
            $this->settings['chatgpt']['api_key'] = $envChatGpt;
        }
        if ($envDeepseek !== '') {
            $this->settings['deepseek']['api_key'] = $envDeepseek;
        }
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Возвращает настройки Bybit.
     * API-ключи берутся из env (если заданы) или из settings.json.
     */
    public function getBybitSettings(): array
    {
        return $this->settings['bybit'] ?? [];
    }

    public function getChatGPTSettings(): array
    {
        return $this->settings['chatgpt'] ?? [];
    }

    public function getDeepseekSettings(): array
    {
        return $this->settings['deepseek'] ?? [];
    }

    public function getTradingSettings(): array
    {
        return $this->settings['trading'] ?? [];
    }

    public function updateSettings(array $settings): void
    {
        $this->settings = array_merge($this->settings, $settings);
        $this->saveSettings();
        // Повторно применяем env-override, чтобы env всегда имел приоритет
        $this->applyEnvOverrides();
    }

    public function updateBybitSettings(array $settings): void
    {
        $this->settings['bybit'] = array_merge($this->settings['bybit'] ?? [], $settings);
        $this->saveSettings();
        $this->applyEnvOverrides();
    }

    public function updateChatGPTSettings(array $settings): void
    {
        $this->settings['chatgpt'] = array_merge($this->settings['chatgpt'] ?? [], $settings);
        $this->saveSettings();
        $this->applyEnvOverrides();
    }

    public function updateDeepseekSettings(array $settings): void
    {
        $this->settings['deepseek'] = array_merge($this->settings['deepseek'] ?? [], $settings);
        $this->saveSettings();
        $this->applyEnvOverrides();
    }

    public function updateTradingSettings(array $settings): void
    {
        $this->settings['trading'] = array_merge($this->settings['trading'] ?? [], $settings);
        $this->saveSettings();
    }

    public function getAlertsSettings(): array
    {
        return $this->settings['alerts'] ?? [];
    }

    public function updateAlertsSettings(array $settings): void
    {
        $this->settings['alerts'] = array_merge($this->settings['alerts'] ?? [], $settings);
        $this->saveSettings();
    }

    /**
     * Сохраняет настройки в файл.
     * API-ключи из env-переменных НЕ сохраняются в файл
     * (чтобы не дублировать секреты на диске).
     */
    private function saveSettings(): void
    {
        $toSave = $this->settings;

        // Если ключ пришёл из env — не пишем его в файл (обнуляем)
        if (($this->settings['bybit']['api_key'] ?? '') !== '' && $this->isEnvKey('BYBIT_API_KEY', $this->settings['bybit']['api_key'])) {
            $toSave['bybit']['api_key'] = '';
        }
        if (($this->settings['bybit']['api_secret'] ?? '') !== '' && $this->isEnvKey('BYBIT_API_SECRET', $this->settings['bybit']['api_secret'])) {
            $toSave['bybit']['api_secret'] = '';
        }
        if (($this->settings['chatgpt']['api_key'] ?? '') !== '' && $this->isEnvKey('CHATGPT_API_KEY', $this->settings['chatgpt']['api_key'])) {
            $toSave['chatgpt']['api_key'] = '';
        }
        if (($this->settings['deepseek']['api_key'] ?? '') !== '' && $this->isEnvKey('DEEPSEEK_API_KEY', $this->settings['deepseek']['api_key'])) {
            $toSave['deepseek']['api_key'] = '';
        }

        $settingsFile = __DIR__ . '/../../var/settings.json';
        $dir = dirname($settingsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($settingsFile, json_encode($toSave, JSON_PRETTY_PRINT));
    }

    /**
     * Проверяет, совпадает ли значение с переменной окружения.
     */
    private function isEnvKey(string $envName, string $value): bool
    {
        $envVal = $_ENV[$envName] ?? $_SERVER[$envName] ?? '';
        return $envVal !== '' && $envVal === $value;
    }
}
