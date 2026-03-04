<?php

namespace App\Service;

/**
 * Selects strategy profile by timeframe + market regime.
 * scalp | intraday | swing | chop (no-trade)
 * Uses profile_overrides from settings when available.
 */
class StrategyProfileService
{
    public const PROFILE_SCALP    = 'scalp';
    public const PROFILE_INTRADAY = 'intraday';
    public const PROFILE_SWING    = 'swing';
    public const PROFILE_CHOP     = 'chop';

    public function __construct(
        private readonly SettingsService $settingsService,
    ) {}

    /**
     * @param array{regime?: array{trend?: string, strength?: float, volatility?: string, chop_score?: float, chop_threshold?: float}} $signals
     */
    public function selectProfile(int $timeframeMinutes, array $signals): string
    {
        $strategies = $this->settingsService->getStrategiesSettings();
        $overrides  = $strategies['profile_overrides'] ?? [];
        $chopTh     = (float)($strategies['indicators']['chop']['threshold'] ?? $signals['regime']['chop_threshold'] ?? 0.65);

        $regime    = $signals['regime'] ?? [];
        $chopScore = (float)($regime['chop_score'] ?? 0.5);

        if ($chopScore >= $chopTh) {
            return self::PROFILE_CHOP;
        }

        $tfKey = (string)$timeframeMinutes;
        if (isset($overrides[$tfKey]) && $overrides[$tfKey] !== '') {
            $p = strtolower(trim($overrides[$tfKey]));
            if (in_array($p, [self::PROFILE_SCALP, self::PROFILE_INTRADAY, self::PROFILE_SWING, self::PROFILE_CHOP], true)) {
                return $p;
            }
        }

        if ($timeframeMinutes <= 5) {
            return self::PROFILE_SCALP;
        }
        if ($timeframeMinutes <= 60) {
            return self::PROFILE_INTRADAY;
        }
        return self::PROFILE_SWING;
    }
}
