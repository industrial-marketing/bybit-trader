<?php

namespace App\Controller;

use App\Service\BybitService;
use App\Service\ChatGPTService;
use App\Service\SettingsService;
use App\Service\BotHistoryService;
use App\Service\PositionLockService;
use App\Service\RiskGuardService;
use App\Service\PendingActionsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private BybitService           $bybitService,
        private ChatGPTService         $chatGPTService,
        private SettingsService        $settingsService,
        private BotHistoryService      $botHistory,
        private PositionLockService    $positionLockService,
        private RiskGuardService       $riskGuard,
        private PendingActionsService  $pendingActions
    ) {}

    #[Route('/positions', name: 'api_positions', methods: ['GET'])]
    public function getPositions(): JsonResponse
    {
        $positions = $this->bybitService->getPositions();
        foreach ($positions as &$p) {
            $symbol = $p['symbol'] ?? '';
            $side = $p['side'] ?? '';
            $p['locked'] = $this->positionLockService->isLocked($symbol, $side);
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
        $analysis = $this->chatGPTService->analyzeMarket($symbol, $marketData);
        return $this->json($analysis);
    }

    #[Route('/trading-decision/{symbol}', name: 'api_trading_decision', methods: ['GET'])]
    public function getTradingDecision(string $symbol): JsonResponse
    {
        $marketData = $this->bybitService->getMarketData($symbol);
        $positions = $this->bybitService->getPositions();
        $decision = $this->chatGPTService->makeTradingDecision($symbol, $marketData, $positions);
        return $this->json($decision);
    }

    #[Route('/orders', name: 'api_orders', methods: ['GET'])]
    public function getOrders(Request $request): JsonResponse
    {
        $symbol = $request->query->get('symbol', '');
        return $this->json($this->bybitService->getOpenOrders($symbol));
    }

    #[Route('/closed-trades', name: 'api_closed_trades', methods: ['GET'])]
    public function getClosedTrades(Request $request): JsonResponse
    {
        $limit = (int)($request->query->get('limit') ?? 100);
        return $this->json($this->bybitService->getClosedTrades($limit));
    }

    #[Route('/market/top', name: 'api_market_top', methods: ['GET'])]
    public function getTopMarkets(Request $request): JsonResponse
    {
        $limit = (int) ($request->query->get('limit') ?? 100);
        $category = $request->query->get('category', 'linear');
        return $this->json($this->bybitService->getTopMarkets($limit, $category));
    }

    #[Route('/analysis/proposals', name: 'api_analysis_proposals', methods: ['GET'])]
    public function getProposals(): JsonResponse
    {
        return $this->json($this->chatGPTService->getProposals($this->bybitService));
    }

    #[Route('/bot/history', name: 'api_bot_history', methods: ['GET'])]
    public function getBotHistory(): JsonResponse
    {
        $events = $this->botHistory->getRecentEvents(7);
        usort($events, function (array $a, array $b): int {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        $events = array_slice($events, 0, 50);

        return $this->json($events);
    }

    #[Route('/bot/tick', name: 'api_bot_tick', methods: ['POST', 'GET'])]
    public function botTick(): JsonResponse
    {
        // ── Kill-switch ──────────────────────────────────────────────
        if (!$this->riskGuard->isTradingEnabled()) {
            return $this->json([
                'ok'      => false,
                'blocked' => true,
                'reason'  => 'kill_switch',
                'message' => 'Торговля отключена в настройках (kill-switch). Включите "Торговля разрешена" для работы бота.',
                'managed' => [], 'opened' => [],
            ]);
        }

        $trading = $this->settingsService->getTradingSettings();
        $autoEnabled    = $trading['auto_open_enabled'] ?? false;
        $minPositions   = isset($trading['auto_open_min_positions']) ? max(0, (int)$trading['auto_open_min_positions']) : 5;
        $maxManaged     = isset($trading['max_managed_positions']) ? max(1, (int)$trading['max_managed_positions']) : 10;
        $botTimeframe   = isset($trading['bot_timeframe']) ? max(1, (int)$trading['bot_timeframe']) : 5;
        $historyCandleLimit = isset($trading['bot_history_candles']) ? max(1, min(60, (int)$trading['bot_history_candles'])) : 60;
        $minIntervalSec = $botTimeframe * 60;

        $positions = $this->bybitService->getPositions();
        $openCount = count($positions);

        // ── Daily loss limit ─────────────────────────────────────────
        $dailyCheck = $this->riskGuard->checkDailyLossLimit();
        if (!$dailyCheck['ok']) {
            return $this->json([
                'ok'      => false,
                'blocked' => true,
                'reason'  => 'daily_loss_limit',
                'message' => $dailyCheck['message'],
                'managed' => [], 'opened' => [],
            ]);
        }

        // ── Частота принятия решений ─────────────────────────────────
        $tfLabel = match (true) {
            $botTimeframe >= 1440 => '1d',
            $botTimeframe >= 60   => ($botTimeframe / 60) . 'h',
            default               => "{$botTimeframe}m",
        };

        $lastTick = $this->botHistory->getLastEventOfType('bot_tick');
        if ($lastTick && !empty($lastTick['timestamp'])) {
            try {
                $lastTs = new \DateTimeImmutable($lastTick['timestamp']);
                $now    = new \DateTimeImmutable('now');
                $diff   = $now->getTimestamp() - $lastTs->getTimestamp();
                if ($diff < $minIntervalSec) {
                    return $this->json([
                        'ok' => true,
                        'skipped' => true,
                        'message' => sprintf(
                            'Бот ждёт таймфрейм %s (%d мин). Прошло %d сек из %d.',
                            $tfLabel, $botTimeframe, $diff, $minIntervalSec
                        ),
                        'managed' => [],
                        'opened' => [],
                        'openPositionsBefore' => $openCount,
                    ]);
                }
            } catch (\Exception) {
                // Игнорируем проблемы с датой и просто продолжаем
            }
        }

        // Адаптивное число ценовых точек под токен-бюджет:
        // бюджет ≈ 14 000 символов, ~2 200 уходит на инструкции, ~120 на заголовок позиции
        $posCount          = count($positions);
        $charBudgetHistory = max(0, 14000 - 2200 - $posCount * 120);
        $maxPricePoints    = $posCount > 0
            ? max(5, min(30, (int)floor($charBudgetHistory / ($posCount * 8))))
            : 30;

        // Обогащаем каждую позицию историей свечей Bybit (kline) по настроенному таймфрейму
        foreach ($positions as &$pos) {
            $sym = $pos['symbol'] ?? '';
            $pos['priceHistory'] = $this->bybitService->getKlineHistory(
                $sym, $botTimeframe, $historyCandleLimit, $maxPricePoints
            );
            $pos['priceHistoryTimeframe'] = $botTimeframe;
        }
        unset($pos);

        // Шаг 1: бот управляет уже открытыми позициями (закрытия, частичные закрытия, усреднение, перенос стопа).
        $manageDecisions = $this->chatGPTService->manageOpenPositions($this->bybitService, $positions);
        $managed = [];

        // Для контроля усреднений "только один раз"
        $recentEvents = $this->botHistory->getRecentEvents(7);
        $alreadyAveraged = [];
        foreach ($recentEvents as $e) {
            if (($e['type'] ?? '') === 'average_in' && !empty($e['symbol'])) {
                $alreadyAveraged[$e['symbol']] = true;
            }
        }

        $positionsBySymbolSide = [];
        foreach ($positions as $p) {
            $key = ($p['symbol'] ?? '') . '|' . ($p['side'] ?? '');
            $positionsBySymbolSide[$key] = $p;
        }

        $strictMode  = $this->riskGuard->isStrictMode();

        foreach ($manageDecisions as $d) {
            $symbol = $d['symbol'] ?? '';
            $action = $d['action'] ?? 'DO_NOTHING';
            if ($symbol === '' || $action === 'DO_NOTHING') {
                continue;
            }

            // Ищем позицию и её сторону
            $position = null;
            foreach (['Buy', 'Sell'] as $sideCandidate) {
                $key = $symbol . '|' . $sideCandidate;
                if (isset($positionsBySymbolSide[$key])) {
                    $position = $positionsBySymbolSide[$key];
                    break;
                }
            }
            if ($position === null) {
                continue;
            }

            $side = $position['side'] ?? '';

            // Позиция заблокирована пользователем — не трогаем
            if ($this->positionLockService->isLocked($symbol, $side)) {
                continue;
            }

            // ── Rate-limit: cooldown per symbol ──────────────────────
            if (!$this->riskGuard->isActionAllowed($symbol, $recentEvents)) {
                $managed[] = [
                    'symbol' => $symbol, 'side' => $side, 'action' => $action,
                    'ok' => false, 'skipped' => true,
                    'note' => 'Cooldown: слишком частые действия по этому символу.',
                ];
                continue;
            }

            // ── Strict mode: опасные действия → в очередь ────────────
            if ($strictMode && $this->riskGuard->isDangerousAction($action)) {
                if (!$this->pendingActions->hasPending($symbol, $action)) {
                    $pndId = $this->pendingActions->add([
                        'symbol'        => $symbol,
                        'side'          => $side,
                        'action'        => $action,
                        'note'          => $d['note'] ?? '',
                        'close_fraction'=> $d['close_fraction'] ?? 0.5,
                        'average_size_usdt' => $d['average_size_usdt'] ?? 10.0,
                        'pnlAtDecision' => isset($position['unrealizedPnl']) ? (float)$position['unrealizedPnl'] : null,
                    ]);
                    $managed[] = [
                        'symbol' => $symbol, 'side' => $side, 'action' => $action,
                        'ok' => true, 'pending' => true, 'pending_id' => $pndId,
                        'note' => 'Строгий режим: требуется подтверждение пользователя.',
                    ];
                }
                continue;
            }

            // ── Исполнение действия ───────────────────────────────────
            $result           = null;
            $eventType        = null;
            $pnlAtDecision    = isset($position['unrealizedPnl']) ? (float)$position['unrealizedPnl'] : null;
            $realizedEstimate = null;

            if ($action === 'CLOSE_FULL' || $action === 'CLOSE_PARTIAL') {
                $fraction = $action === 'CLOSE_FULL' ? 1.0 : (float)($d['close_fraction'] ?? 0.5);
                $result   = $this->bybitService->closePositionMarket($symbol, $side, $fraction);
                if (!empty($result['skipped'])) {
                    $eventType = 'close_partial_skip';
                } else {
                    $eventType = $action === 'CLOSE_FULL' ? 'close_full' : 'close_partial';
                    if ($pnlAtDecision !== null) {
                        $realizedEstimate = $action === 'CLOSE_FULL'
                            ? $pnlAtDecision
                            : $pnlAtDecision * max(0.0, min(1.0, $fraction));
                    }
                }
            } elseif ($action === 'MOVE_STOP_TO_BREAKEVEN') {
                $entry = isset($position['entryPrice']) ? (float)$position['entryPrice'] : 0.0;
                $mark  = isset($position['markPrice'])  ? (float)$position['markPrice']  : 0.0;
                $pnl   = isset($position['unrealizedPnl']) ? (float)$position['unrealizedPnl'] : 0.0;
                if ($pnl > 0 && $entry > 0 && $mark > 0) {
                    $result    = $this->bybitService->setBreakevenStopLoss($symbol, $side, $entry);
                    $eventType = 'move_sl_to_be';
                } else {
                    $result    = ['ok' => true, 'skipped' => true, 'skipReason' => 'position_not_profitable_for_breakeven'];
                    $eventType = 'move_sl_to_be_skip';
                }
            } elseif ($action === 'AVERAGE_IN_ONCE') {
                if (!isset($alreadyAveraged[$symbol])) {
                    $sizeUsdt  = max(1.0, (float)($d['average_size_usdt'] ?? 10.0));
                    $lev       = isset($position['leverage']) ? (int)$position['leverage'] : 1;
                    $bybitSide = strtoupper($side) === 'BUY' ? 'BUY' : 'SELL';
                    $result    = $this->bybitService->placeOrder($symbol, $bybitSide, $sizeUsdt, $lev);
                    $eventType = 'average_in';
                    if ($result['ok'] ?? false) {
                        $alreadyAveraged[$symbol] = true;
                    }
                }
            }

            if ($eventType !== null && $result !== null) {
                $payload = [
                    'symbol'              => $symbol,
                    'side'                => $side,
                    'action'              => $action,
                    'note'                => $d['note'] ?? '',
                    'ok'                  => $result['ok'] ?? false,
                    'error'               => $result['error'] ?? null,
                    'pnlAtDecision'       => $pnlAtDecision,
                    'realizedPnlEstimate' => $realizedEstimate,
                ];
                $this->botHistory->log($eventType, $payload);
                $managed[] = $payload;
            }
        }

        // Шаг 2: автооткрытие новых позиций (если разрешено и нужно добрать минимум).
        $opened = [];

        if ($autoEnabled) {
            // ── Exposure check перед auto-open ───────────────────────
            $exposureCheck = $this->riskGuard->checkMaxExposure($positions);

            $targetMin  = max(0, $minPositions);
            $slotsToMin = max(0, $targetMin - $openCount);
            $slotsToMax = max(0, $maxManaged - $openCount);
            $slots      = $exposureCheck['ok'] ? min($slotsToMin, $slotsToMax) : 0;

            if ($slots > 0) {
                $proposals = $this->chatGPTService->getProposals($this->bybitService);

                if (!empty($proposals)) {
                    // Карта уже занятых символов, чтобы не открывать дубликаты
                    $openSymbols = [];
                    foreach ($positions as $p) {
                        if (!empty($p['symbol'])) {
                            $openSymbols[$p['symbol']] = true;
                        }
                    }

                    foreach ($proposals as $p) {
                        if ($slots <= 0) {
                            break;
                        }
                        $symbol = $p['symbol'] ?? '';
                        $confidence = (int)($p['confidence'] ?? 0);
                        if ($symbol === '' || $confidence < 80) {
                            continue; // автооткрытие только при уверенности >= 80%
                        }
                        if (isset($openSymbols[$symbol])) {
                            continue;
                        }

                        $side = (strtoupper($p['signal'] ?? '') === 'BUY') ? 'BUY' : 'SELL';
                        $size = (float)($p['positionSizeUSDT'] ?? 10);
                        $lev = (int)($p['leverage'] ?? 1);

                        $result = $this->bybitService->placeOrder($symbol, $side, $size, $lev);

                        $event = [
                            'symbol' => $symbol,
                            'side' => $side,
                            'positionSizeUSDT' => $size,
                            'leverage' => $lev,
                            'confidence' => $confidence,
                            'ok' => $result['ok'] ?? false,
                            'error' => $result['error'] ?? null,
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
        }

        $managedCount = count($managed);
        $openedCount = count($opened);

        if ($managedCount === 0 && $openedCount === 0) {
            $summary = 'Бот проверил открытые позиции и решил пока не выполнять никаких действий.';
        } else {
            $parts = [];
            if ($managedCount > 0) {
                $parts[] = sprintf('обработал %d позици(й)', $managedCount);
            }
            if ($openedCount > 0) {
                $parts[] = sprintf('открыл %d новы(х) сдел(ок)', $openedCount);
            }
            $summary = 'Бот тик: ' . implode(', ', $parts) . '.';
        }

        // Логируем сам факт тика
        $this->botHistory->log('bot_tick', [
            'managedCount' => $managedCount,
            'openedCount'  => $openedCount,
            'timeframe'    => $botTimeframe,
        ]);

        return $this->json([
            'ok' => true,
            'message' => 'Bot tick executed',
            'summary' => $summary,
            'managed' => $managed,
            'opened' => $opened,
            'openPositionsBefore' => $openCount,
        ]);
    }

    #[Route('/order/open', name: 'api_order_open', methods: ['POST'])]
    public function openOrder(Request $request): JsonResponse
    {
        // Kill-switch check
        if (!$this->riskGuard->isTradingEnabled()) {
            return $this->json(['ok' => false, 'error' => 'Торговля отключена (kill-switch). Включите в настройках.']);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $symbol = $data['symbol'] ?? '';
        $side = strtoupper($data['side'] ?? '');
        $positionSizeUSDT = (float)($data['positionSizeUSDT'] ?? 10);
        $leverage = (int)($data['leverage'] ?? 1);

        if ($symbol === '' || !in_array($side, ['BUY', 'SELL'], true)) {
            return $this->json(['ok' => false, 'error' => 'Invalid symbol or side']);
        }

        // Max exposure check
        $positions = $this->bybitService->getPositions();
        $exposureCheck = $this->riskGuard->checkMaxExposure($positions);
        if (!$exposureCheck['ok']) {
            return $this->json(['ok' => false, 'error' => $exposureCheck['message']]);
        }

        $result = $this->bybitService->placeOrder($symbol, $side, $positionSizeUSDT, $leverage);

        $this->botHistory->log('manual_open', [
            'symbol' => $symbol,
            'side' => $side,
            'positionSizeUSDT' => $positionSizeUSDT,
            'leverage' => $leverage,
            'ok' => $result['ok'] ?? false,
            'error' => $result['error'] ?? null,
        ]);

        return $this->json($result);
    }

    #[Route('/position/close', name: 'api_position_close', methods: ['POST'])]
    public function closePosition(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $symbol = $data['symbol'] ?? '';
        $side = $data['side'] ?? '';

        if ($symbol === '' || $side === '') {
            return $this->json(['ok' => false, 'error' => 'Invalid symbol or side']);
        }

        $result = $this->bybitService->closePositionMarket($symbol, $side, 1.0);

        $this->botHistory->log('manual_close_full', [
            'symbol' => $symbol,
            'side' => $side,
            'action' => 'MANUAL_CLOSE_FULL',
            'ok' => $result['ok'] ?? false,
            'error' => $result['error'] ?? null,
        ]);

        return $this->json($result);
    }

    #[Route('/position/lock', name: 'api_position_lock', methods: ['POST'])]
    public function lockPosition(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $symbol = $data['symbol'] ?? '';
        $side = $data['side'] ?? '';
        $locked = (bool)($data['locked'] ?? false);

        if ($symbol === '' || $side === '') {
            return $this->json(['ok' => false, 'error' => 'Invalid symbol or side']);
        }

        $this->positionLockService->setLock($symbol, $side, $locked);

        $this->botHistory->log('position_lock', [
            'symbol' => $symbol,
            'side' => $side,
            'locked' => $locked,
        ]);

        return $this->json([
            'ok' => true,
            'symbol' => $symbol,
            'side' => $side,
            'locked' => $locked,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Risk guard endpoints
    // ─────────────────────────────────────────────────────────────────

    #[Route('/bot/risk-status', name: 'api_bot_risk_status', methods: ['GET'])]
    public function getRiskStatus(): JsonResponse
    {
        $positions = $this->bybitService->getPositions();
        return $this->json($this->riskGuard->getRiskStatus($positions));
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
        $id      = $data['id'] ?? '';
        $confirm = (bool)($data['confirm'] ?? false);

        if ($id === '') {
            return $this->json(['ok' => false, 'error' => 'Missing pending action id']);
        }

        $action = $this->pendingActions->resolve($id, $confirm);
        if ($action === null) {
            return $this->json(['ok' => false, 'error' => 'Pending action not found (expired or already resolved)']);
        }

        if (!$confirm) {
            $this->botHistory->log('pending_rejected', ['id' => $id, 'symbol' => $action['symbol'] ?? '', 'action' => $action['action'] ?? '']);
            return $this->json(['ok' => true, 'result' => 'rejected']);
        }

        // Исполняем подтверждённое действие
        if (!$this->riskGuard->isTradingEnabled()) {
            return $this->json(['ok' => false, 'error' => 'Торговля отключена (kill-switch)']);
        }

        $symbol   = $action['symbol'] ?? '';
        $side     = $action['side']   ?? '';
        $actType  = $action['action'] ?? '';
        $result   = ['ok' => false, 'error' => 'Unknown action'];
        $eventType = 'confirmed_action';
        $realizedEstimate = null;

        if ($actType === 'CLOSE_FULL') {
            $result     = $this->bybitService->closePositionMarket($symbol, $side, 1.0);
            $eventType  = 'close_full';
            $realizedEstimate = $action['pnlAtDecision'] ?? null;
        } elseif ($actType === 'AVERAGE_IN_ONCE') {
            $sizeUsdt  = max(1.0, (float)($action['average_size_usdt'] ?? 10.0));
            $lev       = 1;
            $positions = $this->bybitService->getPositions();
            foreach ($positions as $p) {
                if (($p['symbol'] ?? '') === $symbol && ($p['side'] ?? '') === $side) {
                    $lev = max(1, (int)($p['leverage'] ?? 1));
                    break;
                }
            }
            $bybitSide = strtoupper($side) === 'BUY' ? 'BUY' : 'SELL';
            $result    = $this->bybitService->placeOrder($symbol, $bybitSide, $sizeUsdt, $lev);
            $eventType = 'average_in';
        }

        $payload = [
            'symbol'              => $symbol,
            'side'                => $side,
            'action'              => $actType,
            'note'                => 'Confirmed by user',
            'ok'                  => $result['ok'] ?? false,
            'error'               => $result['error'] ?? null,
            'pnlAtDecision'       => $action['pnlAtDecision'] ?? null,
            'realizedPnlEstimate' => $realizedEstimate,
        ];
        $this->botHistory->log($eventType, $payload);

        return $this->json(['ok' => true, 'result' => 'executed', 'details' => $payload]);
    }

    #[Route('/settings', name: 'api_settings_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        return $this->json($this->settingsService->getSettings());
    }

    #[Route('/settings', name: 'api_settings_update', methods: ['POST'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (isset($data['bybit'])) {
            $this->settingsService->updateBybitSettings($data['bybit']);
        }
        
        if (isset($data['chatgpt'])) {
            $this->settingsService->updateChatGPTSettings($data['chatgpt']);
        }

        if (isset($data['deepseek'])) {
            $this->settingsService->updateDeepseekSettings($data['deepseek']);
        }

        if (isset($data['trading'])) {
            $this->settingsService->updateTradingSettings($data['trading']);
        }

        return $this->json(['success' => true, 'settings' => $this->settingsService->getSettings()]);
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
