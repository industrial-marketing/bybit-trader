<?php

namespace App\Service;

/**
 * Post-execution verification guard.
 *
 * After each bot action (close, open/average, move SL) fetches the actual
 * exchange state and compares it against what was expected.
 *
 * On mismatch: logs 'execution_mismatch' event + sends an alert.
 * Always returns a structured result that the caller merges into the event payload.
 *
 * Result shape:
 *   ok            bool    – verification passed (no mismatch detected)
 *   mismatch      bool    – state differs from expectation
 *   requestedQty  ?float
 *   executedQty   ?float  – from order history (cumExecQty)
 *   avgPrice      ?float  – avg fill price from order history
 *   orderId       string
 *   bybitRetCode  ?int
 *   message       string  – human-readable verdict
 */
class ExecutionGuardService
{
    /** Seconds to wait after the order before fetching state. Market orders usually fill <1s. */
    private const POST_CHECK_SLEEP_SEC = 2;

    /** Relative tolerance for qty / SL price comparisons (0.5 % = 0.005). */
    private const QTY_TOLERANCE   = 0.005;
    private const PRICE_TOLERANCE = 0.005;

    public function __construct(
        private readonly BybitService     $bybitService,
        private readonly BotHistoryService $botHistory,
        private readonly AlertService      $alertService,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    // Public verification methods
    // ──────────────────────────────────────────────────────────────────

    /**
     * Verify that a close (full or partial) was actually executed.
     *
     * @param float  $sizeBefore  Position size before the close request.
     * @param float  $fraction    Requested close fraction (1.0 = full close).
     * @param array  $orderResult ['ok' => bool, 'result' => ['orderId' => ...], ...]
     */
    public function verifyClose(
        string $symbol,
        string $side,
        float  $sizeBefore,
        float  $fraction,
        array  $orderResult,
    ): array {
        sleep(self::POST_CHECK_SLEEP_SEC);

        $orderId     = $orderResult['result']['orderId'] ?? '';
        $orderDetail = $this->bybitService->getOrderFromHistory($symbol, $orderId);
        $position    = $this->bybitService->getPositionBySymbol($symbol, $side);

        $executedQty = $orderDetail !== null ? (float)$orderDetail['cumExecQty'] : null;
        $avgPrice    = $orderDetail !== null ? (float)$orderDetail['avgPrice']   : null;
        $orderStatus = $orderDetail['orderStatus'] ?? 'Unknown';

        $requestedQty = $sizeBefore * max(0.0, min(1.0, $fraction));
        $sizeAfter    = $position !== null ? (float)($position['size'] ?? 0) : 0.0;

        $isFull = $fraction >= 0.999;

        if ($isFull) {
            // Expect position to be gone (or size ≈ 0)
            $mismatch = $sizeAfter > $sizeBefore * self::QTY_TOLERANCE;
            $message  = $mismatch
                ? "CLOSE_FULL mismatch: position still open after close order. sizeAfter={$sizeAfter} (expected ≈0)"
                : "CLOSE_FULL confirmed: position closed (sizeAfter={$sizeAfter}).";
        } else {
            // Expect size reduced by ~fraction
            $expectedAfter = $sizeBefore * (1.0 - $fraction);
            $diff          = abs($sizeAfter - $expectedAfter);
            $tolerance     = $sizeBefore * self::QTY_TOLERANCE;
            $mismatch      = $diff > $tolerance && $sizeAfter > $sizeBefore * self::QTY_TOLERANCE;
            $message       = $mismatch
                ? "CLOSE_PARTIAL mismatch: sizeAfter={$sizeAfter}, expected≈{$expectedAfter} (sizeBefore={$sizeBefore}, fraction={$fraction})"
                : "CLOSE_PARTIAL confirmed: size reduced to {$sizeAfter}.";
        }

        $result = [
            'ok'           => !$mismatch,
            'mismatch'     => $mismatch,
            'requestedQty' => round($requestedQty, 8),
            'executedQty'  => $executedQty !== null ? round($executedQty, 8) : null,
            'avgPrice'     => $avgPrice,
            'orderId'      => $orderId,
            'orderStatus'  => $orderStatus,
            'sizeBefore'   => $sizeBefore,
            'sizeAfter'    => $sizeAfter,
            'bybitRetCode' => null,
            'message'      => $message,
        ];

        if ($mismatch) {
            $this->recordMismatch($symbol, $side, 'close', $result);
        }

        return $result;
    }

    /**
     * Verify that an open / average order increased the position size.
     *
     * @param float $sizeBefore  Position size before the order (0 for new position).
     */
    public function verifyOpen(
        string $symbol,
        string $side,
        float  $sizeBefore,
        array  $orderResult,
    ): array {
        sleep(self::POST_CHECK_SLEEP_SEC);

        $orderId     = $orderResult['result']['orderId'] ?? '';
        $orderDetail = $this->bybitService->getOrderFromHistory($symbol, $orderId);
        $position    = $this->bybitService->getPositionBySymbol($symbol, $side);

        $executedQty = $orderDetail !== null ? (float)$orderDetail['cumExecQty'] : null;
        $avgPrice    = $orderDetail !== null ? (float)$orderDetail['avgPrice']   : null;
        $orderStatus = $orderDetail['orderStatus'] ?? 'Unknown';

        $sizeAfter = $position !== null ? (float)($position['size'] ?? 0) : 0.0;
        $mismatch  = $sizeAfter <= $sizeBefore * (1.0 + self::QTY_TOLERANCE / 2);

        $message = $mismatch
            ? "OPEN mismatch: position size did not increase. sizeBefore={$sizeBefore}, sizeAfter={$sizeAfter}"
            : "OPEN confirmed: position size {$sizeBefore} → {$sizeAfter}.";

        $result = [
            'ok'           => !$mismatch,
            'mismatch'     => $mismatch,
            'requestedQty' => null,
            'executedQty'  => $executedQty !== null ? round($executedQty, 8) : null,
            'avgPrice'     => $avgPrice,
            'orderId'      => $orderId,
            'orderStatus'  => $orderStatus,
            'sizeBefore'   => $sizeBefore,
            'sizeAfter'    => $sizeAfter,
            'bybitRetCode' => null,
            'message'      => $message,
        ];

        if ($mismatch) {
            $this->recordMismatch($symbol, $side, 'open', $result);
        }

        return $result;
    }

    /**
     * Verify that the stop-loss was actually set on the position (within price tolerance).
     *
     * @param float $expectedSL  The entry price used as SL target (breakeven).
     */
    public function verifyStopLoss(
        string $symbol,
        string $side,
        float  $expectedSL,
    ): array {
        sleep(self::POST_CHECK_SLEEP_SEC);

        $position = $this->bybitService->getPositionBySymbol($symbol, $side);
        $actualSL = $position !== null ? (float)($position['stopLoss'] ?? 0) : null;

        $tolerance = $expectedSL * self::PRICE_TOLERANCE;
        $mismatch  = $actualSL === null
            || $actualSL <= 0
            || abs($actualSL - $expectedSL) > $tolerance;

        $message = $mismatch
            ? "MOVE_SL mismatch: expectedSL={$expectedSL}, actualSL=" . ($actualSL ?? 'null')
            : "MOVE_SL confirmed: stopLoss set to {$actualSL}.";

        $result = [
            'ok'           => !$mismatch,
            'mismatch'     => $mismatch,
            'requestedQty' => null,
            'executedQty'  => null,
            'avgPrice'     => null,
            'orderId'      => '',
            'orderStatus'  => 'n/a',
            'expectedSL'   => $expectedSL,
            'actualSL'     => $actualSL,
            'bybitRetCode' => null,
            'message'      => $message,
        ];

        if ($mismatch) {
            $this->recordMismatch($symbol, $side, 'move_sl', $result);
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────

    private function recordMismatch(string $symbol, string $side, string $actionType, array $guardResult): void
    {
        $this->botHistory->log('execution_mismatch', array_merge($guardResult, [
            'symbol'      => $symbol,
            'side'        => $side,
            'action_type' => $actionType,
        ]));

        $this->alertService->alertBybitError(
            "execution_mismatch:{$actionType}",
            $guardResult['message'],
            0
        );
    }
}
