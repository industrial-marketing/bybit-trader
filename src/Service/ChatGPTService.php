<?php

namespace App\Service;

use App\Service\Memory\MemoryRetrievalService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatGPTService
{
    private const STRATEGY_VERSION = 'manage_v4.0';
    private const SCHEMA_VERSION  = 'schema_v3';
    private const ORCHESTRATOR_VERSION = 'orchestrator_v1';

    private const REQUIRED_DECISION_FIELDS = ['symbol', 'action', 'confidence', 'reason']; // risk optional, defaults to medium

    private const VALID_ACTIONS = [
        'CLOSE_FULL', 'CLOSE_PARTIAL', 'MOVE_STOP_TO_BREAKEVEN', 'AVERAGE_IN_ONCE', 'DO_NOTHING',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService    $settingsService,
        private readonly BotHistoryService  $botHistory,
        private readonly AlertService       $alertService,
        private readonly CostEstimatorService $costEstimator,
        private readonly MemoryRetrievalService $memoryRetrieval,
    ) {}

    private function log(string $message): void
    {
        LogSanitizer::log('LLM', $message, $this->settingsService);
    }

    public static function getVersionInfo(): array
    {
        return [
            'strategy_version' => self::STRATEGY_VERSION,
            'schema_version'   => self::SCHEMA_VERSION,
        ];
    }

    public function isCanaryMode(): bool
    {
        return (bool)($this->settingsService->getTradingSettings()['canary_mode'] ?? false);
    }

    private function hasAnyProvider(): bool
    {
        $cg = $this->settingsService->getChatGPTSettings();
        $ds = $this->settingsService->getDeepseekSettings();
        return (!empty($cg['api_key']) && ($cg['enabled'] ?? false))
            || (!empty($ds['api_key']) && ($ds['enabled'] ?? false));
    }

    // ═══════════════════════════════════════════════════════════════
    // Unified LLM request (ChatGPT → DeepSeek fallback)
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return array{content: string|null, provider: string|null, error: string|null}
     */
    private function requestLLMRaw(string $purpose, array $messages, float $temperature, int $maxTokens): array
    {
        $cg = $this->settingsService->getChatGPTSettings();
        $ds = $this->settingsService->getDeepseekSettings();

        $chatOk = !empty($cg['api_key']) && ($cg['enabled'] ?? false);
        $deepOk = !empty($ds['api_key']) && ($ds['enabled'] ?? false);
        $lastError = null;

        if ($chatOk) {
            try {
                $timeout = max(15, min(300, (int)($cg['timeout'] ?? 60)));
                $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                    'headers' => ['Authorization' => 'Bearer ' . $cg['api_key'], 'Content-Type' => 'application/json'],
                    'json'    => ['model' => $cg['model'] ?? 'gpt-4', 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens],
                    'timeout' => $timeout,
                ]);
                $data = $response->toArray(false);
                if (isset($data['choices'][0]['message']['content'])) {
                    return ['content' => $data['choices'][0]['message']['content'], 'provider' => 'chatgpt', 'error' => null];
                }
                $lastError = $data['error']['message'] ?? 'empty choices';
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $this->log("ChatGPT {$purpose} error: {$lastError}");
                $this->alertService->alertLLMFailure('ChatGPT', $lastError);
            }
        }

        if ($deepOk) {
            try {
                $timeout = max(15, min(300, (int)($ds['timeout'] ?? 120)));
                $response = $this->httpClient->request('POST', 'https://api.deepseek.com/chat/completions', [
                    'headers' => ['Authorization' => 'Bearer ' . $ds['api_key'], 'Content-Type' => 'application/json'],
                    'json'    => ['model' => $ds['model'] ?? 'deepseek-chat', 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens],
                    'timeout' => $timeout,
                ]);
                $data = $response->toArray(false);
                if (isset($data['choices'][0]['message']['content'])) {
                    return ['content' => $data['choices'][0]['message']['content'], 'provider' => 'deepseek', 'error' => null];
                }
                $lastError = $data['error']['message'] ?? 'empty choices';
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $this->log("DeepSeek {$purpose} error: {$lastError}");
                $this->alertService->alertLLMFailure('DeepSeek', $lastError);
            }
        }

        return ['content' => null, 'provider' => null, 'error' => $lastError];
    }

    /** Convenience wrapper — returns content string or null */
    private function requestLLMContent(string $purpose, array $messages, float $temperature, int $maxTokens): ?string
    {
        return $this->requestLLMRaw($purpose, $messages, $temperature, $maxTokens)['content'];
    }

    // ═══════════════════════════════════════════════════════════════
    // Level B: Portfolio Orchestrator — high-level dispatch
    // ═══════════════════════════════════════════════════════════════

    /**
     * Portfolio-level LLM call. Decides which positions need detailed analysis,
     * whether new positions can be opened, and which candidates to analyze.
     *
     * @return array{positions: array<array{symbol: string, side: string, needs_analysis: bool, reason?: string}>, can_open_new: bool, candidates_to_analyze: string[]}|null
     */
    public function getPortfolioOrchestration(
        array $positions,
        array $exposureCheck,
        int   $availableSlots,
        array $candidateSymbols = []
    ): ?array {
        if (!$this->hasAnyProvider() || empty($positions)) {
            return null;
        }

        $totalExposure = $exposureCheck['total_exposure'] ?? 0;
        $maxExposure   = $exposureCheck['max_exposure'] ?? 0;
        $historyContext = $this->botHistory->getWeeklySummaryText();

        $posLines = [];
        foreach ($positions as $p) {
            $symbol = $p['symbol'] ?? 'UNKNOWN';
            $pnl    = (float)($p['unrealizedPnl'] ?? 0);
            $margin = 0;
            $size   = (float)($p['size'] ?? 0);
            $entry  = (float)($p['entryPrice'] ?? 0);
            $lev    = max(1, (int)($p['leverage'] ?? 1));
            if ($size > 0 && $entry > 0) {
                $margin = ($size * $entry) / $lev;
            }
            $posLines[] = sprintf('%s %s pnl=%.2f margin=%.1f opened=%s', $symbol, $p['side'] ?? '', $pnl, $margin, $p['openedAt'] ?? '');
        }

        $candidatesStr = empty($candidateSymbols) ? '[]' : implode(', ', array_slice($candidateSymbols, 0, 15));

        $schema = <<<'JSON'
{
  "positions": [
    {"symbol": "BTCUSDT", "side": "Buy", "needs_analysis": true, "reason": "optional"},
    {"symbol": "ETHUSDT", "side": "Sell", "needs_analysis": false, "reason": "recent entry, skip"}
  ],
  "can_open_new": true,
  "candidates_to_analyze": ["SOLUSDT", "DOGEUSDT"]
}
JSON;

        $prompt = "PORTFOLIO ORCHESTRATOR (Level B). You decide at a high level — NOT exact actions.\n\n";
        $prompt .= "OPEN POSITIONS (" . count($positions) . "):\n" . implode("\n", $posLines);
        $prompt .= "\n\nTotal exposure: {$totalExposure} USDT. Max allowed: {$maxExposure} USDT.";
        $prompt .= "\nAvailable slots for NEW positions: {$availableSlots}.";
        $prompt .= "\n\nBOT HISTORY:\n{$historyContext}\n\n";
        if (!empty($candidateSymbols)) {
            $prompt .= "CANDIDATE symbols (from pre-filter): {$candidatesStr}\n\n";
        }
        $prompt .= "RULES:\n";
        $prompt .= "- Set needs_analysis=true for positions that may need action (close/partial/average/move_sl).\n";
        $prompt .= "- Set needs_analysis=false for positions to skip this tick (recent entry, grid-managed, etc). Reason optional.\n";
        $prompt .= "- can_open_new: false if portfolio risk high, many open positions, or daily loss limit.\n";
        $prompt .= "- candidates_to_analyze: pick 3-8 best symbols from candidate list for detailed per-symbol analysis. Empty if can_open_new=false or no candidates.\n\n";
        $prompt .= "Return ONLY valid JSON:\n{$schema}\nNo other text.";

        $result = $this->requestLLMRaw('orchestrator', [
            ['role' => 'system', 'content' => 'You output only valid JSON objects. No prose.'],
            ['role' => 'user', 'content' => $prompt],
        ], 0.3, 800);

        $content = $result['content'];
        if ($content === null) {
            return null;
        }

        if (preg_match('/\{[\s\S]*\}/u', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded) && isset($decoded['positions']) && is_array($decoded['positions'])) {
                return [
                    'positions' => $decoded['positions'],
                    'can_open_new' => (bool)($decoded['can_open_new'] ?? true),
                    'candidates_to_analyze' => is_array($decoded['candidates_to_analyze'] ?? null)
                        ? array_slice($decoded['candidates_to_analyze'], 0, 10)
                        : [],
                ];
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // Level C: Per-position LLM — detailed analysis for one position
    // ═══════════════════════════════════════════════════════════════

    /**
     * Single position LLM call. Full context for one position.
     *
     * @param array $position Single position with priceHistory, klineRaw, etc.
     * @param array $strategySignals Strategy block (profile, regime, signals)
     */
    public function manageSinglePosition(
        array $position,
        float $dataFreshnessSec = 0.0,
        array $strategySignals = []
    ): array {
        if (!$this->hasAnyProvider()) {
            return $this->doNothingDecision($position['symbol'] ?? 'UNKNOWN', 'no_provider', '', 'none');
        }

        $symbol   = $position['symbol'] ?? 'UNKNOWN';
        $hist     = $position['priceHistory'] ?? 'no market history';
        $tfMinutes = (int)($position['priceHistoryTimeframe'] ?? 0);
        $tfLabel  = $tfMinutes > 0 ? "{$tfMinutes}min" : 'unknown';

        $size     = (float)($position['size'] ?? 0);
        $mark     = (float)($position['markPrice'] ?? $position['entryPrice'] ?? 0);
        $notional = $size * $mark;
        $costEst  = $this->costEstimator->estimateTotalCost($notional, $symbol, false, 0);
        $costStr  = sprintf('est_cost_roundtrip=%.2f USDT (fees+slip+fund)', $costEst['total_usdt']);

        $strategyBlock = '';
        if (!empty($strategySignals)) {
            $strategyBlock = "\n  strategy: " . json_encode([
                'profile' => $strategySignals['profile'] ?? null,
                'regime'  => $strategySignals['regime'] ?? [],
                'signals' => $strategySignals['signals'] ?? [],
                'rules_hint' => $strategySignals['rules_hint'] ?? [],
            ], JSON_UNESCAPED_UNICODE);
        }

        $line = sprintf(
            "%s side=%s size=%s entry=%s mark=%s pnl=%s lev=%sx opened=%s | %s\n  market_hist(%s): %s%s",
            $symbol, $position['side'] ?? '',
            $position['size'] ?? '0', $position['entryPrice'] ?? '0',
            $position['markPrice'] ?? '0', $position['unrealizedPnl'] ?? '0',
            $position['leverage'] ?? '1', $position['openedAt'] ?? '',
            $costStr, $tfLabel, $hist, $strategyBlock
        );

        $events = $this->botHistory->getRecentEvents(7);
        $averagedList = 'none';
        foreach ($events as $e) {
            if (($e['type'] ?? '') === 'average_in' && ($e['symbol'] ?? '') === $symbol) {
                $averagedList = $symbol;
                break;
            }
        }
        $historyContext = $this->botHistory->getWeeklySummaryText();

        $schema = <<<'JSON'
{
  "symbol": "BTCUSDT",
  "action": "CLOSE_FULL|CLOSE_PARTIAL|MOVE_STOP_TO_BREAKEVEN|AVERAGE_IN_ONCE|DO_NOTHING",
  "confidence": <0-100>,
  "reason": "<1-3 sentences>",
  "risk": "low|medium|high",
  "params": {"close_fraction": <0.1-1.0 or null>, "average_size_usdt": <number or null>},
  "checks": {"pnl_positive": true|false, "trend": "bullish|bearish|sideways|unknown", "averaging_allowed": true|false}
}
JSON;

        $freshnessNote = $dataFreshnessSec > 0
            ? sprintf("⚠️ DATA FRESHNESS: %.1fs old. Factor into confidence.\n", $dataFreshnessSec)
            : '';

        $memoryBlock = '';
        if ($this->memoryRetrieval->isEnabled()) {
            $queryText = "{$symbol} {$position['side']} position. {$hist}";
            $strategies = $this->settingsService->getStrategiesSettings();
            $maxTokens = (int)($strategies['memory_max_tokens'] ?? 800);
            $scored = $this->memoryRetrieval->findForCurrentContext($queryText, $symbol);
            $memoryBlock = $this->memoryRetrieval->buildMemoryPromptBlock($scored, $maxTokens);
            if ($memoryBlock !== '') {
                $this->log(sprintf('Memory: %d cases used for %s %s', count($scored), $symbol, $position['side'] ?? ''));
            }
        }

        $prompt = "TRADING TIMEFRAME: {$tfLabel}. Single position analysis.\n";
        $prompt .= $freshnessNote;
        if ($memoryBlock !== '') {
            $prompt .= $memoryBlock;
        }
        $prompt .= "POSITION:\n{$line}\n\n";
        $prompt .= "BOT HISTORY:\n{$historyContext}\nAveraged recently: {$averagedList}\n\n";
        $prompt .= "RULES: Edge must exceed costs×2. AVERAGE_IN only if NOT in averaged list + regime!=chop. CLOSE_PARTIAL fraction 0.1–0.5.\n\n";
        $prompt .= "Return ONLY valid JSON (single object):\n{$schema}\nNo other text.";

        $result = $this->requestLLMRaw('manage_single', [
            ['role' => 'system', 'content' => 'You output only valid JSON objects. No prose.'],
            ['role' => 'user', 'content' => $prompt],
        ], 0.4, 500);

        $content = $result['content'];
        $provider = $result['provider'] ?? 'unknown';

        if ($content === null) {
            return $this->doNothingDecision($symbol, 'llm_no_response', '', $provider);
        }

        if (preg_match('/\{[\s\S]*\}/u', $content, $m)) {
            $item = json_decode($m[0], true);
            if (is_array($item) && ($item['symbol'] ?? '') === $symbol) {
                $action = strtoupper($item['action'] ?? 'DO_NOTHING');
                if (!in_array($action, self::VALID_ACTIONS, true)) {
                    $action = 'DO_NOTHING';
                }
                $params = $item['params'] ?? [];
                $checks = $item['checks'] ?? [];

                return [
                    'symbol' => $symbol,
                    'action' => $action,
                    'confidence' => min(100, max(0, (int)($item['confidence'] ?? 0))),
                    'reason' => mb_substr((string)($item['reason'] ?? ''), 0, 500),
                    'risk' => strtolower($item['risk'] ?? 'medium'),
                    'checks' => [
                        'pnl_positive' => $checks['pnl_positive'] ?? null,
                        'trend' => $checks['trend'] ?? null,
                        'averaging_allowed' => $checks['averaging_allowed'] ?? null,
                        'strategy_profile' => $checks['strategy_profile'] ?? null,
                        'strategy_alignment' => $checks['strategy_alignment'] ?? null,
                        'regime' => $checks['regime'] ?? null,
                    ],
                    'close_fraction' => isset($params['close_fraction']) ? max(0.05, min(1.0, (float)$params['close_fraction'])) : null,
                    'average_size_usdt' => isset($params['average_size_usdt']) ? max(0.0, (float)$params['average_size_usdt']) : null,
                    'note' => (string)($item['reason'] ?? ''),
                    'prompt_version' => self::STRATEGY_VERSION,
                    'schema_version' => self::SCHEMA_VERSION,
                    'prompt_checksum' => '',
                    'canary' => $this->isCanaryMode(),
                    'provider' => $provider,
                    'llm_raw' => null,
                ];
            }
        }

        return $this->doNothingDecision($symbol, 'invalid_single_response', $content, $provider);
    }

    // ═══════════════════════════════════════════════════════════════
    // manageOpenPositions — cascaded: Orchestrator + per-position LLM
    // ═══════════════════════════════════════════════════════════════
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cascaded LLM: Orchestrator (Level B) + per-position analysis (Level C).
     *
     * @param array<string, array> $strategySignalsBySymbol Strategy blocks per symbol
     * @param array|null $orchestratorContext {exposure_check: array, available_slots: int} — optional, enables orchestrator
     */
    public function manageOpenPositions(
        BybitService $bybitService,
        array       $positions,
        float       $dataFreshnessSec = 0.0,
        array       $strategySignalsBySymbol = [],
        ?array      $orchestratorContext = null
    ): array {
        if (!$this->hasAnyProvider() || empty($positions)) {
            return [];
        }

        $trading   = $this->settingsService->getTradingSettings();
        $maxManaged = max(1, (int)($trading['max_managed_positions'] ?? 10));
        $positions = array_slice($positions, 0, $maxManaged);

        $needsAnalysisMap = []; // symbol|side => true (all need by default)
        if ($orchestratorContext !== null && count($positions) > 1) {
            $exposureCheck = $orchestratorContext['exposure_check'] ?? ['ok' => true, 'total_exposure' => 0, 'max_exposure' => 0];
            $availableSlots = (int)($orchestratorContext['available_slots'] ?? 0);
            $totalExp = 0.0;
            foreach ($positions as $p) {
                $size = (float)($p['size'] ?? 0);
                $entry = (float)($p['entryPrice'] ?? 0);
                $lev = max(1, (int)($p['leverage'] ?? 1));
                $totalExp += ($size * $entry) / $lev;
            }
            $maxExp = (float)($trading['max_total_exposure_usdt'] ?? 0);
            $orch = $this->getPortfolioOrchestration(
                $positions,
                ['total_exposure' => $totalExp, 'max_exposure' => $maxExp],
                $availableSlots,
                []
            );
            if ($orch !== null) {
                foreach ($orch['positions'] as $po) {
                    $sym  = $po['symbol'] ?? '';
                    $side = ucfirst(strtolower($po['side'] ?? ''));
                    if ($sym !== '' && in_array($side, ['Buy', 'Sell'], true)) {
                        $needsAnalysisMap["{$sym}|{$side}"] = (bool)($po['needs_analysis'] ?? true);
                    }
                }
            }
        }

        $out = [];
        foreach ($positions as $p) {
            $symbol = $p['symbol'] ?? 'UNKNOWN';
            $side   = $p['side'] ?? '';

            if (isset($needsAnalysisMap["{$symbol}|{$side}"]) && !$needsAnalysisMap["{$symbol}|{$side}"]) {
                $out[] = $this->doNothingDecision($symbol, 'orchestrator_skip', '', 'orchestrator');
                continue;
            }

            $signals = $strategySignalsBySymbol[$symbol] ?? [];
            $decision = $this->manageSinglePosition($p, $dataFreshnessSec, $signals);
            $out[] = $decision;
        }

        if (!empty($strategySignalsBySymbol)) {
            $this->botHistory->log('strategy_signals', [
                'profiles' => array_map(fn($s) => $s['profile'] ?? null, $strategySignalsBySymbol),
                'regime_summary' => array_map(fn($s) => $s['regime']['trend'] ?? null, $strategySignalsBySymbol),
            ]);
        }

        return $out;
    }

    /**
     * Parse and validate LLM response against strict schema.
     * Any item failing validation is replaced with DO_NOTHING + llm_raw stored.
     */
    private function parseManageResponse(string $raw, array $positions, string $provider, string $promptChecksum = ''): array
    {
        $out       = [];
        $arr       = null;
        $parseOk   = false;

        if (preg_match('/\[[\s\S]*\]/u', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                $arr     = $decoded;
                $parseOk = true;
            }
        }

        if (!$parseOk || $arr === null) {
            // Completely invalid JSON
            $this->log('Invalid LLM JSON (raw): ' . mb_substr($raw, 0, 300));
            $this->botHistory->log('llm_invalid_response', [
                'raw'            => mb_substr($raw, 0, 500),
                'prompt_version' => self::STRATEGY_VERSION,
                'provider'       => $provider,
            ]);
            foreach ($positions as $p) {
                $sym = $p['symbol'] ?? 'UNKNOWN';
                $this->alertService->alertInvalidResponse($sym, $raw);
                $out[] = $this->doNothingDecision($sym, 'invalid_json', $raw, $provider);
            }
            return $out;
        }

        // Iterate over POSITIONS (not LLM response) — LLM must return one item per position in same order
        $posSymbols = array_map(fn($p) => $p['symbol'] ?? '', $positions);
        $arrCount   = count($arr);
        $posCount   = count($positions);

        $countMismatchAlerted = false;
        if ($arrCount !== $posCount) {
            $this->log("LLM returned {$arrCount} items for {$posCount} positions — alignment may be wrong");
        }

        foreach ($posSymbols as $idx => $expectedSym) {
            $item = $arr[$idx] ?? null;
            $sym  = $expectedSym ?: 'UNKNOWN';

            if ($item === null || !is_array($item)) {
                $reason = $arrCount < $posCount
                    ? "LLM returned only {$arrCount} items for {$posCount} positions (missing index {$idx})"
                    : 'no_response_item';
                $this->log("Invalid LLM decision for {$sym}: {$reason}");
                // One alert for count mismatch, not one per missing position
                if (!$countMismatchAlerted) {
                    $this->alertService->alertInvalidResponse(
                        "count_mismatch ({$arrCount}/{$posCount})",
                        "LLM returned {$arrCount} items for {$posCount} positions. Expected: " . implode(', ', array_slice($posSymbols, 0, 12)) . ($posCount > 12 ? '...' : '') . ". First: " . mb_substr(json_encode($arr[0] ?? []), 0, 100)
                    );
                    $countMismatchAlerted = true;
                }
                $this->botHistory->log('llm_invalid_response', [
                    'symbol'    => $sym,
                    'reason'    => $reason,
                    'arr_count' => $arrCount,
                    'pos_count' => $posCount,
                ]);
                $out[] = $this->doNothingDecision($sym, $reason, $raw, $provider);
                continue;
            }

            $itemSym = $item['symbol'] ?? '';
            if ($itemSym !== '' && $itemSym !== $expectedSym) {
                $this->log("LLM symbol mismatch at idx {$idx}: expected {$expectedSym}, got {$itemSym}");
                $this->alertService->alertInvalidResponse($sym, "symbol_mismatch: expected {$expectedSym}, got {$itemSym}. " . mb_substr(json_encode($item), 0, 150));
                $out[] = $this->doNothingDecision($sym, 'symbol_mismatch', json_encode($item), $provider);
                continue;
            }

            $missingFields = [];
            foreach (self::REQUIRED_DECISION_FIELDS as $field) {
                if (!isset($item[$field]) || ($field === 'action' && !in_array(strtoupper($item[$field] ?? ''), self::VALID_ACTIONS, true))) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                $this->log("Invalid LLM decision for {$sym}: missing/invalid " . implode(', ', $missingFields));
                $this->alertService->alertInvalidResponse($sym, json_encode($item));
                $this->botHistory->log('llm_invalid_response', [
                    'symbol'         => $sym,
                    'missing_fields' => $missingFields,
                    'raw_item'       => mb_substr(json_encode($item), 0, 300),
                    'prompt_version' => self::STRATEGY_VERSION,
                    'provider'       => $provider,
                ]);
                $out[] = $this->doNothingDecision($sym, 'schema_violation', json_encode($item), $provider);
                continue;
            }

            $action = strtoupper($item['action']);
            $params = $item['params'] ?? [];
            $checks = $item['checks'] ?? [];

            $closeFraction = isset($params['close_fraction']) ? max(0.05, min(1.0, (float)$params['close_fraction'])) : null;
            $avgSize       = isset($params['average_size_usdt']) ? max(0.0, (float)$params['average_size_usdt']) : null;

            $out[] = [
                'symbol'              => $sym,
                'action'              => $action,
                'confidence'          => min(100, max(0, (int)($item['confidence'] ?? 0))),
                'reason'              => mb_substr((string)($item['reason'] ?? ''), 0, 500),
                'risk'                => strtolower($item['risk'] ?? 'medium'),
                'checks'              => [
                    'pnl_positive'       => $checks['pnl_positive']       ?? null,
                    'trend'              => $checks['trend']              ?? null,
                    'averaging_allowed'  => $checks['averaging_allowed']  ?? null,
                    'strategy_profile'   => $checks['strategy_profile']   ?? null,
                    'strategy_alignment' => $checks['strategy_alignment'] ?? null,
                    'regime'             => $checks['regime']            ?? null,
                ],
                'close_fraction'      => $closeFraction,
                'average_size_usdt'   => $avgSize,
                'note'                => (string)($item['reason'] ?? ''),
                'prompt_version'      => self::STRATEGY_VERSION,
                'schema_version'      => self::SCHEMA_VERSION,
                'prompt_checksum'     => $promptChecksum,
                'canary'              => $this->isCanaryMode(),
                'provider'            => $provider,
                'llm_raw'             => null,
            ];
        }

        return $out;
    }

    private function doNothingDecision(string $symbol, string $invalidReason, string $raw, string $provider): array
    {
        return [
            'symbol'             => $symbol,
            'action'             => 'DO_NOTHING',
            'confidence'         => 0,
            'reason'             => "DO_NOTHING (LLM response invalid: {$invalidReason})",
            'risk'               => 'unknown',
            'checks'             => [],
            'close_fraction'     => null,
            'average_size_usdt'  => null,
            'note'               => '',
            'prompt_version'     => self::STRATEGY_VERSION,
            'provider'           => $provider,
            'llm_raw'            => mb_substr($raw, 0, 300),
            'canary'             => $this->isCanaryMode(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // getProposals — Level A filter + Orchestrator + per-candidate LLM
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param array $positions       Current open positions (for orchestrator when cascaded)
     * @param array|null $orchContext exposure_check, available_slots — enables per-candidate flow
     */
    public function getProposals(
        BybitService $bybitService,
        array $positions = [],
        ?array $orchContext = null
    ): array {
        if (!$this->hasAnyProvider()) {
            return [];
        }

        $markets = $bybitService->getTopMarkets(25, 'linear');
        if (empty($markets)) {
            return [];
        }

        $trading       = $this->settingsService->getTradingSettings();
        $maxMarginUsdt = (float)($trading['max_position_usdt'] ?? 100.0);
        $minMarginUsdt = max(0, (float)($trading['min_position_usdt'] ?? 10.0));
        $minLev        = max(1, (int)($trading['min_leverage'] ?? 1));
        $maxLev        = max($minLev, (int)($trading['max_leverage'] ?? 5));
        $aggr          = $trading['aggressiveness'] ?? 'balanced';
        $maxNotional   = $maxMarginUsdt * $maxLev;
        $minNotional   = $minMarginUsdt * $minLev;
        $defaultSize   = max($minNotional, 10.0);
        $botTimeframe  = max(1, (int)($trading['bot_timeframe'] ?? 5));
        $historyCandles = max(20, min(60, (int)($trading['bot_history_candles'] ?? 60)));

        // Level A: deterministic pre-filter — exclude open symbols
        $openSymbols = array_fill_keys(array_column($positions, 'symbol'), true);
        $candidates  = [];
        foreach ($markets as $m) {
            $sym = $m['symbol'] ?? '';
            if ($sym !== '' && !isset($openSymbols[$sym])) {
                $candidates[] = $sym;
            }
        }

        if (empty($candidates)) {
            return [];
        }

        // Cascaded: Orchestrator + per-candidate when we have positions and context
        $useCascaded = !empty($positions) && $orchContext !== null
            && ($orchContext['available_slots'] ?? 0) > 0
            && ($orchContext['exposure_check']['ok'] ?? true);

        if ($useCascaded && count($candidates) > 3) {
            $exposureCheck = $orchContext['exposure_check'] ?? ['total_exposure' => 0, 'max_exposure' => 0];
            $orch = $this->getPortfolioOrchestration(
                $positions,
                $exposureCheck,
                (int)($orchContext['available_slots'] ?? 0),
                $candidates
            );

            $this->botHistory->log('proposal_flow', [
                'step'                => 'orchestrator_result',
                'reason'              => $orch === null ? 'orch_null'
                    : (!$orch['can_open_new'] ? 'can_open_new_false' : (empty($orch['candidates_to_analyze']) ? 'candidates_empty' : 'ok')),
                'orch'                => $orch !== null ? ['can_open_new' => $orch['can_open_new'], 'candidates_count' => count($orch['candidates_to_analyze'] ?? [])] : null,
                'candidates_total'    => count($candidates),
            ]);

            if ($orch !== null && $orch['can_open_new'] && !empty($orch['candidates_to_analyze'])) {
                $toAnalyze = $orch['candidates_to_analyze'];
                $proposals = [];

                foreach ($toAnalyze as $symbol) {
                    $marketItem = null;
                    foreach ($markets as $m) {
                        if (($m['symbol'] ?? '') === $symbol) {
                            $marketItem = $m;
                            break;
                        }
                    }
                    if ($marketItem === null) {
                        continue;
                    }

                    $proposal = $this->analyzeCandidate($bybitService, $symbol, $marketItem, $botTimeframe, $historyCandles, $minLev, $maxLev, $maxNotional, $defaultSize, $minNotional);
                    if ($proposal !== null && ($proposal['confidence'] ?? 0) >= 70) {
                        $proposals[] = $proposal;
                    }
                }

                usort($proposals, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
                $this->botHistory->log('proposal_flow', [
                    'step'   => 'orchestrator_proposals',
                    'reason' => sprintf('cascaded: %d proposals (conf>=70)', count($proposals)),
                    'count'  => count($proposals),
                ]);
                return $proposals;
            }
        }

        // Fallback: single batch (for API preview or when orchestrator/cascaded not used)
        $batchProposals = $this->getProposalsBatch($bybitService, $markets, $minLev, $maxLev, $maxNotional, $defaultSize, $minNotional, $aggr);
        $this->botHistory->log('proposal_flow', [
            'step'   => 'batch_proposals',
            'reason' => sprintf('fallback batch: %d proposals (useCascaded=%s)', count($batchProposals), $useCascaded ? 'true' : 'false'),
            'count'  => count($batchProposals),
        ]);
        return $batchProposals;
    }

    /** Per-candidate LLM (Level C) — detailed analysis for one symbol. */
    private function analyzeCandidate(
        BybitService $bybitService,
        string $symbol,
        array $marketItem,
        int $botTimeframe,
        int $historyCandles,
        int $minLev,
        int $maxLev,
        float $maxNotional,
        float $defaultSize,
        float $minNotional
    ): ?array {
        $klineData = $bybitService->getKlineData($symbol, $botTimeframe, $historyCandles, 15);
        $klineSummary = $klineData['summary'] ?? '';

        $price    = $marketItem['lastPrice'] ?? 0;
        $chg24    = isset($marketItem['price24hPcnt']) ? round((float)$marketItem['price24hPcnt'], 2) : 0;
        $volume   = $marketItem['volume24h'] ?? 0;

        $prompt = "Per-symbol analysis for {$symbol}. Entry candidate.\n\n";
        $prompt .= "Market: price={$price} 24h%={$chg24} volume={$volume}\n";
        $prompt .= "Kline history ({$botTimeframe}m): {$klineSummary}\n\n";
        $prompt .= "Constraints: position size " . round($minNotional, 0) . "–" . round($maxNotional, 0) . " USDT, leverage {$minLev}x–{$maxLev}x.\n";
        $prompt .= "Return ONLY JSON: {\"symbol\":\"{$symbol}\",\"signal\":\"BUY|SELL\",\"confidence\":<0-100>,\"reason\":\"...\",\"position_size_usdt\":<n>,\"leverage\":<int>}. No other text.";

        $content = $this->requestLLMContent('analyze_candidate', [
            ['role' => 'system', 'content' => 'You output only valid JSON objects. No prose.'],
            ['role' => 'user', 'content' => $prompt],
        ], 0.5, 400);

        if ($content === null) {
            return null;
        }

        if (preg_match('/\{[\s\S]*\}/u', $content, $m)) {
            $item = json_decode($m[0], true);
            if (is_array($item) && ($item['symbol'] ?? '') === $symbol && in_array($item['signal'] ?? '', ['BUY', 'SELL'], true)) {
                $conf = min(100, max(0, (int)($item['confidence'] ?? 0)));
                $size = max($minNotional, min($maxNotional, (float)($item['position_size_usdt'] ?? $defaultSize)));
                $lev  = min(max((int)($item['leverage'] ?? $minLev), $minLev), $maxLev);

                return [
                    'symbol'           => $symbol,
                    'signal'           => strtoupper($item['signal']),
                    'confidence'       => $conf,
                    'reason'           => $item['reason'] ?? '',
                    'positionSizeUSDT' => $size,
                    'leverage'         => $lev,
                ];
            }
        }

        return null;
    }

    /** Legacy batch mode — single LLM call for all candidates. */
    private function getProposalsBatch(
        BybitService $bybitService,
        array $markets,
        int $minLev,
        int $maxLev,
        float $maxNotional,
        float $defaultSize,
        float $minNotional,
        string $aggr
    ): array {
        $lines = [];
        foreach ($markets as $m) {
            $lines[] = sprintf(
                '%s: price=%s 24h%%=%s volume=%s',
                $m['symbol'], $m['lastPrice'] ?? 0,
                isset($m['price24hPcnt']) ? round((float)$m['price24hPcnt'], 2) : 0,
                $m['volume24h'] ?? 0
            );
        }

        $historyContext = $this->botHistory->getWeeklySummaryText();
        $minMarginUsdt  = $minNotional / max(1, $minLev);
        $maxMarginUsdt  = $maxNotional / max(1, $minLev);

        $prompt  = "You are a professional crypto analyst. Below are top symbols with 24h data.\n\n";
        $prompt .= implode("\n", $lines);
        $prompt .= "\n\nRecent bot performance:\n" . $historyContext . "\n\n";
        $prompt .= "Pick 5-10 best trading opportunities (BUY or SELL, skip HOLD). Confidence ≥ 60. ";
        $prompt .= "Position size (notional): min {$minNotional} max {$maxNotional} USDT. Leverage {$minLev}x–{$maxLev}x. Aggressiveness: {$aggr}.\n\n";
        $prompt .= 'Return JSON array: [{"symbol":"X","signal":"BUY|SELL","confidence":<0-100>,"reason":"...","position_size_usdt":<n>,"leverage":<int>}]. No other text.';

        $content = $this->requestLLMContent('proposals', [
            ['role' => 'system', 'content' => 'You output only valid JSON arrays.'],
            ['role' => 'user', 'content' => $prompt],
        ], 0.5, 1500);

        if ($content === null) {
            return [];
        }

        $proposals = $this->parseProposalsResponse($content, $minLev, $maxLev, $maxNotional, $defaultSize, $minNotional);
        usort($proposals, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
        return $proposals;
    }

    private function parseProposalsResponse(string $content, int $minLev, int $maxLev, float $maxUsdt, float $defaultSize, float $minUsdt = 0): array
    {
        $out = [];
        if (preg_match('/\[[\s\S]*\]/u', $content, $m)) {
            $arr = json_decode($m[0], true);
            if (is_array($arr)) {
                foreach ($arr as $item) {
                    if (empty($item['symbol']) || !in_array($item['signal'] ?? '', ['BUY', 'SELL'], true)) {
                        continue;
                    }
                    $conf = (int)($item['confidence'] ?? 0);
                    if ($conf < 60) {
                        continue;
                    }
                    $size = (float)($item['position_size_usdt'] ?? $defaultSize);
                    $size = max($size, $minUsdt, 0);
                    $size = min($size, $maxUsdt);
                    $out[] = [
                        'symbol'          => $item['symbol'],
                        'signal'          => $item['signal'],
                        'confidence'      => $conf,
                        'reason'          => $item['reason'] ?? '',
                        'positionSizeUSDT'=> $size,
                        'leverage'        => min(max((int)($item['leverage'] ?? $minLev), $minLev), $maxLev),
                    ];
                }
            }
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════
    // analyzeMarket / makeTradingDecision (unchanged logic)
    // ═══════════════════════════════════════════════════════════════

    public function analyzeMarket(string $symbol, array $marketData = []): array
    {
        if (!$this->hasAnyProvider()) {
            return $this->getMockAnalysis($symbol);
        }

        try {
            $content = $this->requestLLMContent(
                'analyze_market',
                [
                    ['role' => 'system', 'content' => 'You are a professional cryptocurrency trading analyst. Respond only with valid JSON.'],
                    ['role' => 'user',   'content' => $this->buildAnalysisPrompt($symbol, $marketData)],
                ],
                0.7,
                500
            );

            if ($content !== null) {
                return $this->parseAnalysisResponse($content, $symbol);
            }
        } catch (\Exception $e) {
            $this->log('analyzeMarket Error: ' . $e->getMessage());
        }

        return $this->getMockAnalysis($symbol);
    }

    public function makeTradingDecision(string $symbol, array $marketData, array $currentPositions = []): array
    {
        $trading     = $this->settingsService->getTradingSettings();
        $analysis    = $this->analyzeMarket($symbol, $marketData);
        $maxMargin   = (float)($trading['max_position_usdt'] ?? 100.0);
        $minLev      = max(1, (int)($trading['min_leverage'] ?? 1));
        $maxLev      = max($minLev, (int)($trading['max_leverage'] ?? 5));
        $maxNotional = $maxMargin * $maxLev;

        $positionSize = min(max((float)($analysis['position_size_usdt'] ?? $maxNotional), 0.0), $maxNotional);
        $leverage     = min(max((int)($analysis['leverage'] ?? $minLev), $minLev), $maxLev);

        $decision = [
            'symbol'           => $symbol,
            'action'           => 'HOLD',
            'confidence'       => $analysis['confidence'],
            'reason'           => $analysis['reason'],
            'timestamp'        => date('Y-m-d H:i:s'),
            'marketData'       => $marketData,
            'positionSizeUSDT' => $positionSize,
            'leverage'         => $leverage,
            'tradingSettings'  => $trading,
        ];

        if ($analysis['signal'] === 'BUY' && $analysis['confidence'] > 70) {
            $has = !empty(array_filter($currentPositions, fn($p) => $p['symbol'] === $symbol && $p['side'] === 'Buy'));
            if (!$has) {
                $decision['action'] = 'OPEN_LONG';
            }
        } elseif ($analysis['signal'] === 'SELL' && $analysis['confidence'] > 70) {
            $has = !empty(array_filter($currentPositions, fn($p) => $p['symbol'] === $symbol && $p['side'] === 'Sell'));
            if (!$has) {
                $decision['action'] = 'OPEN_SHORT';
            }
        }

        return $decision;
    }

    private function buildAnalysisPrompt(string $symbol, array $marketData): string
    {
        $trading      = $this->settingsService->getTradingSettings();
        $maxMargin    = (float)($trading['max_position_usdt'] ?? 100.0);
        $minLev       = max(1, (int)($trading['min_leverage'] ?? 1));
        $maxLev       = max($minLev, (int)($trading['max_leverage'] ?? 5));
        $aggr         = $trading['aggressiveness'] ?? 'balanced';
        $maxNotional  = $maxMargin * $maxLev;

        $prompt = "Analyze {$symbol} and provide a trading signal.\n\n";
        if (!empty($marketData)) {
            $prompt .= "Market data:\n";
            foreach (['lastPrice' => 'Last Price', 'price24hPcnt' => '24h %', 'volume24h' => 'Volume', 'turnover24h' => 'Turnover', 'highPrice24h' => '24h High', 'lowPrice24h' => '24h Low'] as $key => $label) {
                if (isset($marketData[$key])) {
                    $prompt .= "- {$label}: {$marketData[$key]}\n";
                }
            }
        }
        $prompt .= "\nConstraints: max margin {$maxMargin} USDT (notional up to {$maxNotional}), leverage {$minLev}x–{$maxLev}x, aggressiveness={$aggr}\n\n";
        $prompt .= 'Respond ONLY with JSON: {"signal":"BUY|SELL|HOLD","confidence":<0-100>,"reason":"...","position_size_usdt":<n>,"leverage":<int>}';

        return $prompt;
    }

    private function parseAnalysisResponse(string $content, string $symbol): array
    {
        if (preg_match('/\{[^}]+\}/u', $content, $m)) {
            $json = json_decode($m[0], true);
            if ($json) {
                return [
                    'symbol'           => $symbol,
                    'signal'           => $json['signal'] ?? 'HOLD',
                    'confidence'       => (int)($json['confidence'] ?? 50),
                    'reason'           => $json['reason'] ?? '',
                    'timestamp'        => date('Y-m-d H:i:s'),
                    'position_size_usdt' => isset($json['position_size_usdt']) ? (float)$json['position_size_usdt'] : null,
                    'leverage'         => isset($json['leverage']) ? (int)$json['leverage'] : null,
                ];
            }
        }
        $signal = 'HOLD';
        if (stripos($content, 'BUY')  !== false) $signal = 'BUY';
        if (stripos($content, 'SELL') !== false) $signal = 'SELL';
        return ['symbol' => $symbol, 'signal' => $signal, 'confidence' => 60, 'reason' => mb_substr($content, 0, 200), 'timestamp' => date('Y-m-d H:i:s')];
    }

    private function getMockAnalysis(string $symbol): array
    {
        $signals = ['BUY', 'SELL', 'HOLD'];
        $signal  = $signals[rand(0, 2)];
        return ['symbol' => $symbol, 'signal' => $signal, 'confidence' => rand(60, 95), 'reason' => "Mock: {$signal}.", 'timestamp' => date('Y-m-d H:i:s')];
    }

    // ═══════════════════════════════════════════════════════════════
    // testConnection
    // ═══════════════════════════════════════════════════════════════

    public function testConnection(): array
    {
        $cg = $this->settingsService->getChatGPTSettings();
        $ds = $this->settingsService->getDeepseekSettings();

        $chatOk = !empty($cg['api_key']) && ($cg['enabled'] ?? false);
        $deepOk = !empty($ds['api_key']) && ($ds['enabled'] ?? false);

        if (!$chatOk && !$deepOk) {
            return ['ok' => false, 'reason' => 'Не настроен ни один LLM-провайдер'];
        }

        $lastRaw = null;

        foreach ([
            'chatgpt'  => $chatOk ? ['url' => 'https://api.openai.com/v1/chat/completions',   'key' => $cg['api_key'], 'model' => $cg['model'] ?? 'gpt-4'] : null,
            'deepseek' => $deepOk ? ['url' => 'https://api.deepseek.com/chat/completions',    'key' => $ds['api_key'], 'model' => $ds['model'] ?? 'deepseek-chat'] : null,
        ] as $name => $cfg) {
            if ($cfg === null) {
                continue;
            }
            try {
                $response = $this->httpClient->request('POST', $cfg['url'], [
                    'headers' => ['Authorization' => 'Bearer ' . $cfg['key'], 'Content-Type' => 'application/json'],
                    'json'    => ['model' => $cfg['model'], 'messages' => [['role' => 'system', 'content' => 'Reply with JSON: {"ok":true}'], ['role' => 'user', 'content' => '{"ok":true}']], 'max_tokens' => 20, 'temperature' => 0],
                    'timeout' => 15,
                ]);
                $status   = $response->getStatusCode();
                $body     = $response->getContent(false);
                $lastRaw  = $body;
                if ($status === 200) {
                    $data = json_decode($body, true);
                    if (isset($data['choices'][0]['message']['content'])) {
                        return ['ok' => true, 'message' => "Подключение к {$name} успешно."];
                    }
                }
            } catch (\Exception $e) {
                $lastRaw = $e->getMessage();
                $this->log("{$name} testConnection error: {$lastRaw}");
            }
        }

        return ['ok' => false, 'error' => 'LLM ответил некорректно или вернул ошибку', 'raw' => $lastRaw];
    }
}
