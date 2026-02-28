<?php

namespace App\Controller;

use App\Service\AlertService;
use App\Service\BotHistoryService;
use App\Service\BotMetricsService;
use App\Service\BybitService;
use App\Service\ChatGPTService;
use App\Service\PendingActionsService;
use App\Service\PositionLockService;
use App\Service\RiskGuardService;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly BybitService          $bybitService,
        private readonly ChatGPTService        $chatGPTService,
        private readonly SettingsService       $settingsService,
        private readonly BotHistoryService     $botHistory,
        private readonly PositionLockService   $positionLockService,
        private readonly RiskGuardService      $riskGuard,
        private readonly PendingActionsService $pendingActions,
        private readonly BotMetricsService     $botMetrics,
        private readonly AlertService          $alertService,
    ) {}

    // ── Positions / orders / trades ───────────────────────────────

    #[Route('/positions', name: 'api_positions', methods: ['GET'])]
    public function getPositions(): JsonResponse
    {
        $positions = $this->bybitService->getPositions();
        $lastDecisions = $this->botMetrics->getLastDecisionPerPosition();

        foreach ($positions as &$p) {
            $symbol = $p['symbol'] ?? '';
            $side   = $p['side']   ?? '';
            $p['locked']      = $this->positionLockService->isLocked($symbol, $side);
            $p['lastDecision']= $lastDecisions["{$symbol}|{$side}"] ?? null;
        }
        unset($p);

        return $this->json($positions);
    }

    #[Route('/trades', name: 'api_trades', methods: ['GET'])]
    public function getTrades(Request $request): JsonResponse
    {
        $limit = (int)($request->query->get('limit') ?? 100);
        return $this->json($this->bybitService->getTrades($limit));
    }

    #[Route('/statistics', name: 'api_statistics', methods: ['GET'])]
    public function getStatistics(): JsonResponse
    {
        return $this->json($this->bybitService->getStatistics());
    }

    #[Route('/balance', name: 'api_balance', methods: ['GET'])]
    public function getBalance(): JsonResponse
    {
        return $this->json($this->bybitService->getBalance());
    }

    #[Route('/market-data/{symbol}', name: 'api_market_data', methods: ['GET'])]
    public function getMarketData(string $symbol): JsonResponse
    {
        return $this->json($this->bybitService->getMarketData($symbol));
    }

    #[Route('/market-analysis/{symbol}', name: 'api_market_analysis', methods: ['GET'])]
    public function analyzeMarket(string $symbol): JsonResponse
    {
        $marketData = $this->bybitService->getMarketData($symbol);
        return $this->json($this->chatGPTService->analyzeMarket($symbol, $marketData));
    }

    #[Route('/trading-decision/{symbol}', name: 'api_trading_decision', methods: ['GET'])]
    public function getTradingDecision(string $symbol): JsonResponse
    {
        $marketData = $this->bybitService->getMarketData($symbol);
        $positions  = $this->bybitService->getPositions();
        return $this->json($this->chatGPTService->makeTradingDecision($symbol, $marketData, $positions));
    }

    #[Route('/orders', name: 'api_orders', methods: ['GET'])]
    public function getOrders(Request $request): JsonResponse
    {
        return $this->json($this->bybitService->getOpenOrders($request->query->get('symbol', '')));
    }

    #[Route('/closed-trades', name: 'api_closed_trades', methods: ['GET'])]
    public function getClosedTrades(Request $request): JsonResponse
    {
        return $this->json($this->bybitService->getClosedTrades((int)($request->query->get('limit') ?? 100)));
    }

    #[Route('/market/top', name: 'api_market_top', methods: ['GET'])]
    public function getTopMarkets(Request $request): JsonResponse
    {
        return $this->json($this->bybitService->getTopMarkets(
            (int)($request->query->get('limit') ?? 100),
            $request->query->get('category', 'linear')
        ));
    }

    #[Route('/analysis/proposals', name: 'api_analysis_proposals', methods: ['GET'])]
    public function getProposals(): JsonResponse
    {
        return $this->json($this->chatGPTService->getProposals($this->bybitService));
    }

    // ── Bot history / metrics / decisions ─────────────────────────

    #[Route('/bot/history', name: 'api_bot_history', methods: ['GET'])]
    public function getBotHistory(Request $request): JsonResponse
    {
        $days   = (int)($request->query->get('days') ?? 7);
        $events = $this->botHistory->getRecentEvents($days);
        usort($events, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        return $this->json(array_slice($events, 0, 100));
    }

    #[Route('/bot/metrics', name: 'api_bot_metrics', methods: ['GET'])]
    public function getBotMetrics(Request $request): JsonResponse
    {
        $days = (int)($request->query->get('days') ?? 30);
        return $this->json($this->botMetrics->getMetrics($days));
    }

    #[Route('/bot/decisions', name: 'api_bot_decisions', methods: ['GET'])]
    public function getBotDecisions(Request $request): JsonResponse
    {
        $limit = (int)($request->query->get('limit') ?? 100);
        return $this->json($this->botMetrics->getRecentDecisions($limit));
    }

    // ── Bot tick ──────────────────────────────────────────────────

    #[Route('/bot/tick', name: 'api_bot_tick', methods: ['POST', 'GET'])]
    public function botTick(): JsonResponse
    {
        // ── Kill-switch ──────────────────────────────────────────────
        if (!$this->riskGuard->isTradingEnabled()) {
            return $this->json([
                'ok' => false, 'blocked' => true, 'reason' => 'kill_switch',
                'message' => 'Торговля отключена (kill-switch).',
                'managed' => [], 'opened' => [],
            ]);
        }

        $trading          = $this->settingsService->getTradingSettings();
        $autoEnabled      = $trading['auto_open_enabled']     ?? false;
        $minPositions     = max(0, (int)($trading['auto_open_min_positions'] ?? 5));
        $maxManaged       = max(1, (int)($trading['max_managed_positions']   ?? 10));
        $botTimeframe     = max(1, (int)($trading['bot_timeframe']           ?? 5));
        $historyCandles   = max(1, min(60, (int)($trading['bot_history_candles'] ?? 60)));
        $minIntervalSec   = $botTimeframe * 60;

        $positions = $this->bybitService->getPositions();
        $openCount = count($positions);

        // ── Daily loss limit ─────────────────────────────────────────
        $dailyCheck = $this->riskGuard->checkDailyLossLimit();
        if (!$dailyCheck['ok']) {
            $this->alertService->alertRiskLimit('daily_loss_limit', ['message' => $dailyCheck['message']]);
            return $this->json([
                'ok' => false, 'blocked' => true, 'reason' => 'daily_loss_limit',
                'message' => $dailyCheck['message'],
                'managed' => [], 'opened' => [],
            ]);
        }

        // ── Timeframe throttle ───────────────────────────────────────
        $tfLabel  = match (true) {
            $botTimeframe >= 1440 => '1d',
            $botTimeframe >= 60   => ($botTimeframe / 60) . 'h',
            default               => "{$botTimeframe}m",
        };
        $lastTick = $this->botHistory->getLastEventOfType('bot_tick');
        if ($lastTick && !empty($lastTick['timestamp'])) {
            try {
                $diff = (new \DateTimeImmutable('now'))->getTimestamp()
                      - (new \DateTimeImmutable($lastTick['timestamp']))->getTimestamp();
                if ($diff < $minIntervalSec) {
                    return $this->json([
                        'ok' => true, 'skipped' => true,
                        'message' => sprintf('Ждём таймфрейм %s (%d мин). Прошло %ds из %ds.', $tfLabel, $botTimeframe, $diff, $minIntervalSec),
                        'managed' => [], 'opened' => [], 'openPositionsBefore' => $openCount,
                    ]);
                }
            } catch (\Exception) {}
        }

        // ── Enrich positions with kline history ──────────────────────
        $posCount          = count($positions);
        $charBudgetHistory = max(0, 14000 - 2200 - $posCount * 130);
        $maxPricePoints    = $posCount > 0 ? max(5, min(30, (int)floor($charBudgetHistory / ($posCount * 8)))) : 30;

        foreach ($positions as &$pos) {
            $pos['priceHistory']          = $this->bybitService->getKlineHistory(
                $pos['symbol'] ?? '', $botTimeframe, $historyCandles, $maxPricePoints
            );
            $pos['priceHistoryTimeframe'] = $botTimeframe;
        }
        unset($pos);

        // ── LLM: manage open positions ────────────────────────────────
        $manageDecisions = $this->chatGPTService->manageOpenPositions($this->bybitService, $positions);

        if (empty($manageDecisions) && $posCount > 0) {
            $this->botHistory->log('llm_failure', ['reason' => 'empty_decisions', 'positions_count' => $posCount]);
        }

        $managed       = [];
        $recentEvents  = $this->botHistory->getRecentEvents(7);
        $alreadyAveraged = [];
        foreach ($recentEvents as $e) {
            if (($e['type'] ?? '') === 'average_in' && !empty($e['symbol'])) {
                $alreadyAveraged[$e['symbol']] = true;
            }
        }

        $posMap      = [];
        foreach ($positions as $p) {
            $key = ($p['symbol'] ?? '') . '|' . ($p['side'] ?? '');
            $posMap[$key] = $p;
        }

        $strictMode  = $this->riskGuard->isStrictMode();

        // ── Track consecutive failures per symbol ─────────────────────
        $consecutiveFails = [];
        foreach ($recentEvents as $e) {
            $sym = $e['symbol'] ?? '';
            if ($sym === '') {
                continue;
            }
            if (!($e['ok'] ?? true)) {
                $consecutiveFails[$sym] = ($consecutiveFails[$sym] ?? 0) + 1;
            } else {
                $consecutiveFails[$sym] = 0;
            }
        }

        foreach ($manageDecisions as $d) {
            $symbol = $d['symbol'] ?? '';
            $action = $d['action'] ?? 'DO_NOTHING';
            if ($symbol === '' || $action === 'DO_NOTHING') {
                continue;
            }

            // Find position
            $position = null;
            foreach (['Buy', 'Sell'] as $side) {
                if (isset($posMap["{$symbol}|{$side}"])) {
                    $position = $posMap["{$symbol}|{$side}"];
                    break;
                }
            }
            if ($position === null) {
                continue;
            }

            $side           = $position['side'] ?? '';
            $pnlAtDecision  = isset($position['unrealizedPnl']) ? (float)$position['unrealizedPnl'] : null;

            // Common trace fields from LLM decision
            $traceFields = [
                'confidence'      => $d['confidence']     ?? null,
                'reason'          => $d['reason']         ?? ($d['note'] ?? ''),
                'risk'            => $d['risk']           ?? null,
                'checks'          => $d['checks']         ?? null,
                'prompt_version'  => $d['prompt_version'] ?? null,
                'provider'        => $d['provider']       ?? null,
            ];

            // ── Locked ───────────────────────────────────────────────
            if ($this->positionLockService->isLocked($symbol, $side)) {
                $managed[] = array_merge($traceFields, [
                    'symbol' => $symbol, 'side' => $side, 'action' => $action,
                    'ok' => false, 'skipped' => true, 'skip_reason' => 'locked',
                ]);
                continue;
            }

            // ── Cooldown ─────────────────────────────────────────────
            if (!$this->riskGuard->isActionAllowed($symbol, $recentEvents)) {
                $managed[] = array_merge($traceFields, [
                    'symbol' => $symbol, 'side' => $side, 'action' => $action,
                    'ok' => false, 'skipped' => true, 'skip_reason' => 'cooldown',
                ]);
                continue;
            }

            // ── Strict mode ──────────────────────────────────────────
            if ($strictMode && $this->riskGuard->isDangerousAction($action)) {
                if (!$this->pendingActions->hasPending($symbol, $action)) {
                    $pndId = $this->pendingActions->add([
                        'symbol'            => $symbol,
                        'side'              => $side,
                        'action'            => $action,
                        'note'              => $d['note'] ?? '',
                        'close_fraction'    => $d['close_fraction']   ?? 0.5,
                        'average_size_usdt' => $d['average_size_usdt'] ?? 10.0,
                        'pnlAtDecision'     => $pnlAtDecision,
                    ]);
                    $managed[] = array_merge($traceFields, [
                        'symbol' => $symbol, 'side' => $side, 'action' => $action,
                        'ok' => true, 'pending' => true, 'pending_id' => $pndId,
                        'skip_reason' => 'strict_mode_pending',
                    ]);
                }
                continue;
            }

            // ── Execute ──────────────────────────────────────────────
            $result           = null;
            $eventType        = null;
            $realizedEstimate = null;
            $skipReason       = null;

            if ($action === 'CLOSE_FULL' || $action === 'CLOSE_PARTIAL') {
                $fraction = $action === 'CLOSE_FULL' ? 1.0 : (float)($d['close_fraction'] ?? 0.5);
                $result   = $this->bybitService->closePositionMarket($symbol, $side, $fraction);
                if (!empty($result['skipped'])) {
                    $eventType  = 'close_partial_skip';
                    $skipReason = $result['skipReason'] ?? 'position_too_small';
                } else {
                    $eventType        = $action === 'CLOSE_FULL' ? 'close_full' : 'close_partial';
                    $realizedEstimate = $pnlAtDecision !== null
                        ? ($action === 'CLOSE_FULL' ? $pnlAtDecision : $pnlAtDecision * max(0, min(1, $fraction)))
                        : null;
                }
            } elseif ($action === 'MOVE_STOP_TO_BREAKEVEN') {
                $entry = (float)($position['entryPrice']    ?? 0);
                $mark  = (float)($position['markPrice']     ?? 0);
                $pnl   = (float)($position['unrealizedPnl'] ?? 0);
                if ($pnl > 0 && $entry > 0 && $mark > 0) {
                    $result    = $this->bybitService->setBreakevenStopLoss($symbol, $side, $entry);
                    $eventType = 'move_sl_to_be';
                } else {
                    $result     = ['ok' => true, 'skipped' => true];
                    $eventType  = 'move_sl_to_be_skip';
                    $skipReason = 'position_not_profitable_for_breakeven';
                }
            } elseif ($action === 'AVERAGE_IN_ONCE') {
                if (!isset($alreadyAveraged[$symbol])) {
                    $sizeUsdt  = max(1.0, (float)($d['average_size_usdt'] ?? 10.0));
                    $lev       = max(1, (int)($position['leverage'] ?? 1));
                    $bybitSide = strtoupper($side) === 'BUY' ? 'BUY' : 'SELL';
                    $result    = $this->bybitService->placeOrder($symbol, $bybitSide, $sizeUsdt, $lev);
                    $eventType = 'average_in';
                    if ($result['ok'] ?? false) {
                        $alreadyAveraged[$symbol] = true;
                    }
                } else {
                    $skipReason = 'already_averaged';
                }
            }

            $payload = array_merge($traceFields, [
                'symbol'              => $symbol,
                'side'                => $side,
                'action'              => $action,
                'ok'                  => $result['ok']    ?? false,
                'error'               => $result['error'] ?? null,
                'skipped'             => !empty($result['skipped']),
                'skip_reason'         => $skipReason,
                'pnlAtDecision'       => $pnlAtDecision,
                'realizedPnlEstimate' => $realizedEstimate,
            ]);

            if ($eventType !== null) {
                $this->botHistory->log($eventType, $payload);

                // Alert on repeated failures
                if (!($result['ok'] ?? false) && empty($result['skipped'])) {
                    $count = ($consecutiveFails[$symbol] ?? 0) + 1;
                    $consecutiveFails[$symbol] = $count;
                    $this->alertService->alertRepeatedFailures($symbol, $count);
                }
            }

            $managed[] = $payload;
        }

        // ── Auto-open new positions ───────────────────────────────────
        $opened = [];

        if ($autoEnabled) {
            $exposureCheck = $this->riskGuard->checkMaxExposure($positions);

            if (!$exposureCheck['ok']) {
                $this->alertService->alertRiskLimit('max_exposure', ['message' => $exposureCheck['message']]);
            }

            $slots = $exposureCheck['ok']
                ? max(0, min(max(0, $minPositions - $openCount), max(0, $maxManaged - $openCount)))
                : 0;

            if ($slots > 0) {
                $proposals   = $this->chatGPTService->getProposals($this->bybitService);
                $openSymbols = array_fill_keys(array_column($positions, 'symbol'), true);

                foreach ($proposals as $p) {
                    if ($slots <= 0) {
                        break;
                    }
                    $symbol     = $p['symbol']     ?? '';
                    $confidence = (int)($p['confidence'] ?? 0);
                    if ($symbol === '' || $confidence < 80 || isset($openSymbols[$symbol])) {
                        continue;
                    }

                    $side   = strtoupper($p['signal'] ?? '') === 'BUY' ? 'BUY' : 'SELL';
                    $size   = (float)($p['positionSizeUSDT'] ?? 10);
                    $lev    = (int)($p['leverage'] ?? 1);
                    $result = $this->bybitService->placeOrder($symbol, $side, $size, $lev);

                    $event = [
                        'symbol'          => $symbol, 'side' => $side,
                        'positionSizeUSDT'=> $size,   'leverage' => $lev,
                        'confidence'      => $confidence,
                        'reason'          => $p['reason'] ?? '',
                        'ok'              => $result['ok']    ?? false,
                        'error'           => $result['error'] ?? null,
                    ];
                    $this->botHistory->log('auto_open', $event);
                    $opened[] = $event;

                    if ($result['ok'] ?? false) {
                        $slots--;
                        $openSymbols[$symbol] = true;
                    }
                }
            }
        }

        $managedCount = count($managed);
        $openedCount  = count($opened);

        $summary = $managedCount === 0 && $openedCount === 0
            ? 'Бот проверил позиции — действий не требуется.'
            : 'Бот тик: ' . implode(', ', array_filter([
                $managedCount > 0 ? "обработал {$managedCount} позиц." : '',
                $openedCount  > 0 ? "открыл {$openedCount} сделок"     : '',
            ])) . '.';

        $this->botHistory->log('bot_tick', [
            'managedCount' => $managedCount,
            'openedCount'  => $openedCount,
            'timeframe'    => $botTimeframe,
        ]);

        return $this->json([
            'ok' => true, 'message' => 'Bot tick executed', 'summary' => $summary,
            'managed' => $managed, 'opened' => $opened, 'openPositionsBefore' => $openCount,
        ]);
    }

    // ── Order management ──────────────────────────────────────────

    #[Route('/order/open', name: 'api_order_open', methods: ['POST'])]
    public function openOrder(Request $request): JsonResponse
    {
        if (!$this->riskGuard->isTradingEnabled()) {
            return $this->json(['ok' => false, 'error' => 'Торговля отключена (kill-switch).']);
        }

        $data   = json_decode($request->getContent(), true) ?? [];
        $symbol = $data['symbol'] ?? '';
        $side   = strtoupper($data['side'] ?? '');
        $size   = (float)($data['positionSizeUSDT'] ?? 10);
        $lev    = (int)($data['leverage'] ?? 1);

        if ($symbol === '' || !in_array($side, ['BUY', 'SELL'], true)) {
            return $this->json(['ok' => false, 'error' => 'Invalid symbol or side']);
        }

        $positions     = $this->bybitService->getPositions();
        $exposureCheck = $this->riskGuard->checkMaxExposure($positions);
        if (!$exposureCheck['ok']) {
            return $this->json(['ok' => false, 'error' => $exposureCheck['message']]);
        }

        $result = $this->bybitService->placeOrder($symbol, $side, $size, $lev);
        $this->botHistory->log('manual_open', [
            'symbol' => $symbol, 'side' => $side,
            'positionSizeUSDT' => $size, 'leverage' => $lev,
            'ok' => $result['ok'] ?? false, 'error' => $result['error'] ?? null,
        ]);

        return $this->json($result);
    }

    #[Route('/position/close', name: 'api_position_close', methods: ['POST'])]
    public function closePosition(Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent(), true) ?? [];
        $symbol = $data['symbol'] ?? '';
        $side   = $data['side']   ?? '';

        if ($symbol === '' || $side === '') {
            return $this->json(['ok' => false, 'error' => 'Invalid symbol or side']);
        }

        $result = $this->bybitService->closePositionMarket($symbol, $side, 1.0);
        $this->botHistory->log('manual_close_full', [
            'symbol' => $symbol, 'side' => $side, 'action' => 'MANUAL_CLOSE_FULL',
            'ok' => $result['ok'] ?? false, 'error' => $result['error'] ?? null,
        ]);

        return $this->json($result);
    }

    #[Route('/position/lock', name: 'api_position_lock', methods: ['POST'])]
    public function lockPosition(Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent(), true) ?? [];
        $symbol = $data['symbol'] ?? '';
        $side   = $data['side']   ?? '';
        $locked = (bool)($data['locked'] ?? false);

        if ($symbol === '' || $side === '') {
            return $this->json(['ok' => false, 'error' => 'Invalid symbol or side']);
        }

        $this->positionLockService->setLock($symbol, $side, $locked);
        $this->botHistory->log('position_lock', ['symbol' => $symbol, 'side' => $side, 'locked' => $locked]);

        return $this->json(['ok' => true, 'symbol' => $symbol, 'side' => $side, 'locked' => $locked]);
    }

    // ── Risk guard ────────────────────────────────────────────────

    #[Route('/bot/risk-status', name: 'api_bot_risk_status', methods: ['GET'])]
    public function getRiskStatus(): JsonResponse
    {
        return $this->json($this->riskGuard->getRiskStatus($this->bybitService->getPositions()));
    }

    #[Route('/bot/pending', name: 'api_bot_pending', methods: ['GET'])]
    public function getPendingActions(): JsonResponse
    {
        return $this->json($this->pendingActions->getAll());
    }

    #[Route('/bot/confirm', name: 'api_bot_confirm', methods: ['POST'])]
    public function confirmPendingAction(Request $request): JsonResponse
    {
        $data    = json_decode($request->getContent(), true) ?? [];
        $id      = $data['id']      ?? '';
        $confirm = (bool)($data['confirm'] ?? false);

        if ($id === '') {
            return $this->json(['ok' => false, 'error' => 'Missing pending action id']);
        }

        $action = $this->pendingActions->resolve($id, $confirm);
        if ($action === null) {
            return $this->json(['ok' => false, 'error' => 'Pending action not found (expired or resolved)']);
        }

        if (!$confirm) {
            $this->botHistory->log('pending_rejected', ['id' => $id, 'symbol' => $action['symbol'] ?? '', 'action' => $action['action'] ?? '']);
            return $this->json(['ok' => true, 'result' => 'rejected']);
        }

        if (!$this->riskGuard->isTradingEnabled()) {
            return $this->json(['ok' => false, 'error' => 'Торговля отключена (kill-switch)']);
        }

        $symbol    = $action['symbol'] ?? '';
        $side      = $action['side']   ?? '';
        $actType   = $action['action'] ?? '';
        $result    = ['ok' => false, 'error' => 'Unknown action'];
        $eventType = 'confirmed_action';
        $realizedEstimate = null;

        if ($actType === 'CLOSE_FULL') {
            $result           = $this->bybitService->closePositionMarket($symbol, $side, 1.0);
            $eventType        = 'close_full';
            $realizedEstimate = $action['pnlAtDecision'] ?? null;
        } elseif ($actType === 'AVERAGE_IN_ONCE') {
            $sizeUsdt  = max(1.0, (float)($action['average_size_usdt'] ?? 10.0));
            $lev       = 1;
            foreach ($this->bybitService->getPositions() as $p) {
                if (($p['symbol'] ?? '') === $symbol && ($p['side'] ?? '') === $side) {
                    $lev = max(1, (int)($p['leverage'] ?? 1));
                    break;
                }
            }
            $result    = $this->bybitService->placeOrder($symbol, strtoupper($side) === 'BUY' ? 'BUY' : 'SELL', $sizeUsdt, $lev);
            $eventType = 'average_in';
        }

        $payload = [
            'symbol' => $symbol, 'side' => $side, 'action' => $actType,
            'note'   => 'Confirmed by user',
            'ok'     => $result['ok']    ?? false,
            'error'  => $result['error'] ?? null,
            'pnlAtDecision'       => $action['pnlAtDecision'] ?? null,
            'realizedPnlEstimate' => $realizedEstimate,
        ];
        $this->botHistory->log($eventType, $payload);

        return $this->json(['ok' => true, 'result' => 'executed', 'details' => $payload]);
    }

    // ── Settings ──────────────────────────────────────────────────

    #[Route('/settings', name: 'api_settings_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        return $this->json($this->settingsService->getSettings());
    }

    #[Route('/settings', name: 'api_settings_update', methods: ['POST'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['bybit']))    { $this->settingsService->updateBybitSettings($data['bybit']); }
        if (isset($data['chatgpt']))  { $this->settingsService->updateChatGPTSettings($data['chatgpt']); }
        if (isset($data['deepseek'])) { $this->settingsService->updateDeepseekSettings($data['deepseek']); }
        if (isset($data['trading']))  { $this->settingsService->updateTradingSettings($data['trading']); }
        if (isset($data['alerts']))   { $this->settingsService->updateAlertsSettings($data['alerts']); }

        return $this->json(['success' => true, 'settings' => $this->settingsService->getSettings()]);
    }

    // ── Connection tests ──────────────────────────────────────────

    #[Route('/alerts/test', name: 'api_alerts_test', methods: ['POST'])]
    public function testAlert(): JsonResponse
    {
        try {
            $this->alertService->send('INFO', 'Тестовый алерт от Bybit Trader', ['time' => date('Y-m-d H:i:s')]);
            return $this->json(['ok' => true]);
        } catch (\Exception $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    #[Route('/test/bybit', name: 'api_test_bybit', methods: ['GET'])]
    public function testBybit(): JsonResponse
    {
        return $this->json($this->bybitService->testConnection());
    }

    #[Route('/test/chatgpt', name: 'api_test_chatgpt', methods: ['GET'])]
    public function testChatGPT(): JsonResponse
    {
        return $this->json($this->chatGPTService->testConnection());
    }
}
