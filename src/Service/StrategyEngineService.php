<?php

namespace App\Service;

/**
 * Builds StrategySignals from kline OHLCV data.
 * Features provider for LLM — not execution logic.
 */
class StrategyEngineService
{
    public function __construct(
        private readonly BybitService $bybitService,
    ) {}

    /**
     * @param array{closes: float[], highs: float[], lows: float[]} $kline
     */
    public function buildSignals(string $symbol, int $timeframeMinutes, array $kline): array
    {
        $closes = $kline['closes'] ?? [];
        $highs  = $kline['highs'] ?? [];
        $lows   = $kline['lows'] ?? [];

        if (count($closes) < 20) {
            return $this->emptySignals();
        }

        $rsi       = IndicatorService::rsi($closes, 14);
        $ema20     = IndicatorService::ema($closes, 20);
        $ema50     = IndicatorService::ema($closes, 50);
        $atrPct    = IndicatorService::atrPct($highs, $lows, $closes, 14);
        $trendStr  = IndicatorService::trendStrength($closes, 20);
        $chopScore = IndicatorService::chopScore($highs, $lows, $closes, 20);

        $lastClose = end($closes);
        $emaState  = 'neutral';
        $emaSlope  = 0.0;
        if ($ema20 !== null && $ema50 !== null && $lastClose > 0) {
            if ($ema20 > $ema50 * 1.0005) {
                $emaState = 'bull';
                $emaSlope = $ema50 > 0 ? (($ema20 - $ema50) / $ema50) : 0;
            } elseif ($ema20 < $ema50 * 0.9995) {
                $emaState = 'bear';
                $emaSlope = $ema50 > 0 ? (($ema20 - $ema50) / $ema50) : 0;
            }
            $emaSlope = round($emaSlope, 2);
        }

        $volatility = 'medium';
        if ($atrPct !== null) {
            if ($atrPct < 0.5) {
                $volatility = 'low';
            } elseif ($atrPct > 1.5) {
                $volatility = 'high';
            }
        }

        $trend = 'sideways';
        if ($trendStr > 0.6) {
            $trend = 'up';
        } elseif ($trendStr < 0.4) {
            $trend = 'down';
        }

        $breakout = $this->detectBreakout($highs, $lows, $closes);
        $meanRev  = $this->meanReversionScore($rsi, $closes);

        $costInfo = $this->costEstimator->getTickerCostInfo($symbol);
        $spreadPct = $costInfo['spread_pct'] ?? 0.001;

        $rulesHint = $this->buildRulesHint($emaState, $trendStr, $chopScore, $volatility);

        return [
            'profile'     => null,
            'regime'      => [
                'trend'       => $trend,
                'strength'    => round($trendStr, 2),
                'volatility'  => $volatility,
                'chop_score'  => $chopScore,
            ],
            'signals'     => [
                'ema'           => [
                    'fast'  => 20,
                    'slow'  => 50,
                    'state' => $emaState,
                    'slope' => $emaSlope,
                ],
                'rsi14'         => $rsi,
                'atr_pct'       => $atrPct,
                'breakout'      => $breakout,
                'meanReversion' => $meanRev,
                'spread_pct'    => round($spreadPct * 100, 3),
            ],
            'rules_hint'  => $rulesHint,
        ];
    }

    private function emptySignals(): array
    {
        return [
            'profile'    => null,
            'regime'     => ['trend' => 'unknown', 'strength' => 0.5, 'volatility' => 'medium', 'chop_score' => 0.5],
            'signals'    => [],
            'rules_hint' => [],
        ];
    }

    private function detectBreakout(array $highs, array $lows, array $closes): array
    {
        $len = count($closes);
        if ($len < 20) {
            return ['state' => 'none', 'level' => null];
        }

        $lookback = min(20, $len - 5);
        $recentH  = max(array_slice($highs, -$lookback));
        $recentL  = min(array_slice($lows, -$lookback));
        $last     = end($closes);

        if ($last > $recentH * 1.002) {
            return ['state' => 'breakout_up', 'level' => round($recentH, 4)];
        }
        if ($last < $recentL * 0.998) {
            return ['state' => 'breakout_down', 'level' => round($recentL, 4)];
        }

        return ['state' => 'none', 'level' => null];
    }

    private function meanReversionScore(?float $rsi, array $closes): array
    {
        $score = 0.5;
        $bias  = 'neutral';

        if ($rsi !== null) {
            if ($rsi > 70) {
                $score = round(($rsi - 70) / 30, 2);
                $bias  = 'overbought';
            } elseif ($rsi < 30) {
                $score = round((30 - $rsi) / 30, 2);
                $bias  = 'oversold';
            }
        }

        return ['score' => $score, 'bias' => $bias];
    }

    private function buildRulesHint(string $emaState, float $trendStr, float $chopScore, string $volatility): array
    {
        $hints = [];

        if ($trendStr > 0.6) {
            $hints[] = 'Strong trend up: avoid CLOSE_FULL unless risk=high. Prefer MOVE_SL_TO_BE when pnl>0.';
        }
        if ($trendStr < 0.4) {
            $hints[] = 'Strong trend down: consider reducing exposure if position against trend.';
        }
        if ($chopScore > 0.6) {
            $hints[] = 'Choppy market: prefer DO_NOTHING or CLOSE_PARTIAL to reduce risk. Avoid AVERAGE_IN.';
        }
        if ($volatility === 'high') {
            $hints[] = 'High volatility: reduce confidence for uncertain edges.';
        }

        return array_slice($hints, 0, 3);
    }
}
