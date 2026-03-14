<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Entity\AiProviderConfig;
use App\Entity\BotSettings;
use App\Entity\ExchangeIntegration;
use App\Entity\TradingProfile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Settings source: MySQL (TradingProfile + ExchangeIntegration, AiProviderConfig, BotSettings).
 * Used when ProfileContext has activeProfileId set.
 */
class DatabaseSettingsSource implements SettingsSourceInterface
{
    private ?array $settings = null;
    private ?TradingProfile $profile = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
    ) {
    }

    private function loadProfile(): ?TradingProfile
    {
        $profileId = $this->profileContext->getActiveProfileId();
        if ($profileId === null) {
            return null;
        }

        if ($this->profile !== null && $this->profile->getId() === $profileId) {
            return $this->profile;
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
        if ($profile === null) {
            return null;
        }

        $this->profile = $profile;
        $this->settings = null;
        return $profile;
    }

    private function buildSettings(): array
    {
        $profile = $this->loadProfile();
        if ($profile === null) {
            return $this->getDefaults();
        }

        $defaults = $this->getDefaults();

        $bybit = $defaults['bybit'];
        $ex = $profile->getExchangeIntegration();
        if ($ex !== null) {
            $baseUrl = $ex->getBaseUrl();
            if ($baseUrl === null || $baseUrl === '') {
                $baseUrl = $ex->isTestnetMode()
                    ? 'https://api-testnet.bybit.com'
                    : 'https://api.bybit.com';
            }
            $bybit = [
                'api_key'    => $ex->getApiKey(),
                'api_secret' => $ex->getApiSecret(),
                'testnet'    => str_contains($baseUrl, 'testnet'),
                'base_url'   => $baseUrl,
            ];
        }
        // Do NOT override with env — each profile has its own Bybit keys; env would force all users to same account
        $bybit = array_merge($defaults['bybit'], $bybit);

        $chatgpt = $defaults['chatgpt'];
        $deepseek = $defaults['deepseek'];
        foreach ($profile->getAiProviderConfigs() as $ac) {
            if ($ac->getProvider() === AiProviderConfig::PROVIDER_OPENAI) {
                $chatgpt = [
                    'api_key' => $ac->getApiKey(),
                    'model'   => $ac->getModel(),
                    'enabled' => $ac->isEnabled(),
                    'timeout' => $ac->getTimeout() ?? 60,
                ];
                $chatgpt = array_merge($defaults['chatgpt'], $chatgpt);
            }
            if ($ac->getProvider() === AiProviderConfig::PROVIDER_DEEPSEEK) {
                $deepseek = [
                    'api_key' => $ac->getApiKey(),
                    'model'   => $ac->getModel(),
                    'enabled' => $ac->isEnabled(),
                    'timeout' => $ac->getTimeout() ?? 120,
                ];
                $deepseek = array_merge($defaults['deepseek'], $deepseek);
            }
        }

        $trading = $defaults['trading'];
        $alerts = $defaults['alerts'];
        $strategies = $defaults['strategies'];
        $bot = $profile->getBotSettings();
        if ($bot !== null) {
            if ($bot->getRiskSettings() !== null) {
                $trading = array_merge($trading, $bot->getRiskSettings());
            }
            if ($bot->getNotificationsSettings() !== null) {
                $alerts = array_merge($alerts, $bot->getNotificationsSettings());
            }
            if ($bot->getStrategySettings() !== null) {
                $strategies = array_merge($strategies, $bot->getStrategySettings());
            }
        }

        return [
            'bybit'     => $bybit,
            'chatgpt'   => $chatgpt,
            'deepseek'  => $deepseek,
            'trading'   => $trading,
            'alerts'    => $alerts,
            'strategies'=> $strategies,
        ];
    }

    private function applyEnvOverridesToSection(array &$section, string $key): void
    {
        $envMap = [
            'bybit'   => ['api_key' => 'BYBIT_API_KEY', 'api_secret' => 'BYBIT_API_SECRET'],
            'chatgpt' => ['api_key' => 'CHATGPT_API_KEY'],
            'deepseek'=> ['api_key' => 'DEEPSEEK_API_KEY'],
        ];
        $map = $envMap[$key] ?? [];
        foreach ($map as $field => $envName) {
            $envVal = $_ENV[$envName] ?? $_SERVER[$envName] ?? '';
            if ($envVal !== '') {
                $section[$field] = $envVal;
            }
        }
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
                'bybit_position_mode'      => 'one_way',
                'position_mode'            => 'single',
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
                'update_enabled'             => false,
                'update_interval_minutes'    => 60,
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
                'memory_enabled'              => false,
                'memory_write_enabled'        => false,
                'memory_top_k'                => 5,
                'memory_lookback_days'        => 90,
                'memory_include_cross_symbol'=> false,
                'memory_include_daily_summaries' => true,
                'memory_include_insights'     => true,
                'memory_max_tokens'           => 800,
                'memory_min_score'            => 0.5,
                'weights' => [
                    'trend'              => 1.0,
                    'mean_reversion'     => 0.7,
                    'breakout'           => 0.8,
                    'volatility_penalty' => 0.6,
                ],
            ],
        ];
    }

    private function getSettingsArray(): array
    {
        if ($this->settings === null) {
            $this->settings = $this->buildSettings();
        }
        return $this->settings;
    }

    public function isAvailable(): bool
    {
        return $this->loadProfile() !== null;
    }

    /** Clear cached profile/settings — force fresh load from DB on next get* call */
    public function clearProfileCache(): void
    {
        $this->profile = null;
        $this->settings = null;
    }

    public function getSettings(): array
    {
        return $this->getSettingsArray();
    }

    public function getBybitSettings(): array
    {
        return $this->getSettingsArray()['bybit'] ?? [];
    }

    public function getChatGPTSettings(): array
    {
        return $this->getSettingsArray()['chatgpt'] ?? [];
    }

    public function getDeepseekSettings(): array
    {
        return $this->getSettingsArray()['deepseek'] ?? [];
    }

    public function getTradingSettings(): array
    {
        return $this->getSettingsArray()['trading'] ?? [];
    }

    public function getAlertsSettings(): array
    {
        return $this->getSettingsArray()['alerts'] ?? [];
    }

    public function getStrategiesSettings(): array
    {
        return $this->getSettingsArray()['strategies'] ?? [];
    }

    public function updateSettings(array $settings): void
    {
        $profile = $this->loadProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot update settings: no active profile in context.');
        }

        if (isset($settings['bybit'])) {
            $this->updateBybitSettings($settings['bybit']);
        }
        if (isset($settings['chatgpt'])) {
            $this->updateChatGPTSettings($settings['chatgpt']);
        }
        if (isset($settings['deepseek'])) {
            $this->updateDeepseekSettings($settings['deepseek']);
        }
        if (isset($settings['trading'])) {
            $this->updateTradingSettings($settings['trading']);
        }
        if (isset($settings['alerts'])) {
            $this->updateAlertsSettings($settings['alerts']);
        }
        if (isset($settings['strategies'])) {
            $this->updateStrategiesSettings($settings['strategies']);
        }
    }

    public function updateBybitSettings(array $settings): void
    {
        $profile = $this->loadProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot update settings: no active profile in context.');
        }

        $ex = $profile->getExchangeIntegration();
        if ($ex === null) {
            $ex = new ExchangeIntegration();
            $ex->setTradingProfile($profile);
            $ex->setExchangeName(ExchangeIntegration::EXCHANGE_BYBIT);
            $profile->setExchangeIntegration($ex);
            $this->em->persist($ex);
        }

        if (isset($settings['api_key'])) {
            $ex->setApiKey((string) $settings['api_key']);
        }
        if (isset($settings['api_secret'])) {
            $ex->setApiSecret((string) $settings['api_secret']);
        }
        if (array_key_exists('base_url', $settings)) {
            $baseUrl = $settings['base_url'] !== '' ? (string) $settings['base_url'] : null;
            $ex->setBaseUrl($baseUrl);
            if ($baseUrl !== null) {
                $isTestnet = str_contains($baseUrl, 'testnet');
                $ex->setTestnetMode($isTestnet);
                // Sync profile.environment with base_url — single source of truth, avoids mainnet→testnet mismatch
                $profile->setEnvironment($isTestnet ? 'testnet' : 'mainnet');
            }
        }
        $ex->touch();
        $this->em->flush();
        $this->settings = null;
    }

    public function updateChatGPTSettings(array $settings): void
    {
        $this->updateAiProviderSettings(AiProviderConfig::PROVIDER_OPENAI, $settings);
    }

    public function updateDeepseekSettings(array $settings): void
    {
        $this->updateAiProviderSettings(AiProviderConfig::PROVIDER_DEEPSEEK, $settings);
    }

    private function updateAiProviderSettings(string $provider, array $settings): void
    {
        $profile = $this->loadProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot update settings: no active profile in context.');
        }

        $ac = null;
        foreach ($profile->getAiProviderConfigs() as $c) {
            if ($c->getProvider() === $provider) {
                $ac = $c;
                break;
            }
        }
        if ($ac === null) {
            $ac = new AiProviderConfig();
            $ac->setTradingProfile($profile);
            $ac->setProvider($provider);
            $ac->setIsDefault($provider === AiProviderConfig::PROVIDER_OPENAI);
            $profile->getAiProviderConfigs()->add($ac);
            $this->em->persist($ac);
        }

        if (isset($settings['api_key'])) {
            $ac->setApiKey((string) $settings['api_key']);
        }
        if (isset($settings['model'])) {
            $ac->setModel((string) $settings['model']);
        }
        if (isset($settings['enabled'])) {
            $ac->setEnabled((bool) $settings['enabled']);
        }
        if (isset($settings['timeout'])) {
            $ac->setTimeout((int) $settings['timeout']);
        }
        $ac->touch();
        $this->em->flush();
        $this->settings = null;
    }

    public function updateTradingSettings(array $settings): void
    {
        $profile = $this->loadProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot update settings: no active profile in context.');
        }

        $bot = $this->getOrCreateBotSettings($profile);
        $current = $bot->getRiskSettings() ?? [];
        $merged = array_merge($current, $settings);
        if (!isset($merged['min_position_usdt']) || $merged['min_position_usdt'] === '' || $merged['min_position_usdt'] === null) {
            $merged['min_position_usdt'] = 10.0;
        }
        $merged['min_position_usdt'] = max(0.0, (float) $merged['min_position_usdt']);
        $bot->setRiskSettings($merged);
        $bot->touch();
        $this->em->flush();
        $this->settings = null;
    }

    public function updateAlertsSettings(array $settings): void
    {
        $profile = $this->loadProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot update settings: no active profile in context.');
        }

        $bot = $this->getOrCreateBotSettings($profile);
        $current = $bot->getNotificationsSettings() ?? [];
        $bot->setNotificationsSettings(array_merge($current, $settings));
        $bot->touch();
        $this->em->flush();
        $this->settings = null;
    }

    public function updateStrategiesSettings(array $settings): void
    {
        $profile = $this->loadProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot update settings: no active profile in context.');
        }

        $bot = $this->getOrCreateBotSettings($profile);
        $current = $bot->getStrategySettings() ?? [];
        $bot->setStrategySettings(array_merge($current, $settings));
        $bot->touch();
        $this->em->flush();
        $this->settings = null;
    }

    private function getOrCreateBotSettings(TradingProfile $profile): BotSettings
    {
        $bot = $profile->getBotSettings();
        if ($bot === null) {
            $bot = new BotSettings();
            $bot->setTradingProfile($profile);
            $profile->setBotSettings($bot);
            $this->em->persist($bot);
        }
        return $bot;
    }
}
