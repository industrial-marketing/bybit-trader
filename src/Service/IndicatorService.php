<?php

namespace App\Service;

/**
 * Pure indicator calculations: RSI, EMA, ATR.
 * No I/O, no state — used by StrategyEngineService.
 */
class IndicatorService
{
    /**
     * RSI (period=14) from close prices.
     */
    public static function rsi(array $closes, int $period = 14): ?float
    {
        $len = count($closes);
        if ($len < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = $len - $period; $i < $len - 1; $i++) {
            $change = ($closes[$i + 1] ?? 0) - ($closes[$i] ?? 0);
            $gains[] = $change > 0 ? $change : 0;
            $losses[] = $change < 0 ? -$change : 0;
        }

        $avgGain = array_sum($gains) / $period;
        $avgLoss = array_sum($losses) / $period;

        if ($avgLoss <= 0) {
            return $avgGain > 0 ? 100.0 : 50.0;
        }

        $rs   = $avgGain / $avgLoss;
        $rsi  = 100 - (100 / (1 + $rs));
        return round($rsi, 1);
    }

    /**
     * EMA for a series. Returns last value.
     */
    public static function ema(array $values, int $period): ?float
    {
        $len = count($values);
        if ($len < $period) {
            return null;
        }

        $mult = 2.0 / ($period + 1);
        $ema  = array_sum(array_slice($values, 0, $period)) / $period;

        for ($i = $period; $i < $len; $i++) {
            $ema = (($values[$i] ?? 0) - $ema) * $mult + $ema;
        }

        return round($ema, 8);
    }

    /**
     * ATR (Average True Range) for period.
     */
    public static function atr(array $highs, array $lows, array $closes, int $period = 14): ?float
    {
        $len = count($closes);
        if ($len < $period + 1) {
            return null;
        }

        $trList = [];
        for ($i = 1; $i < $len; $i++) {
            $high  = $highs[$i] ?? 0;
            $low   = $lows[$i] ?? 0;
            $prevC = $closes[$i - 1] ?? 0;
            $tr    = max(
                $high - $low,
                abs($high - $prevC),
                abs($low - $prevC)
            );
            $trList[] = $tr;
        }

        $atr = array_sum(array_slice($trList, -$period)) / $period;
        return round($atr, 8);
    }

    /**
     * ATR as % of current price (for volatility comparison).
     */
    public static function atrPct(array $highs, array $lows, array $closes, int $period = 14): ?float
    {
        $atr   = self::atr($highs, $lows, $closes, $period);
        $price = end($closes);
        if ($atr === null || $price <= 0) {
            return null;
        }
        return round(($atr / $price) * 100, 2);
    }

    /**
     * Simplified trend strength: slope of linear regression over last N closes (0..1).
     */
    public static function trendStrength(array $closes, int $lookback = 20): float
    {
        $len = count($closes);
        if ($len < 2 || $lookback < 2) {
            return 0.5;
        }

        $slice = array_slice($closes, -$lookback);
        $n     = count($slice);
        $sumX  = 0;
        $sumY  = 0;
        $sumXY = 0;
        $sumX2 = 0;

        foreach ($slice as $i => $y) {
            $x = $i;
            $sumX  += $x;
            $sumY  += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denom = $n * $sumX2 - $sumX * $sumX;
        if ($denom == 0) {
            return 0.5;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denom;
        $avgPrice = $sumY / $n;
        $slopePct = $avgPrice > 0 ? ($slope / $avgPrice) * 100 : 0;

        return max(0, min(1, 0.5 + $slopePct * 2));
    }

    /**
     * Chop score: low when trending, high when sideways. Simplified via price range vs move.
     */
    public static function chopScore(array $highs, array $lows, array $closes, int $lookback = 20): float
    {
        $len = count($closes);
        if ($len < $lookback) {
            return 0.5;
        }

        $sliceH = array_slice($highs, -$lookback);
        $sliceL = array_slice($lows, -$lookback);
        $range  = max($sliceH) - min($sliceL);
        $first  = $closes[$len - $lookback] ?? 0;
        $last   = end($closes);
        $netMove = abs($last - $first);

        if ($range <= 0) {
            return 0.5;
        }

        $ratio = $netMove / $range;
        return round(max(0, min(1, 1 - $ratio)), 2);
    }
}
