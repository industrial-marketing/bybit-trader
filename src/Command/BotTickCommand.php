<?php

namespace App\Command;

use App\Entity\TradingProfile;
use App\Service\AlertService;
use App\Service\BotHistoryService;
use App\Service\BotRunService;
use App\Service\BybitService;
use App\Service\ChatGPTService;
use App\Service\CircuitBreakerService;
use App\Service\CostEstimatorService;
use App\Service\ExecutionGuardService;
use App\Service\StrategyEngineService;
use App\Service\StrategyProfileService;
use App\Service\PendingActionsService;
use App\Service\PositionLockService;
use App\Service\RotationalGridLimitOrderManager;
use App\Service\RotationalGridService;
use App\Service\RiskGuardService;
use App\Service\SettingsService;
use App\Service\Settings\ProfileContext;
use App\Service\Memory\MemoryWriteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bot-tick',
    description: 'Run one bot tick: manage open positions and optionally open new ones',
)]
class BotTickCommand extends Command
{
    public function __construct(
        private readonly BybitService          $bybitService,
        private readonly ChatGPTService        $chatGPTService,
        private readonly SettingsService       $settingsService,
        private readonly BotHistoryService     $botHistory,
        private readonly PositionLockService   $positionLockService,
        private readonly RiskGuardService      $riskGuard,
        private readonly PendingActionsService $pendingActions,
        private readonly AlertService          $alertService,
        private readonly BotRunService         $botRunService,
        private readonly ExecutionGuardService $executionGuard,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly CostEstimatorService  $costEstimator,
        private readonly StrategyEngineService  $strategyEngine,
        private readonly StrategyProfileService $strategyProfile,
        private readonly RotationalGridService  $rotationalGrid,
        private readonly RotationalGridLimitOrderManager $gridLimitOrders,
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext        $profileContext,
        private readonly MemoryWriteService    $memoryWrite,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'profile-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Trading profile ID (uses ACTIVE_PROFILE_ID env if not set)',
        );
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Skip idempotency check (timeframe bucket) — run even if already executed this window',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $io->title('Bybit Trader — Bot Tick');

        $profileIdOpt = $input->getOption('profile-id');
        $profileIdEnv = $_ENV['ACTIVE_PROFILE_ID'] ?? $_SERVER['ACTIVE_PROFILE_ID'] ?? null;
        $singleProfileId = null;
        if ($profileIdOpt !== null && $profileIdOpt !== '') {
            $singleProfileId = (int) $profileIdOpt;
        } elseif ($profileIdEnv !== null && $profileIdEnv !== '' && is_numeric($profileIdEnv)) {
            $singleProfileId = (int) $profileIdEnv;
        }

        $profiles = [];
        if ($singleProfileId !== null && $singleProfileId > 0) {
            $profile = $this->em->getRepository(TradingProfile::class)->find($singleProfileId);
            if ($profile === null) {
                $io->error("Profile #{$singleProfileId} not found.");
                return Command::FAILURE;
            }
            $profiles = [$profile];
        } else {
            $profiles = $this->em->getRepository(TradingProfile::class)->findBy(
                ['isBotApproved' => true, 'isActive' => true],
                ['id' => 'ASC']
            );
            if (empty($profiles)) {
                $io->note('No admin-approved profiles. Approve profiles in Admin panel or use --profile-id=N.');
                return Command::SUCCESS;
            }
        }

        $exitCode = Command::SUCCESS;
        foreach ($profiles as $profile) {
            $this->profileContext->setActiveProfileId($profile->getId());
            $io->section("Profile: {$profile->getName()} (#{$profile->getId()}) [{$profile->getEnvironment()}]");
            try {
                $result = $this->runForProfile($io, $force);
                if ($result !== Command::SUCCESS) {
                    $exitCode = Command::FAILURE;
                }
            } catch (\Throwable $e) {
                $io->error("Profile {$profile->getName()} failed: " . $e->getMessage());
                $exitCode = Command::FAILURE;
            }
        }
        return $exitCode;
    }

    private function runForProfile(SymfonyStyle $io, bool $force): int
    {
        if (!$this->riskGuard->isTradingEnabled()) {
            $io->warning('Trading disabled (kill-switch). Tick skipped.');
            return Command::SUCCESS;
        }

        $cbStatus = $this->circuitBreaker->getStatus();
        if ($cbStatus['is_open']) {
            $io->warning($cbStatus['message']);
            return Command::SUCCESS;
        }

        $trading        = $this->settingsService->getTradingSettings();
        $autoEnabled    = $trading['auto_open_enabled']     ?? false;
        $minPositions   = max(0, (int)($trading['auto_open_min_positions'] ?? 5));
        $maxManaged     = max(1, (int)($trading['max_managed_positions']   ?? 10));
        $botTimeframe   = max(1, (int)($trading['bot_timeframe']           ?? 5));
        $historyCandles = max(1, min(60, (int)($trading['bot_history_candles'] ?? 60)));

        $tfLabel = match (true) {
            $botTimeframe >= 1440 => '1d',
            $botTimeframe >= 60   => ($botTimeframe / 60) . 'h',
            default               => "{$botTimeframe}m",
        };

        $dailyCheck = $this->riskGuard->checkDailyLossLimit();
        if (!$dailyCheck['ok']) {
            $this->alertService->alertRiskLimit('daily_loss_limit', ['message' => $dailyCheck['message']]);
            $io->warning('Daily loss limit reached: ' . $dailyCheck['message']);
            return Command::SUCCESS;
        }

        if ($force) {
            $io->note('--force: skipping idempotency check.');
            $runId = 'force-' . uniqid('', true);
        } else {
            $runId = $this->botRunService->tryStart($botTimeframe);
            if ($runId === null) {
                $bucket = $this->botRunService->currentBucket($botTimeframe);
                $io->note("Skipped — bucket [{$bucket}] ({$tfLabel}) already done or running.");
                return Command::SUCCESS;
            }
        }

        $io->writeln("<info>Run ID:</info> {$runId}  <info>Timeframe:</info> {$tfLabel}");

        $varErr = $this->botHistory->checkVarWritable();
        if ($varErr !== null) {
            $io->warning("var/ not writable — history & metrics will not update: {$varErr}");
        }

        try {
            return $this->runTick(
                $io, $runId, $botTimeframe, $historyCandles,
                $autoEnabled, $minPositions, $maxManaged
            );
        } catch (\Throwable $e) {
            $this->botRunService->finish($runId, 'error');
            throw $e;
        }
    }

    private function runTick(
        SymfonyStyle $io,
        string $runId,
        int $botTimeframe,
        int $historyCandles,
        bool $autoEnabled,
        int $minPositions,
        int $maxManaged,
    ): int {
        $trading = $this->settingsService->getTradingSettings();

        // ── Positions + kline history ────────────────────────────────────
        $positions = $this->bybitService->getPositions();
        $openCount = count($positions);
        $io->writeln("<comment>Open positions:</comment> {$openCount}");

        $posCount          = count($positions);
        $charBudgetHistory = max(0, 14000 - 2200 - $posCount * 130);
        $maxPricePoints    = $posCount > 0
            ? max(5, min(30, (int) floor($charBudgetHistory / ($posCount * 8))))
            : 30;

        foreach ($positions as &$pos) {
            $klineData = $this->bybitService->getKlineData(
                $pos['symbol'] ?? '', $botTimeframe, $historyCandles, $maxPricePoints
            );
            $pos['priceHistory']          = $klineData['summary'];
            $pos['priceHistoryTimeframe'] = $botTimeframe;
            $pos['klineRaw']              = $klineData;
        }
        unset($pos);

        $strategySignalsBySymbol = [];
        foreach ($positions as $p) {
            $sym = $p['symbol'] ?? '';
            $raw = $p['klineRaw'] ?? [];
            if ($sym !== '' && !empty($raw['closes'])) {
                $signals = $this->strategyEngine->buildSignals($sym, $botTimeframe, $raw);
                $signals['profile'] = $this->strategyProfile->selectProfile($botTimeframe, $signals);
                $strategySignalsBySymbol[$sym] = $signals;
            }
        }

        // ── Data freshness check ─────────────────────────────────────────
        $freshnessCheck = $this->riskGuard->checkDataFreshness($positions);
        if (!$freshnessCheck['ok']) {
            $this->botHistory->log('stale_data_skip', [
                'age_sec'     => $freshnessCheck['age_sec'],
                'max_age_sec' => $freshnessCheck['max_age_sec'],
                'message'     => $freshnessCheck['message'],
            ]);
            $this->alertService->alertRiskLimit('stale_data', ['message' => $freshnessCheck['message']]);
            $this->botRunService->finish($runId, 'skipped');
            $io->warning($freshnessCheck['message']);
            return Command::SUCCESS;
        }
        $dataFreshnessSec = (float)($freshnessCheck['age_sec'] ?? 0.0);
        if ($dataFreshnessSec > 5.0) {
            $io->writeln(sprintf('<comment>Data freshness: %.1fs (limit %ds)</comment>', $dataFreshnessSec, $freshnessCheck['max_age_sec']));
        }

        // ── Grid rotation (rotational_grid positions) ─────────────────────
        $positionMode = $trading['position_mode'] ?? 'single';
        $rotationalSymbols = [];
        if ($positionMode === 'rotational_grid') {
            $orphanedPlans = $this->rotationalGrid->getOrphanedPlans($positions);
            foreach ($orphanedPlans as $orphan) {
                $this->gridLimitOrders->cancelPlanOrders($orphan);
            }
            $removed = $this->rotationalGrid->removeOrphanedPlans($positions);
            if ($removed > 0) {
                $io->writeln("  <comment>[GRID]</comment> Удалено {$removed} планов по закрытым позициям.");
            }

            $maxLayers       = max(1, (int)($trading['max_layers'] ?? 3));
            $maxPositionUsdt = max(10.0, (float)($trading['max_position_usdt'] ?? 100.0));
            $layerSizeUsdt   = max(10.0, $maxPositionUsdt / $maxLayers);
            $gridStepPct     = max(1.0, min(20.0, (float)($trading['grid_step_pct'] ?? 5)));
            $rotInTrend      = (bool)($trading['rotation_allowed_in_trend'] ?? false);
            $rotInChop       = (bool)($trading['rotation_allowed_in_chop'] ?? true);

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
                        $io->writeln("  <info>[GRID]</info> {$symbol} {$side} — создан план, anchor={$entryPrice}");
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

                $positionForSync = [
                    'symbol' => $symbol,
                    'side'   => $side,
                    'size'   => $p['size'] ?? 0,
                    'markPrice' => $p['markPrice'] ?? $p['entryPrice'] ?? 0,
                    'leverage'  => $p['leverage'] ?? 1,
                ];

                $plan = $this->gridLimitOrders->sync($plan, $positionForSync, $rotationAllowed);
                if ($plan !== null) {
                    $addCount  = count($plan['limit_add_orders'] ?? []);
                    $unloadSet = isset($plan['limit_unload_order']) && $plan['limit_unload_order'] !== null;
                    if ($addCount > 0 || $unloadSet) {
                        $io->writeln("  <info>[GRID]</info> {$symbol} {$side} — limit: add={$addCount} unload=" . ($unloadSet ? '1' : '0'));
                    }
                }
            }
        }

        // ── LLM: manage open positions (Orchestrator + per-position) ──────
        $io->section('Managing open positions…');
        $exposureCheck = $this->riskGuard->checkMaxExposure($positions);
        $slots = $exposureCheck['ok']
            ? max(0, min(max(0, $minPositions - $openCount), max(0, $maxManaged - $openCount)))
            : 0;
        $orchCtx = [
            'exposure_check' => [
                'ok' => $exposureCheck['ok'],
                'total_exposure' => $exposureCheck['total_exposure'] ?? 0,
                'max_exposure' => (float)($trading['max_total_exposure_usdt'] ?? 0),
            ],
            'available_slots' => $slots,
        ];
        $manageDecisions = $this->chatGPTService->manageOpenPositions(
            $this->bybitService, $positions, $dataFreshnessSec, $strategySignalsBySymbol, $orchCtx
        );

        if (empty($manageDecisions) && $posCount > 0) {
            $this->botHistory->log('llm_failure', ['reason' => 'empty_decisions', 'positions_count' => $posCount]);
            $io->warning('LLM returned no decisions despite open positions.');
        }

        $recentEvents  = $this->botHistory->getRecentEvents(7);
        $alreadyAveraged = [];
        foreach ($recentEvents as $e) {
            if (($e['type'] ?? '') === 'average_in' && !empty($e['symbol'])) {
                $alreadyAveraged[$e['symbol']] = true;
            }
        }

        $posMap = [];
        foreach ($positions as $p) {
            $key = ($p['symbol'] ?? '') . '|' . ($p['side'] ?? '');
            $posMap[$key] = $p;
        }

        $strictMode       = $this->riskGuard->isStrictMode();
        $consecutiveFails = [];
        foreach ($recentEvents as $e) {
            $sym = $e['symbol'] ?? '';
            if ($sym === '') {
                continue;
            }
            $consecutiveFails[$sym] = ($e['ok'] ?? true)
                ? 0
                : ($consecutiveFails[$sym] ?? 0) + 1;
        }

        $managed = [];

        foreach ($manageDecisions as $d) {
            $symbol = $d['symbol'] ?? '';
            $action = $d['action'] ?? 'DO_NOTHING';
            if ($symbol === '' || $action === 'DO_NOTHING') {
                continue;
            }

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

            $side          = $position['side'] ?? '';
            $pnlAtDecision = isset($position['unrealizedPnl']) ? (float) $position['unrealizedPnl'] : null;

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

            if ($this->positionLockService->isLocked($symbol, $side)) {
                $io->writeln("  <comment>[SKIP locked]</comment> {$symbol} {$side} → {$action}");
                $this->writeDecisionMemory($symbol, $action, $traceFields['reason'] ?? '', (int)($traceFields['confidence'] ?? 0), false);
                $managed[] = array_merge($traceFields, [
                    'symbol' => $symbol, 'side' => $side, 'action' => $action,
                    'ok' => false, 'skipped' => true, 'skip_reason' => 'locked',
                ]);
                continue;
            }

            if (!$this->riskGuard->isActionAllowed($symbol, $recentEvents)) {
                $io->writeln("  <comment>[SKIP cooldown]</comment> {$symbol} {$side} → {$action}");
                $this->writeDecisionMemory($symbol, $action, $traceFields['reason'] ?? '', (int)($traceFields['confidence'] ?? 0), false);
                $managed[] = array_merge($traceFields, [
                    'symbol' => $symbol, 'side' => $side, 'action' => $action,
                    'ok' => false, 'skipped' => true, 'skip_reason' => 'cooldown',
                ]);
                continue;
            }

            // Rotational grid: add/unload handled by grid; skip LLM AVERAGE_IN / CLOSE_PARTIAL
            if (isset($rotationalSymbols["{$symbol}|{$side}"]) && in_array($action, ['AVERAGE_IN_ONCE', 'CLOSE_PARTIAL'], true)) {
                $io->writeln("  <comment>[SKIP rotational]</comment> {$symbol} {$side} → {$action} (grid handles add/unload)");
                $this->writeDecisionMemory($symbol, $action, $traceFields['reason'] ?? '', (int)($traceFields['confidence'] ?? 0), false);
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
                        'close_fraction'    => $d['close_fraction']    ?? 0.5,
                        'average_size_usdt' => $d['average_size_usdt'] ?? 10.0,
                        'pnlAtDecision'     => $pnlAtDecision,
                    ]);
                    $io->writeln("  <comment>[CANARY → pending {$pndId}]</comment> {$symbol} {$side} → {$action}");
                    $this->writeDecisionMemory($symbol, $action, $traceFields['reason'] ?? '', (int)($traceFields['confidence'] ?? 0), false);
                    $managed[] = array_merge($traceFields, [
                        'symbol' => $symbol, 'side' => $side, 'action' => $action,
                        'ok' => true, 'pending' => true, 'pending_id' => $pndId,
                        'skip_reason' => 'canary_mode',
                    ]);
                }
                continue;
            }

            if ($strictMode && $this->riskGuard->isDangerousAction($action)) {
                if (!$this->pendingActions->hasPending($symbol, $action)) {
                    $pndId = $this->pendingActions->add([
                        'symbol'            => $symbol,
                        'side'              => $side,
                        'action'            => $action,
                        'note'              => $d['note'] ?? '',
                        'close_fraction'    => $d['close_fraction']    ?? 0.5,
                        'average_size_usdt' => $d['average_size_usdt'] ?? 10.0,
                        'pnlAtDecision'     => $pnlAtDecision,
                    ]);
                    $io->writeln("  <comment>[PENDING {$pndId}]</comment> {$symbol} {$side} → {$action} (strict mode)");
                    $this->writeDecisionMemory($symbol, $action, $traceFields['reason'] ?? '', (int)($traceFields['confidence'] ?? 0), false);
                    $managed[] = array_merge($traceFields, [
                        'symbol' => $symbol, 'side' => $side, 'action' => $action,
                        'ok' => true, 'pending' => true, 'pending_id' => $pndId,
                        'skip_reason' => 'strict_mode_pending',
                    ]);
                }
                continue;
            }

            // Execute
            $result           = null;
            $eventType        = null;
            $realizedEstimate = null;
            $skipReason       = null;
            $guardResult      = null;

            if ($action === 'CLOSE_FULL' || $action === 'CLOSE_PARTIAL') {
                $fraction   = $action === 'CLOSE_FULL' ? 1.0 : (float) ($d['close_fraction'] ?? 0.5);
                $sizeBefore = (float) ($position['size'] ?? 0);
                $notional   = $sizeBefore * (float) ($position['markPrice'] ?? $position['entryPrice'] ?? 0) * $fraction;
                $edgeUsdt   = $pnlAtDecision !== null ? ($action === 'CLOSE_FULL' ? $pnlAtDecision : $pnlAtDecision * $fraction) : 0;

                $costEst   = $this->costEstimator->estimateTotalCost($notional, $symbol, false, 0);
                $edgeCheck = $this->costEstimator->checkMinimumEdge($edgeUsdt, $costEst['total_usdt']);
                if (!$edgeCheck['ok']) {
                    $io->writeln("  <comment>[SKIP min_edge]</comment> {$symbol} {$side} → {$action} — edge {$edgeUsdt}\$ < costs×mult");
                    $this->writeDecisionMemory($symbol, $action, $traceFields['reason'] ?? '', (int)($traceFields['confidence'] ?? 0), false);
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
                        if ($action === 'CLOSE_FULL' && isset($rotationalSymbols["{$symbol}|{$side}"])) {
                            $this->rotationalGrid->removePlan($symbol, $side);
                        }
                    }
                }
            } elseif ($action === 'MOVE_STOP_TO_BREAKEVEN') {
                $entry = (float) ($position['entryPrice']    ?? 0);
                $mark  = (float) ($position['markPrice']     ?? 0);
                $pnl   = (float) ($position['unrealizedPnl'] ?? 0);
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
                    $sizeBefore  = (float) ($position['size'] ?? 0);
                    $lev         = max(1, (int) ($position['leverage'] ?? 1));
                    $minMargin   = max(0, (float)($trading['min_position_usdt'] ?? 10));
                    $minNotional = $minMargin * $lev;
                    $sizeUsdt    = max($minNotional, (float)($d['average_size_usdt'] ?? 10.0));
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

            $ok = $result['ok'] ?? false;
            $statusTag = $ok ? '<info>[OK]</info>' : '<error>[ERR]</error>';
            if (!empty($result['skipped'])) {
                $statusTag = '<comment>[SKIP]</comment>';
            }
            $guardTag = '';
            if ($guardResult !== null) {
                $guardTag = $guardResult['mismatch'] ? ' <error>[MISMATCH]</error>' : ' <info>[verified]</info>';
                if ($guardResult['mismatch']) {
                    $io->writeln("        guard: " . $guardResult['message']);
                }
            }
            $io->writeln("  {$statusTag} {$symbol} {$side} → {$action}" . ($skipReason ? " ({$skipReason})" : '') . $guardTag);
            if (!$ok && !empty($result['error'])) {
                $io->writeln("        error: " . $result['error']);
            }

            $payload = array_merge($traceFields, [
                'symbol'              => $symbol,
                'side'                => $side,
                'action'              => $action,
                'ok'                  => $ok,
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

                if (($eventType === 'close_full' || $eventType === 'close_partial') && $this->memoryWrite->isWriteEnabled()) {
                    $profileId = $this->profileContext->getActiveProfileId();
                    if ($profileId !== null) {
                        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
                        if ($profile instanceof TradingProfile) {
                            $this->memoryWrite->createTradeMemory(
                                $profile,
                                $symbol,
                                $side,
                                $position,
                                $realizedEstimate,
                                $eventType,
                                $d['reason'] ?? null
                            );
                        }
                    }
                }

                if (!$ok && empty($result['skipped'])) {
                    $count = ($consecutiveFails[$symbol] ?? 0) + 1;
                    $consecutiveFails[$symbol] = $count;
                    $this->alertService->alertRepeatedFailures($symbol, $count);
                }
            }

            $executed = $result !== null && ($result['ok'] ?? false) && empty($result['skipped'] ?? true);
            $this->writeDecisionMemory($symbol, $action, $traceFields['reason'] ?? '', (int)($traceFields['confidence'] ?? 0), $executed);

            $managed[] = $payload;
        }

        // ── Auto-open ────────────────────────────────────────────────────
        $opened = [];

        if ($autoEnabled) {
            $io->section('Auto-open new positions…');
            $exposureCheck = $this->riskGuard->checkMaxExposure($positions);

            if (!$exposureCheck['ok']) {
                $this->alertService->alertRiskLimit('max_exposure', ['message' => $exposureCheck['message']]);
                $io->warning('Max exposure reached: ' . $exposureCheck['message']);
            }

            $slots = $exposureCheck['ok']
                ? max(0, min(max(0, $minPositions - $openCount), max(0, $maxManaged - $openCount)))
                : 0;

            $io->writeln("<comment>Available slots:</comment> {$slots}");

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
                    $confidence = (int) ($p['confidence'] ?? 0);
                    if ($symbol === '' || $confidence < 80 || isset($openSymbols[$symbol])) {
                        continue;
                    }

                    $side   = strtoupper($p['signal'] ?? '') === 'BUY' ? 'BUY' : 'SELL';
                    $size   = (float) ($p['positionSizeUSDT'] ?? 10);
                    $lev    = (int) ($p['leverage'] ?? 1);
                    $result = $this->bybitService->placeOrder($symbol, $side, $size, $lev);

                    $ok        = $result['ok'] ?? false;
                    $statusTag = $ok ? '<info>[OK]</info>' : '<error>[ERR]</error>';
                    $io->writeln("  {$statusTag} {$symbol} {$side} {$size}$ lev={$lev} conf={$confidence}%");
                    if (!$ok && !empty($result['error'])) {
                        $io->writeln("        error: " . $result['error']);
                    }

                    $event = [
                        'symbol'           => $symbol, 'side' => $side,
                        'positionSizeUSDT' => $size,   'leverage' => $lev,
                        'confidence'       => $confidence,
                        'reason'           => $p['reason'] ?? '',
                        'ok'               => $ok,
                        'error'            => $result['error'] ?? null,
                    ];
                    $this->botHistory->log('auto_open', $event);
                    $opened[] = $event;

                    if ($ok) {
                        $slots--;
                        $openSymbols[$symbol] = true;
                    }
                }
            }
        } else {
            $io->writeln('<comment>Auto-open disabled.</comment>');
        }

        // ── Finalize ─────────────────────────────────────────────────────
        $managedCount = count($managed);
        $openedCount  = count($opened);

        $this->botHistory->log('bot_tick', [
            'run_id'             => $runId,
            'managedCount'       => $managedCount,
            'openedCount'        => $openedCount,
            'timeframe'          => $botTimeframe,
            'data_freshness_sec' => $dataFreshnessSec,
        ]);

        $this->botRunService->finish($runId, 'done');

        $io->success(sprintf(
            'Done. Managed: %d, Opened: %d.',
            $managedCount,
            $openedCount,
        ));

        return Command::SUCCESS;
    }

    private function writeDecisionMemory(string $symbol, string $action, string $reason, int $confidence, bool $executed): void
    {
        if (!$this->memoryWrite->isWriteEnabled()) {
            return;
        }
        $profileId = $this->profileContext->getActiveProfileId();
        if ($profileId === null) {
            return;
        }
        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
        if (!$profile instanceof TradingProfile) {
            return;
        }
        $rec = "{$action}: " . mb_substr($reason, 0, 150);
        $this->memoryWrite->createDecisionMemory($profile, $symbol, $rec, (float) $confidence, $executed, null);
    }
}
