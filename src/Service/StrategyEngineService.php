<?php

namespace App\Service;

/**
 * Builds StrategySignals from kline OHLCV data.
 * Features provider for LLM — not execution logic.
 */
class StrategyEngineService
{
    public function __construct(
        private readonly BybitService   $bybitService,
        private readonly SettingsService $settingsService,
    ) {}

    private function getStrategiesConfig(): array
    {
        $cfg = $this->settingsService->getStrategiesSettings();
        if (($cfg['enabled'] ?? true) === false) {
            return ['enabled' => false];
        }
        return array_merge([
            'enabled' => true,
            'profile_overrides' => [],
            'indicators' => [
                'ema'   => ['enabled' => true, 'fast' => 20, 'slow' => 50],
                'rsi14' => ['enabled' => true, 'overbought' => 70, 'oversold' => 30],
                'atr'   => ['enabled' => true, 'period' => 14],
                'chop'  => ['enabled' => true, 'threshold' => 0.65],
            ],
            'rules' => [
                'allow_average_in' => true,
                'average_in_block_in_chop' => true,
                'prefer_be_in_trend' => true,
            ],
        ], $cfg);
    }

    /**
     * @param array{closes: float[], highs: float[], lows: float[]} $kline
     */
    public function buildSignals(string $symbol, int $timeframeMinutes, array $kline): array
    {
        $cfg = $this->getStrategiesConfig();
        if (!($cfg['enabled'] ?? true)) {
            return $this->emptySignals();
        }

        $closes = $kline['closes'] ?? [];
        $highs  = $kline['highs'] ?? [];
        $lows   = $kline['lows'] ?? [];

        if (count($closes) < 20) {
            return $this->emptySignals();
        }

        $indicators = $cfg['indicators'] ?? [];
        $emaCfg     = $indicators['ema'] ?? ['enabled' => true, 'fast' => 20, 'slow' => 50];
        $rsiCfg     = $indicators['rsi14'] ?? ['enabled' => true, 'overbought' => 70, 'oversold' => 30];
        $atrCfg     = $indicators['atr'] ?? ['enabled' => true, 'period' => 14];
        $chopCfg    = $indicators['chop'] ?? ['enabled' => true, 'threshold' => 0.65];

        $fast   = (int)($emaCfg['fast'] ?? 20);
        $slow   = (int)($emaCfg['slow'] ?? 50);
        $atrPer = (int)($atrCfg['period'] ?? 14);
        $chopTh = (float)($chopCfg['threshold'] ?? 0.65);

        $rsi       = ($rsiCfg['enabled'] ?? true) ? IndicatorService::rsi($closes, 14) : null;
        $ema20     = ($emaCfg['enabled'] ?? true) ? IndicatorService::ema($closes, $fast) : null;
        $ema50     = ($emaCfg['enabled'] ?? true) ? IndicatorService::ema($closes, $slow) : null;
        $atrPct    = ($atrCfg['enabled'] ?? true) ? IndicatorService::atrPct($highs, $lows, $closes, $atrPer) : null;
        $trendStr  = IndicatorService::trendStrength($closes, 20);
        $chopScore = ($chopCfg['enabled'] ?? true) ? IndicatorService::chopScore($highs, $lows, $closes, 20) : 0.5;

        $lastClose = end($closes);
        $emaState  = 'neutral';
        $emaSlope  = 0.0;
        if ($ema20 !== null && $ema50 !== null && $lastClose > 0 && ($emaCfg['enabled'] ?? true)) {
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
        $rsiOB    = (int)($rsiCfg['overbought'] ?? 70);
        $rsiOS    = (int)($rsiCfg['oversold'] ?? 30);
        $meanRev  = $this->meanReversionScore($rsi, $closes, $rsiOB, $rsiOS);

        $costInfo  = $this->bybitService->getTickerCostInfo($symbol);
        $spreadPct  = (float)($costInfo['spreadPct'] ?? 0.001);

        $rulesHint = $this->buildRulesHint($emaState, $trendStr, $chopScore, $volatility, $cfg['rules'] ?? [], $chopTh);

        return [
            'profile'     => null,
            'regime'      => [
                'trend'       => $trend,
                'strength'    => round($trendStr, 2),
                'volatility'  => $volatility,
                'chop_score'  => $chopScore,
                'chop_threshold' => $chopTh,
            ],
            'signals'     => [
                'ema'           => ($emaCfg['enabled'] ?? true) ? [
                    'fast'  => $fast,
                    'slow'  => $slow,
                    'state' => $emaState,
                    'slope' => $emaSlope,
                ] : null,
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

    private function meanReversionScore(?float $rsi, array $closes, int $overbought = 70, int $oversold = 30): array
    {
        $score = 0.5;
        $bias  = 'neutral';

        if ($rsi !== null) {
            if ($rsi > $overbought) {
                $score = round(($rsi - $overbought) / (100 - $overbought), 2);
                $bias  = 'overbought';
            } elseif ($rsi < $oversold) {
                $score = round(($oversold - $rsi) / $oversold, 2);
                $bias  = 'oversold';
            }
        }

        return ['score' => $score, 'bias' => $bias];
    }

    private function buildRulesHint(string $emaState, float $trendStr, float $chopScore, string $volatility, array $rules, float $chopThreshold): array
    {
        $hints = [];

        if (($rules['prefer_be_in_trend'] ?? true) && $trendStr > 0.6) {
            $hints[] = 'Strong trend up: avoid CLOSE_FULL unless risk=high. Prefer MOVE_SL_TO_BE when pnl>0.';
        }
        if ($trendStr < 0.4) {
            $hints[] = 'Strong trend down: consider reducing exposure if position against trend.';
        }
        if (($rules['average_in_block_in_chop'] ?? true) && $chopScore >= $chopThreshold) {
            $hints[] = 'Choppy market: prefer DO_NOTHING or CLOSE_PARTIAL to reduce risk. Avoid AVERAGE_IN.';
        }
        if ($volatility === 'high') {
            $hints[] = 'High volatility: reduce confidence for uncertain edges.';
        }

        return array_slice($hints, 0, 3);
    }
}
