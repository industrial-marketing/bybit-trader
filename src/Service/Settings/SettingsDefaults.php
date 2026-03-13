<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Default settings structure shared by FileSettingsSource and DatabaseSettingsSource.
 */
final class SettingsDefaults
{
    public static function get(): array
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
                'bybit_position_mode'      => 'one_way',  // one_way | hedge — match your Bybit account
                'max_layers'               => 3,
                'layer_size_usdt'          => 50.0,
                'grid_step_pct'            => 5.0,
                'grid_reentry_enabled'     => true,
                'unload_on_reclaim_level'  => true,
                'base_layer_persistent'    => true,
                'rotation_allowed_in_chop' => true,
                'rotation_allowed_in_trend'=> false,
                'rotation_always_active'   => false,
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
                'update_enabled'             => false,
                'update_interval_minutes'    => 60,
            ],
            'strategies' => [
                'enabled'           => true,
                'profile_overrides'  => [
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
                    'allow_average_in'       => true,
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

    public static function applyEnvOverrides(array $settings): array
    {
        $envBybitKey    = $_ENV['BYBIT_API_KEY']    ?? $_SERVER['BYBIT_API_KEY']    ?? '';
        $envBybitSecret = $_ENV['BYBIT_API_SECRET'] ?? $_SERVER['BYBIT_API_SECRET'] ?? '';
        $envChatGpt     = $_ENV['CHATGPT_API_KEY']  ?? $_SERVER['CHATGPT_API_KEY']  ?? '';
        $envDeepseek    = $_ENV['DEEPSEEK_API_KEY'] ?? $_SERVER['DEEPSEEK_API_KEY'] ?? '';

        if ($envBybitKey !== '') {
            $settings['bybit']['api_key'] = $envBybitKey;
        }
        if ($envBybitSecret !== '') {
            $settings['bybit']['api_secret'] = $envBybitSecret;
        }
        if ($envChatGpt !== '') {
            $settings['chatgpt']['api_key'] = $envChatGpt;
        }
        if ($envDeepseek !== '') {
            $settings['deepseek']['api_key'] = $envDeepseek;
        }

        return $settings;
    }

    public static function stripEnvKeysForSave(array $settings): array
    {
        $toSave = $settings;
        if (($settings['bybit']['api_key'] ?? '') !== '' && self::isEnvKey('BYBIT_API_KEY', $settings['bybit']['api_key'])) {
            $toSave['bybit']['api_key'] = '';
        }
        if (($settings['bybit']['api_secret'] ?? '') !== '' && self::isEnvKey('BYBIT_API_SECRET', $settings['bybit']['api_secret'])) {
            $toSave['bybit']['api_secret'] = '';
        }
        if (($settings['chatgpt']['api_key'] ?? '') !== '' && self::isEnvKey('CHATGPT_API_KEY', $settings['chatgpt']['api_key'])) {
            $toSave['chatgpt']['api_key'] = '';
        }
        if (($settings['deepseek']['api_key'] ?? '') !== '' && self::isEnvKey('DEEPSEEK_API_KEY', $settings['deepseek']['api_key'])) {
            $toSave['deepseek']['api_key'] = '';
        }
        return $toSave;
    }

    private static function isEnvKey(string $envName, string $value): bool
    {
        $envVal = $_ENV[$envName] ?? $_SERVER[$envName] ?? '';
        return $envVal !== '' && $envVal === $value;
    }
}
