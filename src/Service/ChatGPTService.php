<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatGPTService
{
    /**
     * Prompt version written into every bot history event.
     * Bump when the manageOpenPositions prompt schema changes.
     */
    private const MANAGE_PROMPT_VERSION = 'manage_v3';

    /**
     * Required top-level keys in each LLM decision object.
     * Missing/invalid → DO_NOTHING + alert.
     */
    private const REQUIRED_DECISION_FIELDS = ['symbol', 'action', 'confidence', 'reason', 'risk'];

    /** Valid action values */
    private const VALID_ACTIONS = [
        'CLOSE_FULL', 'CLOSE_PARTIAL', 'MOVE_STOP_TO_BREAKEVEN', 'AVERAGE_IN_ONCE', 'DO_NOTHING',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService    $settingsService,
        private readonly BotHistoryService  $botHistory,
        private readonly AlertService       $alertService
    ) {}

    private function log(string $message): void
    {
        LogSanitizer::log('LLM', $message, $this->settingsService);
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
                $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                    'headers' => ['Authorization' => 'Bearer ' . $cg['api_key'], 'Content-Type' => 'application/json'],
                    'json'    => ['model' => $cg['model'] ?? 'gpt-4', 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens],
                    'timeout' => 60,
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
                $response = $this->httpClient->request('POST', 'https://api.deepseek.com/chat/completions', [
                    'headers' => ['Authorization' => 'Bearer ' . $ds['api_key'], 'Content-Type' => 'application/json'],
                    'json'    => ['model' => $ds['model'] ?? 'deepseek-chat', 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens],
                    'timeout' => 60,
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
    // manageOpenPositions — strict contract v3
    // ═══════════════════════════════════════════════════════════════

    /**
     * Asks LLM for a decision on each open position.
     *
     * Each returned item is guaranteed to have:
     *   symbol, action, confidence, reason, risk,
     *   params (close_fraction?, average_size_usdt?),
     *   checks (pnl_positive?, trend?, averaging_allowed?),
     *   prompt_version, llm_raw (only when JSON was invalid)
     *
     * On invalid JSON from LLM: all positions → DO_NOTHING, alert sent.
     */
    public function manageOpenPositions(BybitService $bybitService, array $positions): array
    {
        if (!$this->hasAnyProvider() || empty($positions)) {
            return [];
        }

        $trading    = $this->settingsService->getTradingSettings();
        $maxManaged = max(1, (int)($trading['max_managed_positions'] ?? 10));
        $positions  = array_slice($positions, 0, $maxManaged);
        $posCount   = count($positions);

        // Adaptive price-point budget
        $charBudget     = 14000;
        $available      = max(0, $charBudget - 2500 - $posCount * 130);
        $maxPricePoints = $posCount > 0 ? max(5, min(30, (int)floor($available / ($posCount * 8)))) : 30;

        $tfMinutes = 0;
        foreach ($positions as $p) {
            if (isset($p['priceHistoryTimeframe'])) {
                $tfMinutes = (int)$p['priceHistoryTimeframe'];
                break;
            }
        }
        $tfLabel = $tfMinutes > 0 ? "{$tfMinutes}min" : 'unknown';

        // Build position lines
        $lines = [];
        foreach ($positions as $p) {
            $hist   = $p['priceHistory'] ?? 'no market history';
            $lines[] = sprintf(
                "%s side=%s size=%s entry=%s mark=%s pnl=%s lev=%sx opened=%s\n  market_hist(%s): %s",
                $p['symbol'] ?? 'UNKNOWN', $p['side'] ?? '',
                $p['size'] ?? '0', $p['entryPrice'] ?? '0',
                $p['markPrice'] ?? '0', $p['unrealizedPnl'] ?? '0',
                $p['leverage'] ?? '1', $p['openedAt'] ?? '',
                $tfLabel, $hist
            );
        }

        // Recent averaging info
        $events          = $this->botHistory->getRecentEvents(7);
        $averagedSymbols = [];
        foreach ($events as $e) {
            if (($e['type'] ?? '') === 'average_in' && !empty($e['symbol'])) {
                $averagedSymbols[$e['symbol']] = true;
            }
        }
        $averagedList    = empty($averagedSymbols) ? 'none' : implode(', ', array_keys($averagedSymbols));
        $historyContext  = $this->botHistory->getWeeklySummaryText();

        // ── Strict-contract prompt ────────────────────────────────
        $schema = <<<'JSON'
{
  "symbol": "BTCUSDT",
  "action": "CLOSE_FULL|CLOSE_PARTIAL|MOVE_STOP_TO_BREAKEVEN|AVERAGE_IN_ONCE|DO_NOTHING",
  "confidence": <integer 0-100>,
  "reason": "<1-3 sentences>",
  "risk": "low|medium|high",
  "params": {
    "close_fraction": <0.1-1.0 or null>,
    "average_size_usdt": <number or null>
  },
  "checks": {
    "pnl_positive": <true|false>,
    "trend": "bullish|bearish|flat",
    "averaging_allowed": <true|false>
  }
}
JSON;

        $prompt  = "TRADING TIMEFRAME: {$tfLabel}. Use market price history on this timeframe for trend/momentum.\n";
        $prompt .= "Price history = real market prices. ⚠️ If history is insufficient — note uncertainty.\n\n";
        $prompt .= "OPEN POSITIONS ({$posCount}):\n" . implode("\n\n", $lines);
        $prompt .= "\n\nBOT HISTORY (last 7 days):\n" . $historyContext;
        $prompt .= "\nAveraged in last 7 days: {$averagedList}.\n\n";
        $prompt .= "RULES:\n";
        $prompt .= "- Fees ≈ 0.06 %/side; avoid tiny-edge trades.\n";
        $prompt .= "- Only AVERAGE_IN_ONCE if symbol NOT in averaged list AND you see a strong edge.\n";
        $prompt .= "- CLOSE_PARTIAL fraction must be 0.1–0.5.\n";
        $prompt .= "- Fill checks.pnl_positive from position pnl field.\n\n";
        $prompt .= "STRICT OUTPUT CONTRACT: Return a JSON **array** (one element per position, same order).\n";
        $prompt .= "Each element MUST contain ALL these fields:\n{$schema}\n";
        $prompt .= "Missing/invalid fields → set action to DO_NOTHING. No extra text.";

        // Emergency token-budget fallback
        if (strlen($prompt) > $charBudget) {
            $shortLines = [];
            foreach ($positions as $p) {
                $shortLines[] = sprintf(
                    "%s side=%s size=%s entry=%s mark=%s pnl=%s lev=%sx",
                    $p['symbol'] ?? 'UNKNOWN', $p['side'] ?? '',
                    $p['size'] ?? '0', $p['entryPrice'] ?? '0',
                    $p['markPrice'] ?? '0', $p['unrealizedPnl'] ?? '0',
                    $p['leverage'] ?? '1'
                );
            }
            $prompt  = "TRADING TIMEFRAME: {$tfLabel}. ⚠️ History omitted (token budget).\n\n";
            $prompt .= "OPEN POSITIONS ({$posCount}):\n" . implode("\n", $shortLines);
            $prompt .= "\n\nBOT HISTORY:\n" . $historyContext;
            $prompt .= "\nAveraged: {$averagedList}.\n\n";
            $prompt .= "STRICT OUTPUT CONTRACT: JSON array, each element:\n{$schema}\nNo extra text.";
        }

        $result = $this->requestLLMRaw(
            'manage_positions',
            [
                ['role' => 'system', 'content' => 'You output only valid JSON arrays. Follow the exact schema provided. No prose.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            0.4,
            2000
        );

        $rawContent = $result['content'];
        $provider   = $result['provider'] ?? 'unknown';

        if ($rawContent === null) {
            // LLM completely failed — alerts already sent in requestLLMRaw
            return [];
        }

        return $this->parseManageResponse($rawContent, $positions, $provider);
    }

    /**
     * Parse and validate LLM response against strict schema.
     * Any item failing validation is replaced with DO_NOTHING + llm_raw stored.
     */
    private function parseManageResponse(string $raw, array $positions, string $provider): array
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
                'prompt_version' => self::MANAGE_PROMPT_VERSION,
                'provider'       => $provider,
            ]);
            foreach ($positions as $p) {
                $sym = $p['symbol'] ?? 'UNKNOWN';
                $this->alertService->alertInvalidResponse($sym, $raw);
                $out[] = $this->doNothingDecision($sym, 'invalid_json', $raw, $provider);
            }
            return $out;
        }

        // Build symbol-indexed map for alignment
        $posSymbols = array_map(fn($p) => $p['symbol'] ?? '', $positions);

        foreach ($arr as $idx => $item) {
            $sym       = $item['symbol'] ?? ($posSymbols[$idx] ?? 'UNKNOWN');
            $missingFields = [];

            foreach (self::REQUIRED_DECISION_FIELDS as $field) {
                if (!isset($item[$field]) || ($field === 'action' && !in_array(strtoupper($item[$field]), self::VALID_ACTIONS, true))) {
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
                    'prompt_version' => self::MANAGE_PROMPT_VERSION,
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
                    'pnl_positive'      => $checks['pnl_positive']    ?? null,
                    'trend'             => $checks['trend']           ?? null,
                    'averaging_allowed' => $checks['averaging_allowed'] ?? null,
                ],
                'close_fraction'      => $closeFraction,
                'average_size_usdt'   => $avgSize,
                'note'                => (string)($item['reason'] ?? ''), // backward compat
                'prompt_version'      => self::MANAGE_PROMPT_VERSION,
                'provider'            => $provider,
                'llm_raw'             => null, // valid → no raw needed
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
            'prompt_version'     => self::MANAGE_PROMPT_VERSION,
            'provider'           => $provider,
            'llm_raw'            => mb_substr($raw, 0, 300),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // getProposals
    // ═══════════════════════════════════════════════════════════════

    public function getProposals(BybitService $bybitService): array
    {
        if (!$this->hasAnyProvider()) {
            return [];
        }

        $markets = $bybitService->getTopMarkets(25, 'linear');
        if (empty($markets)) {
            return [];
        }

        $trading         = $this->settingsService->getTradingSettings();
        $maxPositionUsdt = (float)($trading['max_position_usdt'] ?? 100.0);
        $minLev          = max(1, (int)($trading['min_leverage'] ?? 1));
        $maxLev          = max($minLev, (int)($trading['max_leverage'] ?? 5));
        $aggr            = $trading['aggressiveness'] ?? 'balanced';
        $defaultSize     = 10.0;

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

        $prompt  = "You are a professional crypto analyst. Below are top symbols with 24h data.\n\n";
        $prompt .= implode("\n", $lines);
        $prompt .= "\n\nRecent bot performance:\n" . $historyContext . "\n\n";
        $prompt .= "Pick 5-10 best trading opportunities (BUY or SELL, skip HOLD). Confidence ≥ 60. ";
        $prompt .= "Fees ≈ 0.06 %/side; avoid tiny-edge proposals.\n";
        $prompt .= "Default size: {$defaultSize} USDT. Leverage {$minLev}x–{$maxLev}x. Aggressiveness: {$aggr}.\n\n";
        $prompt .= 'Return JSON array: [{"symbol":"X","signal":"BUY|SELL","confidence":<0-100>,"reason":"...","position_size_usdt":<n>,"leverage":<int>}]. No other text.';

        try {
            $content = $this->requestLLMContent('proposals', [
                ['role' => 'system', 'content' => 'You output only valid JSON arrays.'],
                ['role' => 'user',   'content' => $prompt],
            ], 0.5, 1500);

            if ($content === null) {
                return [];
            }

            $proposals = $this->parseProposalsResponse($content, $minLev, $maxLev, $maxPositionUsdt, $defaultSize);
            usort($proposals, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
            return $proposals;
        } catch (\Exception $e) {
            $this->log('getProposals Error: ' . $e->getMessage());
            return [];
        }
    }

    private function parseProposalsResponse(string $content, int $minLev, int $maxLev, float $maxUsdt, float $defaultSize): array
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
                    $out[] = [
                        'symbol'          => $item['symbol'],
                        'signal'          => $item['signal'],
                        'confidence'      => $conf,
                        'reason'          => $item['reason'] ?? '',
                        'positionSizeUSDT'=> min(max((float)($item['position_size_usdt'] ?? $defaultSize), 0), $maxUsdt),
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
        $trading   = $this->settingsService->getTradingSettings();
        $analysis  = $this->analyzeMarket($symbol, $marketData);
        $maxUsdt   = (float)($trading['max_position_usdt'] ?? 100.0);
        $minLev    = max(1, (int)($trading['min_leverage'] ?? 1));
        $maxLev    = max($minLev, (int)($trading['max_leverage'] ?? 5));

        $positionSize = min(max((float)($analysis['position_size_usdt'] ?? $maxUsdt), 0.0), $maxUsdt);
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
        $trading    = $this->settingsService->getTradingSettings();
        $maxUsdt    = (float)($trading['max_position_usdt'] ?? 100.0);
        $minLev     = max(1, (int)($trading['min_leverage'] ?? 1));
        $maxLev     = max($minLev, (int)($trading['max_leverage'] ?? 5));
        $aggr       = $trading['aggressiveness'] ?? 'balanced';

        $prompt = "Analyze {$symbol} and provide a trading signal.\n\n";
        if (!empty($marketData)) {
            $prompt .= "Market data:\n";
            foreach (['lastPrice' => 'Last Price', 'price24hPcnt' => '24h %', 'volume24h' => 'Volume', 'turnover24h' => 'Turnover', 'highPrice24h' => '24h High', 'lowPrice24h' => '24h Low'] as $key => $label) {
                if (isset($marketData[$key])) {
                    $prompt .= "- {$label}: {$marketData[$key]}\n";
                }
            }
        }
        $prompt .= "\nConstraints: maxSize={$maxUsdt} USDT, leverage {$minLev}x–{$maxLev}x, aggressiveness={$aggr}\n\n";
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
