<?php

declare(strict_types=1);

namespace App\Service\Settings;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Settings source: var/settings.json.
 * Applies env overrides for API keys (BYBIT_*, CHATGPT_*, DEEPSEEK_*).
 * For logged-in users, Bybit env overrides are NOT applied — each user must use their own profile keys.
 */
class FileSettingsSource implements SettingsSourceInterface
{
    private array $settings = [];
    private string $settingsPath;

    public function __construct(
        string $projectDir,
        private readonly ?Security $security = null,
    ) {
        $this->settingsPath = $projectDir . '/var/settings.json';
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        if (is_file($this->settingsPath)) {
            $content = file_get_contents($this->settingsPath);
            $this->settings = json_decode($content, true) ?? [];
        }

        $this->settings = array_replace_recursive($this->getDefaults(), $this->settings);
        $this->applyEnvOverrides();
    }

    private function getDefaults(): array
    {
        return [
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
                'timeout' => 60,
            ],
            'deepseek' => [
                'api_key' => '',
                'model'   => 'deepseek-chat',
                'enabled' => false,
                'timeout' => 120,
            ],
            'trading' => [
                'max_position_usdt'        => 100.0,
                'min_position_usdt'        => 10.0,
                'min_leverage'             => 1,
                'max_leverage'             => 5,
                'required_margin_mode'     => 'auto',
                'position_mode'            => 'single',
                'max_layers'               => 3,
                'layer_size_usdt'          => 50.0,
                'grid_step_pct'            => 5.0,
                'grid_reentry_enabled'     => true,
                'unload_on_reclaim_level'  => true,
                'base_layer_persistent'    => true,
                'rotation_allowed_in_chop' => true,
                'rotation_allowed_in_trend'=> false,
                'aggressiveness'           => 'balanced',
                'max_managed_positions'    => 10,
                'auto_open_min_positions'  => 5,
                'auto_open_enabled'        => false,
                'bot_timeframe'            => 5,
                'bot_history_candles'      => 60,
                'trading_enabled'          => true,
                'daily_loss_limit_usdt'    => 0.0,
                'max_total_exposure_usdt'  => 0.0,
                'action_cooldown_minutes'  => 30,
                'bot_strict_mode'          => false,
            ],
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
                'repeated_failure_cooldown_minutes' => 60,
            ],
            'strategies' => [
                'enabled'           => true,
                'profile_overrides' => [
                    '1'   => 'scalp', '5' => 'scalp',
                    '15'  => 'intraday', '60' => 'intraday',
                    '240' => 'swing', '1440' => 'swing',
                ],
                'indicators' => [
                    'ema'   => ['enabled' => true, 'fast' => 20, 'slow' => 50],
                    'rsi14' => ['enabled' => true, 'overbought' => 70, 'oversold' => 30],
                    'atr'   => ['enabled' => true, 'period' => 14],
                    'chop'  => ['enabled' => true, 'threshold' => 0.65],
                ],
                'rules' => [
                    'allow_average_in'        => true,
                    'average_in_block_in_chop'=> true,
                    'prefer_be_in_trend'     => true,
                ],
                'weights' => [
                    'trend'              => 1.0,
                    'mean_reversion'     => 0.7,
                    'breakout'           => 0.8,
                    'volatility_penalty' => 0.6,
                ],
            ],
        ];
    }

    private function applyEnvOverrides(): void
    {
        $envBybitKey    = $_ENV['BYBIT_API_KEY']    ?? $_SERVER['BYBIT_API_KEY']    ?? '';
        $envBybitSecret = $_ENV['BYBIT_API_SECRET'] ?? $_SERVER['BYBIT_API_SECRET'] ?? '';
        $envChatGpt     = $_ENV['CHATGPT_API_KEY']  ?? $_SERVER['CHATGPT_API_KEY']  ?? '';
        $envDeepseek    = $_ENV['DEEPSEEK_API_KEY'] ?? $_SERVER['DEEPSEEK_API_KEY'] ?? '';

        // Never apply Bybit env overrides for logged-in users — each user must use their own profile keys
        $user = $this->security?->getUser();
        if ($user === null) {
            if ($envBybitKey !== '') {
                $this->settings['bybit']['api_key'] = $envBybitKey;
            }
            if ($envBybitSecret !== '') {
                $this->settings['bybit']['api_secret'] = $envBybitSecret;
            }
        }
        if ($envChatGpt !== '') {
            $this->settings['chatgpt']['api_key'] = $envChatGpt;
        }
        if ($envDeepseek !== '') {
            $this->settings['deepseek']['api_key'] = $envDeepseek;
        }
    }

    private function saveSettings(): void
    {
        $toSave = $this->settings;

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

        $dir = dirname($this->settingsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $json = json_encode($toSave, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Ошибка сериализации настроек: ' . json_last_error_msg());
        }
        if (file_put_contents($this->settingsPath, $json) === false) {
            throw new \RuntimeException('Не удалось записать var/settings.json. Проверьте права на каталог var/.');
        }
    }

    private function isEnvKey(string $envName, string $value): bool
    {
        $envVal = $_ENV[$envName] ?? $_SERVER[$envName] ?? '';
        return $envVal !== '' && $envVal === $value;
    }

    public function isAvailable(): bool
    {
        return is_dir(dirname($this->settingsPath)) || is_file($this->settingsPath);
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

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

    public function getAlertsSettings(): array
    {
        return $this->settings['alerts'] ?? [];
    }

    public function getStrategiesSettings(): array
    {
        return $this->settings['strategies'] ?? [];
    }

    public function updateSettings(array $settings): void
    {
        $this->settings = array_merge($this->settings, $settings);
        $this->saveSettings();
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
        $current = $this->settings['trading'] ?? [];
        $merged  = array_merge($current, $settings);
        if (!isset($merged['min_position_usdt']) || $merged['min_position_usdt'] === '' || $merged['min_position_usdt'] === null) {
            $merged['min_position_usdt'] = 10.0;
        }
        $merged['min_position_usdt'] = max(0.0, (float) $merged['min_position_usdt']);
        $this->settings['trading'] = $merged;
        $this->saveSettings();
    }

    public function updateAlertsSettings(array $settings): void
    {
        $this->settings['alerts'] = array_merge($this->settings['alerts'] ?? [], $settings);
        $this->saveSettings();
    }

    public function updateStrategiesSettings(array $settings): void
    {
        $this->settings['strategies'] = array_merge($this->settings['strategies'] ?? [], $settings);
        $this->saveSettings();
    }
}
