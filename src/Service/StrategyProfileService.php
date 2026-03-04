<?php

namespace App\Service;

/**
 * Selects strategy profile by timeframe + market regime.
 * scalp | intraday | swing | chop (no-trade)
 */
class StrategyProfileService
{
    public const PROFILE_SCALP    = 'scalp';
    public const PROFILE_INTRADAY = 'intraday';
    public const PROFILE_SWING    = 'swing';
    public const PROFILE_CHOP     = 'chop';

    /**
     * @param array{regime?: array{trend?: string, strength?: float, volatility?: string, chop_score?: float}} $signals
     */
    public function selectProfile(int $timeframeMinutes, array $signals): string
    {
        $regime   = $signals['regime'] ?? [];
        $chopScore = (float)($regime['chop_score'] ?? 0.5);
        $strength  = (float)($regime['strength'] ?? 0.5);
        $volatility = $regime['volatility'] ?? 'medium';

        if ($chopScore > 0.65) {
            return self::PROFILE_CHOP;
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
