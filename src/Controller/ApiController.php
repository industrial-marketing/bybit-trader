<?php

namespace App\Controller;

use App\Service\AlertService;
use App\Service\BotHistoryService;
use App\Service\BotMetricsService;
use App\Service\BotRunService;
use App\Service\BybitService;
use App\Service\ChatGPTService;
use App\Service\CircuitBreakerService;
use App\Service\CostEstimatorService;
use App\Service\ExecutionGuardService;
use App\Service\PnlStatisticsService;
use App\Service\StrategyEngineService;
use App\Service\StrategyProfileService;
use App\Service\PendingActionsService;
use App\Service\PositionLockService;
use App\Service\RiskGuardService;
use App\Service\RotationalGridLimitOrderManager;
use App\Service\RotationalGridService;
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
        private readonly BotRunService         $botRunService,
        private readonly ExecutionGuardService $executionGuard,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly CostEstimatorService  $costEstimator,
        private readonly StrategyEngineService  $strategyEngine,
        private readonly StrategyProfileService $strategyProfile,
        private readonly PnlStatisticsService  $pnlStats,
        private readonly RotationalGridService $rotationalGrid,
        private readonly RotationalGridLimitOrderManager $gridLimitOrders,
    ) {}

    // ── Positions / orders / trades ───────────────────────────────

    #[Route('/positions/debug', name: 'api_positions_debug', methods: ['GET'])]
    public function getPositionsDebug(): JsonResponse
    {
        return $this->json($this->bybitService->getPositionsDebug());
    }

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

    #[Route('/position-plans', name: 'api_position_plans', methods: ['GET'])]
    public function getPositionPlans(): JsonResponse
    {
        $allPlans = $this->rotationalGrid->getAllPlans();
        $positions = $this->bybitService->getPositions();
        $planKey = fn(string $s, string $side): string => strtoupper($s) . '|' . ucfirst(strtolower($side));
        $openKeys = [];
        foreach ($positions as $p) {
            $sym = $p['symbol'] ?? '';
            $side = $p['side'] ?? '';
            if ($sym !== '') {
                $openKeys[$planKey($sym, $side)] = true;
            }
        }
        $filtered = [];
        foreach ($allPlans as $k => $plan) {
            $sym = $plan['symbol'] ?? '';
            $side = $plan['side'] ?? '';
            if (isset($openKeys[$planKey($sym, $side)])) {
                $filtered[$k] = $plan;
            }
        }
        return $this->json($filtered);
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

    #[Route('/statistics/periods', name: 'api_statistics_periods', methods: ['GET'])]
    public function getPeriodPnl(): JsonResponse
    {
        return $this->json($this->pnlStats->getPeriodPnl());
    }

    #[Route('/statistics/pnl', name: 'api_statistics_pnl', methods: ['GET'])]
    public function getPnlStatistics(Request $request): JsonResponse
    {
        $days    = (int)($request->query->get('days') ?? 30);
        $groupBy = $request->query->get('groupBy') ?? 'day';
        $symbol  = $request->query->get('symbol');
        $from    = $request->query->get('from');
        $to      = $request->query->get('to');

        return $this->json($this->pnlStats->getPnlSeries($days, $groupBy, $symbol ?: null, $from ?: null, $to ?: null));
    }

    #[Route('/balance', name: 'api_balance', methods: ['GET'])]
    public function getBalance(): JsonResponse
    {
        return $this->json($this->bybitService->getBalance());
    }

    #[Route('/account-info', name: 'api_account_info', methods: ['GET'])]
    public function getAccountInfo(): JsonResponse
    {
        $info = $this->bybitService->getAccountInfo();
        return $this->json($info ?? ['marginMode' => null, 'unifiedMarginStatus' => null]);
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
        $display = (bool)($request->query->get('display') ?? true);
        $limit   = (int)($request->query->get('limit') ?? 50);
        $period  = (string)($request->query->get('period') ?? 'all');
        $cursor  = $request->query->get('cursor');

        if ($display) {
            return $this->json($this->bybitService->getClosedTradesForDisplay($limit, $period, $cursor));
        }
        return $this->json($this->bybitService->getClosedTrades(max($limit, 200)));
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

    #[Route('/bot/runs', name: 'api_bot_runs', methods: ['GET'])]
    public function getBotRuns(Request $request): JsonResponse
    {
        $limit = (int)($request->query->get('limit') ?? 30);
        return $this->json($this->botRunService->getRecentRuns($limit));
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

        // ── Circuit Breaker ──────────────────────────────────────────
        $cbStatus = $this->circuitBreaker->getStatus();
        if ($cbStatus['is_open']) {
            return $this->json([
                'ok' => false, 'blocked' => true, 'reason' => 'circuit_breaker',
                'message' => $cbStatus['message'],
                'circuit_breaker' => $cbStatus,
                'managed' => [], 'opened' => [],
            ]);
        }

        $trading        = $this->settingsService->getTradingSettings();
        $autoEnabled    = $trading['auto_open_enabled']     ?? false;
        $minPositions   = max(0, (int)($trading['auto_open_min_positions'] ?? 5));
        $maxManaged     = max(1, (int)($trading['max_managed_positions']   ?? 10));
        $botTimeframe   = max(1, (int)($trading['bot_timeframe']           ?? 5));
        $historyCandles = max(1, min(60, (int)($trading['bot_history_candles'] ?? 60)));

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

        // ── Idempotency / timeframe throttle (atomic, flock-protected) ───
        $tfLabel = match (true) {
            $botTimeframe >= 1440 => '1d',
            $botTimeframe >= 60   => ($botTimeframe / 60) . 'h',
            default               => "{$botTimeframe}m",
        };
        $runId = $this->botRunService->tryStart($botTimeframe);
        if ($runId === null) {
            $bucket = $this->botRunService->currentBucket($botTimeframe);
            return $this->json([
                'ok'      => true,
                'skipped' => true,
                'reason'  => 'timeframe_bucket_done_or_running',
                'message' => "Тик для окна {$bucket} ({$tfLabel}) уже выполняется или завершён.",
                'managed' => [], 'opened' => [],
            ]);
        }

        $positions = $this->bybitService->getPositions();
        $openCount = count($positions);

        // ── Enrich positions with kline history ──────────────────────
        $posCount          = count($positions);
        $charBudgetHistory = max(0, 14000 - 2200 - $posCount * 130);
        $maxPricePoints    = $posCount > 0 ? max(5, min(30, (int)floor($charBudgetHistory / ($posCount * 8)))) : 30;

        foreach ($positions as &$pos) {
            $klineData = $this->bybitService->getKlineData(
                $pos['symbol'] ?? '', $botTimeframe, $historyCandles, $maxPricePoints
            );
            $pos['priceHistory']          = $klineData['summary'];
            $pos['priceHistoryTimeframe'] = $botTimeframe;
            $pos['klineRaw']              = $klineData;
        }
        unset($pos);

        // ── Strategy signals (7.1, 7.2) ───────────────────────────────
        $strategySignalsBySymbol = [];
        foreach ($positions as $p) {
            $sym  = $p['symbol'] ?? '';
            $raw  = $p['klineRaw'] ?? [];
            if ($sym === '' || empty($raw['closes'])) {
                continue;
            }
            $signals = $this->strategyEngine->buildSignals($sym, $botTimeframe, $raw);
            $signals['profile'] = $this->strategyProfile->selectProfile($botTimeframe, $signals);
            $strategySignalsBySymbol[$sym] = $signals;
        }

        // ── Data freshness check ──────────────────────────────────────
        $freshnessCheck = $this->riskGuard->checkDataFreshness($positions);
        if (!$freshnessCheck['ok']) {
            $this->botHistory->log('stale_data_skip', [
                'age_sec'     => $freshnessCheck['age_sec'],
                'max_age_sec' => $freshnessCheck['max_age_sec'],
                'message'     => $freshnessCheck['message'],
            ]);
            $this->alertService->alertRiskLimit('stale_data', ['message' => $freshnessCheck['message']]);
            $this->botRunService->finish($runId, 'skipped');
            return $this->json([
                'ok' => false, 'blocked' => true, 'reason' => 'stale_data',
                'message' => $freshnessCheck['message'],
                'managed' => [], 'opened' => [],
            ]);
        }
        $dataFreshnessSec = (float)($freshnessCheck['age_sec'] ?? 0.0);

        // ── Grid rotation (rotational_grid positions) ─────────────────────
        $positionMode     = $trading['position_mode'] ?? 'single';
        $rotationalSymbols = [];
        if ($positionMode === 'rotational_grid') {
            $maxLayers       = max(1, (int)($trading['max_layers'] ?? 3));
            $maxPositionUsdt = max(10.0, (float)($trading['max_position_usdt'] ?? 100.0));
            $layerSizeUsdt   = max(10.0, $maxPositionUsdt / $maxLayers);
            $gridStepPct     = max(1.0, min(20.0, (float)($trading['grid_step_pct'] ?? 5)));
            $rotInTrend    = (bool)($trading['rotation_allowed_in_trend'] ?? false);
            $rotInChop     = (bool)($trading['rotation_allowed_in_chop'] ?? true);

            foreach ($positions as $p) {
                $symbol = $p['symbol'] ?? '';
                $side   = $p['side'] ?? '';
                if ($symbol === '' || $this->positionLockService->isLocked($symbol, $side)) {
                    continue;
                }
                $plan = $this->rotationalGrid->getPlan($symbol, $side);
                if ($plan === null) {
                    $entryPrice = (float)($p['entryPrice'] ?? $p['markPrice'] ?? 0);
                    if ($entryPrice > 0) {
                        $plan = $this->rotationalGrid->createPlan($symbol, $side, $entryPrice, $maxLayers, $layerSizeUsdt, $gridStepPct);
                    }
                }
                if ($plan === null) {
                    continue;
                }
                $rotationalSymbols["{$symbol}|{$side}"] = true;

                $regime   = $strategySignalsBySymbol[$symbol]['regime'] ?? [];
                $chopScore = (float)($regime['chop_score'] ?? 0.5);
                $chopTh    = (float)($regime['chop_threshold'] ?? 0.65);
                $trend     = $regime['trend'] ?? 'unknown';
                $isChop    = $chopScore >= $chopTh;
                $isTrend   = in_array($trend, ['up', 'down'], true);
                $rotationAllowed = ($isChop && $rotInChop) || ($isTrend && $rotInTrend) || (!$isChop && !$isTrend);

                $markPrice = (float)($p['markPrice'] ?? $p['entryPrice'] ?? 0);
                if ($markPrice <= 0) {
                    continue;
                }

                $positionForSync = [
                    'symbol'    => $symbol,
                    'side'     => $side,
                    'size'     => $p['size'] ?? 0,
                    'markPrice'=> $markPrice,
                    'leverage' => $p['leverage'] ?? 1,
                ];
                $plan = $this->gridLimitOrders->sync($plan, $positionForSync, $rotationAllowed);
            }
        }

        // ── LLM: manage open positions (Orchestrator + per-position) ──────
        $exposureCheck  = $this->riskGuard->checkMaxExposure($positions);
        $maxManaged     = max(1, (int)($trading['max_managed_positions'] ?? 10));
        $minPositions   = max(0, (int)($trading['auto_open_min_positions'] ?? 5));
        $slots          = $exposureCheck['ok']
            ? max(0, min(max(0, $minPositions - $openCount), max(0, $maxManaged - $openCount)))
            : 0;
        $orchCtx        = [
            'exposure_check'   => ['ok' => $exposureCheck['ok'], 'total_exposure' => $exposureCheck['total_exposure'] ?? 0, 'max_exposure' => (float)($trading['max_total_exposure_usdt'] ?? 0)],
            'available_slots'  => $slots,
        ];
        $manageDecisions = $this->chatGPTService->manageOpenPositions(
            $this->bybitService, $positions, $dataFreshnessSec, $strategySignalsBySymbol, $orchCtx
        );

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

            $traceFields = [
                'confidence'      => $d['confidence']      ?? null,
                'reason'          => $d['reason']          ?? ($d['note'] ?? ''),
                'risk'            => $d['risk']            ?? null,
                'checks'          => $d['checks']          ?? null,
                'prompt_version'  => $d['prompt_version']  ?? null,
                'schema_version'  => $d['schema_version']  ?? null,
                'prompt_checksum' => $d['prompt_checksum'] ?? null,
                'provider'        => $d['provider']        ?? null,
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

            // ── Rotational grid: LLM не управляет, grid сам добор/разгрузка ──
            if (isset($rotationalSymbols["{$symbol}|{$side}"])) {
                $managed[] = array_merge($traceFields, [
                    'symbol' => $symbol, 'side' => $side, 'action' => $action,
                    'ok' => true, 'skipped' => true, 'skip_reason' => 'rotational_grid',
                ]);
                continue;
            }

            // ── Canary mode — ALL actions go to pending ──────────────
            $isCanary = $d['canary'] ?? false;
            if ($isCanary) {
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
                        'skip_reason' => 'canary_mode',
                    ]);
                }
                continue;
            }

            // ── Strict mode — dangerous actions go to pending ────────
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

            $guardResult = null;

            if ($action === 'CLOSE_FULL' || $action === 'CLOSE_PARTIAL') {
                $fraction   = $action === 'CLOSE_FULL' ? 1.0 : (float)($d['close_fraction'] ?? 0.5);
                $sizeBefore = (float)($position['size'] ?? 0);
                $notional   = $sizeBefore * (float)($position['markPrice'] ?? $position['entryPrice'] ?? 0) * $fraction;
                $edgeUsdt   = $pnlAtDecision !== null ? ($action === 'CLOSE_FULL' ? $pnlAtDecision : $pnlAtDecision * $fraction) : 0;

                $costEst   = $this->costEstimator->estimateTotalCost($notional, $symbol, false, 0);
                $edgeCheck = $this->costEstimator->checkMinimumEdge($edgeUsdt, $costEst['total_usdt']);
                if (!$edgeCheck['ok']) {
                    $managed[] = array_merge($traceFields, [
                        'symbol' => $symbol, 'side' => $side, 'action' => $action,
                        'ok' => false, 'skipped' => true, 'skip_reason' => 'min_edge',
                        'min_edge_detail' => $edgeCheck,
                    ]);
                    continue;
                }

                $result = $this->bybitService->closePositionMarket($symbol, $side, $fraction);
                if (!empty($result['skipped'])) {
                    $eventType  = 'close_partial_skip';
                    $skipReason = $result['skipReason'] ?? 'position_too_small';
                } else {
                    $eventType        = $action === 'CLOSE_FULL' ? 'close_full' : 'close_partial';
                    $realizedEstimate = $pnlAtDecision !== null
                        ? ($action === 'CLOSE_FULL' ? $pnlAtDecision : $pnlAtDecision * max(0, min(1, $fraction)))
                        : null;
                    if ($result['ok'] ?? false) {
                        $guardResult = $this->executionGuard->verifyClose($symbol, $side, $sizeBefore, $fraction, $result);
                    }
                }
            } elseif ($action === 'MOVE_STOP_TO_BREAKEVEN') {
                $entry = (float)($position['entryPrice']    ?? 0);
                $mark  = (float)($position['markPrice']     ?? 0);
                $pnl   = (float)($position['unrealizedPnl'] ?? 0);
                if ($pnl > 0 && $entry > 0 && $mark > 0) {
                    $result    = $this->bybitService->setBreakevenStopLoss($symbol, $side, $entry);
                    $eventType = 'move_sl_to_be';
                    if ($result['ok'] ?? false) {
                        $guardResult = $this->executionGuard->verifyStopLoss($symbol, $side, $entry);
                    }
                } else {
                    $result     = ['ok' => true, 'skipped' => true];
                    $eventType  = 'move_sl_to_be_skip';
                    $skipReason = 'position_not_profitable_for_breakeven';
                }
            } elseif ($action === 'AVERAGE_IN_ONCE') {
                if (!isset($alreadyAveraged[$symbol])) {
                    $sizeBefore = (float)($position['size'] ?? 0);
                    $lev        = max(1, (int)($position['leverage'] ?? 1));
                    $minMargin  = max(0, (float)($trading['min_position_usdt'] ?? 10));
                    $minNotional= $minMargin * $lev;
                    $sizeUsdt   = max($minNotional, (float)($d['average_size_usdt'] ?? 10.0));
                    $bybitSide  = strtoupper($side) === 'BUY' ? 'BUY' : 'SELL';
                    $result     = $this->bybitService->placeOrder($symbol, $bybitSide, $sizeUsdt, $lev);
                    $eventType  = 'average_in';
                    if ($result['ok'] ?? false) {
                        $alreadyAveraged[$symbol] = true;
                        $guardResult = $this->executionGuard->verifyOpen($symbol, $side, $sizeBefore, $result);
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
                'orderId'             => $result['result']['orderId'] ?? null,
                'guard'               => $guardResult,
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
                $orchCtx = [
                    'exposure_check'  => ['ok' => $exposureCheck['ok'], 'total_exposure' => $exposureCheck['total_exposure'] ?? 0, 'max_exposure' => (float)($trading['max_total_exposure_usdt'] ?? 0)],
                    'available_slots' => $slots,
                ];
                $proposals   = $this->chatGPTService->getProposals($this->bybitService, $positions, $orchCtx);
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
            'run_id'            => $runId,
            'managedCount'      => $managedCount,
            'openedCount'       => $openedCount,
            'timeframe'         => $botTimeframe,
            'data_freshness_sec'=> $dataFreshnessSec,
        ]);

        $this->botRunService->finish($runId, 'done');

        return $this->json([
            'ok' => true, 'message' => 'Bot tick executed', 'summary' => $summary,
            'run_id'  => $runId,
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
            'orderId' => $result['orderId'] ?? null, 'positionVerified' => $result['positionVerified'] ?? false,
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
        if ($result['ok'] ?? false) {
            $this->rotationalGrid->removePlan($symbol, $side);
        }
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

        $trading    = $this->settingsService->getTradingSettings();
        $symbol     = $action['symbol'] ?? '';
        $side       = $action['side']   ?? '';
        $actType    = $action['action'] ?? '';
        $result     = ['ok' => false, 'error' => 'Unknown action'];
        $eventType  = 'confirmed_action';
        $realizedEstimate = null;

        if ($actType === 'CLOSE_FULL') {
            $result           = $this->bybitService->closePositionMarket($symbol, $side, 1.0);
            if ($result['ok'] ?? false) {
                $this->rotationalGrid->removePlan($symbol, $side);
            }
            $eventType        = 'close_full';
            $realizedEstimate = $action['pnlAtDecision'] ?? null;
        } elseif ($actType === 'AVERAGE_IN_ONCE') {
            $lev       = 1;
            foreach ($this->bybitService->getPositions() as $p) {
                if (($p['symbol'] ?? '') === $symbol && ($p['side'] ?? '') === $side) {
                    $lev = max(1, (int)($p['leverage'] ?? 1));
                    break;
                }
            }
            $minMargin   = max(0, (float)($trading['min_position_usdt'] ?? 10));
            $minNotional = $minMargin * $lev;
            $sizeUsdt    = max($minNotional, (float)($action['average_size_usdt'] ?? 10.0));
            $result      = $this->bybitService->placeOrder($symbol, strtoupper($side) === 'BUY' ? 'BUY' : 'SELL', $sizeUsdt, $lev);
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

    // ── Circuit Breaker ───────────────────────────────────────────

    #[Route('/bot/circuit-breaker', name: 'api_circuit_breaker_status', methods: ['GET'])]
    public function getCircuitBreakerStatus(): JsonResponse
    {
        return $this->json($this->circuitBreaker->getStatus());
    }

    #[Route('/bot/circuit-breaker/reset', name: 'api_circuit_breaker_reset', methods: ['POST'])]
    public function resetCircuitBreaker(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $type = $data['type'] ?? null;

        if ($type !== null && !in_array($type, [
            CircuitBreakerService::TYPE_BYBIT,
            CircuitBreakerService::TYPE_LLM,
            CircuitBreakerService::TYPE_LLM_INVALID,
        ], true)) {
            return $this->json(['ok' => false, 'error' => "Unknown type: {$type}"]);
        }

        $this->circuitBreaker->reset($type);
        $this->botHistory->log('circuit_breaker_reset', [
            'type'      => $type ?? 'all',
            'reset_by'  => 'user',
        ]);

        return $this->json(['ok' => true, 'reset' => $type ?? 'all', 'status' => $this->circuitBreaker->getStatus()]);
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
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            if (isset($data['bybit']))    { $this->settingsService->updateBybitSettings($data['bybit']); }
            if (isset($data['chatgpt']))  { $this->settingsService->updateChatGPTSettings($data['chatgpt']); }
            if (isset($data['deepseek'])) { $this->settingsService->updateDeepseekSettings($data['deepseek']); }
            if (isset($data['trading']))  { $this->settingsService->updateTradingSettings($data['trading']); }
            if (isset($data['alerts']))     { $this->settingsService->updateAlertsSettings($data['alerts']); }
            if (isset($data['strategies'])) { $this->settingsService->updateStrategiesSettings($data['strategies']); }

            return $this->json(['success' => true, 'settings' => $this->settingsService->getSettings()]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Connection tests ──────────────────────────────────────────

    #[Route('/alerts/test', name: 'api_alerts_test', methods: ['POST'])]
    public function testAlert(): JsonResponse
    {
        $result = $this->alertService->sendTest('Тестовый алерт от Bybit Trader ✅', ['time' => date('Y-m-d H:i:s')]);
        return $this->json($result);
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
