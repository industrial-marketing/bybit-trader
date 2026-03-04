<?php

namespace App\Service;

/**
 * Оценка финансовых издержек: fees, slippage, funding.
 *
 * Используется для:
 *  - проверки min_edge (блокировать действия, если ожидаемый edge < costs × multiplier);
 *  - передачи в LLM для учёта в решениях.
 *
 * Константы:
 *  - FEE_PCT = 0.06% за сторону (Bybit linear taker);
 *  - Funding horizon = 8h (3 платежа/день для perpetual).
 */
class CostEstimatorService
{
    private const FEE_PCT_PER_SIDE = 0.0006;  // 0.06%

    /** Минимальный множитель: edge должен быть >= costs × min_edge_multiplier */
    private const DEFAULT_MIN_EDGE_MULTIPLIER = 2.0;

    /** Funding horizon в часах (обычно 8h между выплатами) */
    private const FUNDING_HORIZON_HOURS = 8;

    public function __construct(
        private readonly BybitService     $bybitService,
        private readonly SettingsService  $settingsService,
    ) {}

    /**
     * Оценка суммарных издержек для операции.
     *
     * @param float  $notionalUsdt  Размер позиции в USDT (size × price)
     * @param string $symbol        Символ для funding/spread
     * @param bool   $isOpen        true = открытие, false = закрытие (для fees: 1 vs 2 стороны)
     * @param float  $holdingHours  Ожидаемое удержание в часах (для funding)
     */
    public function estimateTotalCost(
        float  $notionalUsdt,
        string $symbol,
        bool   $isOpen,
        float  $holdingHours = self::FUNDING_HORIZON_HOURS,
    ): array {
        $costInfo  = $this->bybitService->getTickerCostInfo($symbol);
        $funding   = (float)($costInfo['fundingRate'] ?? 0);
        $spreadPct = (float)($costInfo['spreadPct'] ?? 0.001);

        // Fees: 0.06% за текущую сторону (open или close). Open fee уже учтена при открытии.
        $feesUsdt = $notionalUsdt * self::FEE_PCT_PER_SIDE;

        // Slippage: half spread (market order)
        $slippagePct = $spreadPct * 0.5;
        $slippageUsdt = $notionalUsdt * $slippagePct;

        // Funding: rate × notional × (hours / 8). Rate уже в decimal (0.0001 = 0.01%)
        $fundingPayments = max(0, $holdingHours / self::FUNDING_HORIZON_HOURS);
        $fundingUsdt = abs($funding) * $notionalUsdt * $fundingPayments;

        $totalUsdt = $feesUsdt + $slippageUsdt + $fundingUsdt;

        return [
            'fees_usdt'      => round($feesUsdt, 4),
            'slippage_usdt'  => round($slippageUsdt, 4),
            'funding_usdt'   => round($fundingUsdt, 4),
            'total_usdt'     => round($totalUsdt, 4),
            'funding_rate'   => $funding,
            'spread_pct'     => $spreadPct,
            'notional_usdt'  => $notionalUsdt,
        ];
    }

    /**
     * Проверка: достаточно ли ожидаемого edge для покрытия издержек.
     *
     * @param float $estimatedEdgeUsdt  Ожидаемая прибыль в USDT (например из PnL или LLM)
     * @param float $totalCostUsdt      Суммарные издержки (из estimateTotalCost)
     * @return ['ok' => bool, 'message' => string, 'edge' => float, 'cost' => float]
     */
    public function checkMinimumEdge(float $estimatedEdgeUsdt, float $totalCostUsdt): array
    {
        $multiplier = (float)($this->settingsService->getTradingSettings()['min_edge_multiplier'] ?? self::DEFAULT_MIN_EDGE_MULTIPLIER);
        if ($multiplier <= 0) {
            return ['ok' => true, 'message' => '', 'edge' => $estimatedEdgeUsdt, 'cost' => $totalCostUsdt];
        }
        $multiplier = max(1.0, $multiplier);
        $minEdge = $totalCostUsdt * $multiplier;

        if ($estimatedEdgeUsdt < $minEdge && $totalCostUsdt > 0) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    'Ожидаемый edge %.2f USDT < min %.2f (costs %.2f × %.1fx). Действие заблокировано.',
                    $estimatedEdgeUsdt, $minEdge, $totalCostUsdt, $multiplier
                ),
                'edge'         => $estimatedEdgeUsdt,
                'cost'         => $totalCostUsdt,
                'min_edge'     => $minEdge,
                'multiplier'   => $multiplier,
            ];
        }

        return [
            'ok'      => true,
            'message' => '',
            'edge'    => $estimatedEdgeUsdt,
            'cost'    => $totalCostUsdt,
        ];
    }

    /** Fees в % для подсказки LLM (round-trip). */
    public static function getFeePctRoundTrip(): float
    {
        return self::FEE_PCT_PER_SIDE * 2 * 100;  // 0.12%
    }
}
