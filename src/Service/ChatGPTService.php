<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatGPTService
{
    private HttpClientInterface $httpClient;
    private SettingsService $settingsService;
    private BotHistoryService $botHistory;

    public function __construct(HttpClientInterface $httpClient, SettingsService $settingsService, BotHistoryService $botHistory)
    {
        $this->httpClient = $httpClient;
        $this->settingsService = $settingsService;
        $this->botHistory = $botHistory;
    }

    /** Безопасное логирование: редактирует API-ключи перед записью в error_log. */
    private function log(string $message): void
    {
        LogSanitizer::log('LLM', $message, $this->settingsService);
    }

    private function hasAnyProvider(): bool
    {
        $chatgpt = $this->settingsService->getChatGPTSettings();
        $deepseek = $this->settingsService->getDeepseekSettings();

        $chatOk = !empty($chatgpt['api_key']) && ($chatgpt['enabled'] ?? false);
        $deepOk = !empty($deepseek['api_key']) && ($deepseek['enabled'] ?? false);

        return $chatOk || $deepOk;
    }

    /**
     * Унифицированный вызов LLM: сначала пробуем ChatGPT, при ошибке/отказе — DeepSeek.
     */
    private function requestLLMContent(string $purpose, array $messages, float $temperature, int $maxTokens): ?string
    {
        $chatgpt = $this->settingsService->getChatGPTSettings();
        $deepseek = $this->settingsService->getDeepseekSettings();

        $chatOk = !empty($chatgpt['api_key']) && ($chatgpt['enabled'] ?? false);
        $deepOk = !empty($deepseek['api_key']) && ($deepseek['enabled'] ?? false);

        // 1. Пробуем OpenAI ChatGPT, если доступен
        if ($chatOk) {
            try {
                $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $chatgpt['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $chatgpt['model'] ?? 'gpt-4',
                        'messages' => $messages,
                        'temperature' => $temperature,
                        'max_tokens' => $maxTokens,
                    ],
                ]);

                $data = $response->toArray(false);
                if (isset($data['choices'][0]['message']['content'])) {
                    return $data['choices'][0]['message']['content'];
                }
            } catch (\Exception $e) {
                $this->log('ChatGPT ' . $purpose . ' error: ' . $e->getMessage());
                // Падаем дальше к DeepSeek
            }
        }

        // 2. Пробуем DeepSeek, если доступен
        if ($deepOk) {
            try {
                $response = $this->httpClient->request('POST', 'https://api.deepseek.com/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $deepseek['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $deepseek['model'] ?? 'deepseek-chat',
                        'messages' => $messages,
                        'temperature' => $temperature,
                        'max_tokens' => $maxTokens,
                    ],
                ]);

                $data = $response->toArray(false);
                if (isset($data['choices'][0]['message']['content'])) {
                    return $data['choices'][0]['message']['content'];
                }
            } catch (\Exception $e) {
                $this->log('DeepSeek ' . $purpose . ' error: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Анализ всех монет и предложения по сделкам, отсортированные по уверенности.
     */
    public function getProposals(BybitService $bybitService): array
    {
        if (!$this->hasAnyProvider()) {
            return [];
        }

        $markets = $bybitService->getTopMarkets(25, 'linear');
        if (empty($markets)) {
            return [];
        }

        $trading = $this->settingsService->getTradingSettings();
        $maxPositionUsdt = isset($trading['max_position_usdt']) ? (float)$trading['max_position_usdt'] : 100.0;
        $minLeverage = max(1, (int)($trading['min_leverage'] ?? 1));
        $maxLeverage = max($minLeverage, (int)($trading['max_leverage'] ?? 5));
        $aggr = $trading['aggressiveness'] ?? 'balanced';
        $defaultSize = 10.0;

        $lines = [];
        foreach ($markets as $m) {
            $lines[] = sprintf(
                "%s: price=%s 24h%%=%s volume=%s",
                $m['symbol'],
                $m['lastPrice'] ?? 0,
                isset($m['price24hPcnt']) ? round((float)$m['price24hPcnt'], 2) : 0,
                $m['volume24h'] ?? 0
            );
        }

        $historyContext = $this->botHistory->getWeeklySummaryText();

        $prompt = "You are a professional crypto analyst. Below are top symbols with 24h data.\n\n";
        $prompt .= implode("\n", $lines);
        $prompt .= "\n\nRecent bot performance summary (use this to avoid repeating mistakes and to reinforce successful patterns):\n";
        $prompt .= $historyContext . "\n\n";
        $prompt .= "Pick the best 5-10 trading opportunities (BUY or SELL only; skip HOLD). Confidence must be >= 60. ";
        $prompt .= "Assume trading fees are about 0.06% per side; avoid proposing trades where the expected move is too small and would likely be eaten by fees.\n";
        $prompt .= "Default position size: {$defaultSize} USDT. Leverage between {$minLeverage}x and {$maxLeverage}x. Aggressiveness: {$aggr}.\n\n";
        $prompt .= "Return a JSON array of objects, each: {\"symbol\": \"SYMBOL\", \"signal\": \"BUY|SELL\", \"confidence\": <0-100>, \"reason\": \"...\", \"position_size_usdt\": <number>, \"leverage\": <integer>}. ";
        $prompt .= "No other text, only the JSON array.";

        try {
            $content = $this->requestLLMContent(
                'proposals',
                [
                    ['role' => 'system', 'content' => 'You output only valid JSON arrays.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                0.5,
                1500
            );
            if ($content === null) {
                return [];
            }
            $proposals = $this->parseProposalsResponse($content, $minLeverage, $maxLeverage, $maxPositionUsdt, $defaultSize);
            usort($proposals, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
            return $proposals;
        } catch (\Exception $e) {
            $this->log('ChatGPT getProposals Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Управление уже открытыми позициями: закрытие, частичное закрытие, усреднение, перевод стопа в безубыток.
     * Возвращает массив решений по позициям.
     */
    public function manageOpenPositions(BybitService $bybitService, array $positions): array
    {
        if (!$this->hasAnyProvider()) {
            return [];
        }

        if (empty($positions)) {
            return [];
        }

        $trading = $this->settingsService->getTradingSettings();
        $maxManaged = isset($trading['max_managed_positions']) ? max(1, (int)$trading['max_managed_positions']) : 10;

        $positions = array_slice($positions, 0, $maxManaged);

        // Лимит токенов: целевой максимум для входного промпта ~14 000 символов (~3500 токенов),
        // с запасом для ответа 2 000 токенов. При превышении уменьшаем maxPricePoints.
        $charBudget   = 14000;
        $tfMinutes    = 0;
        $maxPricePoints = 30; // начальное значение, будет скорректировано

        // Определяем таймфрейм из первой позиции
        foreach ($positions as $p) {
            if (isset($p['priceHistoryTimeframe'])) {
                $tfMinutes = (int)$p['priceHistoryTimeframe'];
                break;
            }
        }
        $tfLabel = $tfMinutes > 0 ? "{$tfMinutes}min" : 'unknown';

        // Адаптивное уменьшение числа точек под бюджет
        // Грубо оцениваем: инструкции ≈ 1500 символов, история бота ≈ 600, на каждую позицию базово ≈ 120 символов
        $posCount = count($positions);
        $baseChars = 2200; // инструкции + история бота
        $perPosBase = 120; // заголовок позиции без истории
        $available = max(0, $charBudget - $baseChars - $posCount * $perPosBase);
        // Каждая ценовая точка занимает ~8 символов (число + запятая)
        $charsPerPoint = 8;
        if ($posCount > 0) {
            $maxPricePoints = (int)floor($available / ($posCount * $charsPerPoint));
            $maxPricePoints = max(5, min(30, $maxPricePoints)); // не менее 5 и не более 30
        }

        $lines = [];
        foreach ($positions as $p) {
            $historyStr = $p['priceHistory'] ?? 'no market history';
            $lines[] = sprintf(
                "%s side=%s size=%s entry=%s mark=%s pnl=%s lev=%sx opened=%s\n  market_hist(%s): %s",
                $p['symbol'] ?? 'UNKNOWN',
                $p['side'] ?? '',
                $p['size'] ?? '0',
                $p['entryPrice'] ?? '0',
                $p['markPrice'] ?? '0',
                $p['unrealizedPnl'] ?? '0',
                $p['leverage'] ?? '1',
                $p['openedAt'] ?? '',
                $tfLabel,
                $historyStr
            );
        }

        // Проверяем, по каким символам уже были события усреднения
        $events = $this->botHistory->getRecentEvents(7);
        $averagedSymbols = [];
        foreach ($events as $e) {
            if (($e['type'] ?? '') === 'average_in' && !empty($e['symbol'])) {
                $averagedSymbols[$e['symbol']] = true;
            }
        }
        $averagedList = empty($averagedSymbols) ? 'none' : implode(', ', array_keys($averagedSymbols));

        $historyContext = $this->botHistory->getWeeklySummaryText();

        // Собираем промпт
        $prompt  = "TRADING TIMEFRAME: {$tfLabel}. Use the market price history on this timeframe to assess trend/momentum.\n";
        $prompt .= "Price history = real market prices (not position prices). ⚠️ If history shows 'collecting' or few candles — note uncertainty in your decision.\n\n";
        $prompt .= "OPEN POSITIONS ({$posCount}):\n";
        $prompt .= implode("\n", $lines);
        $prompt .= "\n\nBOT HISTORY (last 7 days):\n" . $historyContext;
        $prompt .= "\nAveraged in last 7 days: {$averagedList}.\n\n";
        $prompt .= "For EACH position pick ONE action: CLOSE_FULL | CLOSE_PARTIAL | MOVE_STOP_TO_BREAKEVEN | AVERAGE_IN_ONCE | DO_NOTHING\n";
        $prompt .= "Rules: fees≈0.06%/side; avoid tiny-edge trades; only AVERAGE_IN_ONCE if not in averaged list and strong edge; CLOSE_PARTIAL fraction 0.1–0.5.\n";
        $prompt .= "Return JSON array (same order): [{\"symbol\":\"X\",\"action\":\"...\",\"close_fraction\":0.3,\"average_size_usdt\":10,\"note\":\"...\"}]\n";
        $prompt .= "Omit unused fields. No other text.";

        // Аварийная проверка токен-бюджета: если промпт > charBudget — обрезаем историю каждой позиции
        if (strlen($prompt) > $charBudget) {
            // Перестраиваем без истории цен
            $linesNoHist = [];
            foreach ($positions as $p) {
                $linesNoHist[] = sprintf(
                    "%s side=%s size=%s entry=%s mark=%s pnl=%s lev=%sx",
                    $p['symbol'] ?? 'UNKNOWN', $p['side'] ?? '',
                    $p['size'] ?? '0', $p['entryPrice'] ?? '0',
                    $p['markPrice'] ?? '0', $p['unrealizedPnl'] ?? '0',
                    $p['leverage'] ?? '1'
                );
            }
            $prompt  = "TRADING TIMEFRAME: {$tfLabel}. ⚠️ Market price history omitted (too many positions/data). Decide based on position PnL alone.\n\n";
            $prompt .= "OPEN POSITIONS ({$posCount}):\n";
            $prompt .= implode("\n", $linesNoHist);
            $prompt .= "\n\nBOT HISTORY:\n" . $historyContext;
            $prompt .= "\nAveraged: {$averagedList}.\n\n";
            $prompt .= "For EACH position pick ONE action: CLOSE_FULL|CLOSE_PARTIAL|MOVE_STOP_TO_BREAKEVEN|AVERAGE_IN_ONCE|DO_NOTHING\n";
            $prompt .= "Rules: fees≈0.06%/side; CLOSE_PARTIAL fraction 0.1–0.5.\n";
            $prompt .= "Return JSON array: [{\"symbol\":\"X\",\"action\":\"...\",\"close_fraction\":0.3,\"average_size_usdt\":10,\"note\":\"...\"}] No other text.";
        }

        try {
            $content = $this->requestLLMContent(
                'manage_positions',
                [
                    ['role' => 'system', 'content' => 'You output only valid JSON arrays with concise risk-aware decisions. Base your analysis on the provided price history.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                0.4,
                2000
            );
            if ($content === null) {
                return [];
            }
            return $this->parseManagePositionsResponse($content);
        } catch (\Exception $e) {
            $this->log('ChatGPT manageOpenPositions Error: ' . $e->getMessage());
            return [];
        }
    }

    private function parseManagePositionsResponse(string $content): array
    {
        $out = [];
        if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
            $arr = json_decode($m[0], true);
            if (is_array($arr)) {
                foreach ($arr as $item) {
                    if (empty($item['symbol']) || empty($item['action'])) {
                        continue;
                    }
                    $action = strtoupper($item['action']);
                    if (!in_array($action, ['CLOSE_FULL', 'CLOSE_PARTIAL', 'MOVE_STOP_TO_BREAKEVEN', 'AVERAGE_IN_ONCE', 'DO_NOTHING'], true)) {
                        continue;
                    }
                    $closeFraction = isset($item['close_fraction']) ? (float)$item['close_fraction'] : null;
                    if ($closeFraction !== null) {
                        $closeFraction = max(0.05, min(1.0, $closeFraction));
                    }
                    $avgSize = isset($item['average_size_usdt']) ? max(0.0, (float)$item['average_size_usdt']) : null;

                    $out[] = [
                        'symbol' => $item['symbol'],
                        'action' => $action,
                        'close_fraction' => $closeFraction,
                        'average_size_usdt' => $avgSize,
                        'note' => $item['note'] ?? '',
                    ];
                }
            }
        }
        return $out;
    }

    private function parseProposalsResponse(string $content, int $minLev, int $maxLev, float $maxUsdt, float $defaultSize): array
    {
        $out = [];
        if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
            $arr = json_decode($m[0], true);
            if (is_array($arr)) {
                foreach ($arr as $item) {
                    if (empty($item['symbol']) || !in_array($item['signal'] ?? '', ['BUY', 'SELL'], true)) {
                        continue;
                    }
                    $confidence = (int)($item['confidence'] ?? 0);
                    if ($confidence < 60) {
                        continue;
                    }
                    $size = isset($item['position_size_usdt']) ? min(max((float)$item['position_size_usdt'], 0), $maxUsdt) : $defaultSize;
                    $lev = isset($item['leverage']) ? min(max((int)$item['leverage'], $minLev), $maxLev) : $minLev;
                    $out[] = [
                        'symbol' => $item['symbol'],
                        'signal' => $item['signal'],
                        'confidence' => $confidence,
                        'reason' => $item['reason'] ?? '',
                        'positionSizeUSDT' => $size,
                        'leverage' => $lev,
                    ];
                }
            }
        }
        return $out;
    }

    public function analyzeMarket(string $symbol, array $marketData = []): array
    {
        if (!$this->hasAnyProvider()) {
            return $this->getMockAnalysis($symbol);
        }

        try {
            $prompt = $this->buildAnalysisPrompt($symbol, $marketData);

            $content = $this->requestLLMContent(
                'analyze_market',
                [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional cryptocurrency trading analyst. Analyze market data and provide trading signals (BUY, SELL, or HOLD) with confidence level and reasoning.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                0.7,
                500
            );

            if ($content !== null) {
                return $this->parseAnalysisResponse($content, $symbol);
            }

            return $this->getMockAnalysis($symbol);
        } catch (\Exception $e) {
            $this->log('ChatGPT API Error: ' . $e->getMessage());
            return $this->getMockAnalysis($symbol);
        }
    }

    public function makeTradingDecision(string $symbol, array $marketData, array $currentPositions = []): array
    {
        $trading = $this->settingsService->getTradingSettings();
        $analysis = $this->analyzeMarket($symbol, $marketData);

        // Размер сделки и плечо по умолчанию на случай, если модель их не вернула
        $maxPositionUsdt = isset($trading['max_position_usdt']) ? (float) $trading['max_position_usdt'] : 100.0;
        $minLeverage = isset($trading['min_leverage']) ? max(1, (int) $trading['min_leverage']) : 1;
        $maxLeverage = isset($trading['max_leverage']) ? max($minLeverage, (int) $trading['max_leverage']) : 5;

        $positionSize = isset($analysis['position_size_usdt'])
            ? min(max((float) $analysis['position_size_usdt'], 0.0), $maxPositionUsdt)
            : $maxPositionUsdt;

        $leverage = isset($analysis['leverage'])
            ? min(max((int) $analysis['leverage'], $minLeverage), $maxLeverage)
            : $minLeverage;

        $decision = [
            'symbol' => $symbol,
            'action' => 'HOLD',
            'confidence' => $analysis['confidence'],
            'reason' => $analysis['reason'],
            'timestamp' => date('Y-m-d H:i:s'),
            'marketData' => $marketData,
            'positionSizeUSDT' => $positionSize,
            'leverage' => $leverage,
            'tradingSettings' => $trading,
        ];

        // Логика принятия решения на основе анализа сигнала
        if ($analysis['signal'] === 'BUY' && $analysis['confidence'] > 70) {
            $hasPosition = !empty(array_filter($currentPositions, fn($p) => $p['symbol'] === $symbol && $p['side'] === 'Buy'));
            if (!$hasPosition) {
                $decision['action'] = 'OPEN_LONG';
            }
        } elseif ($analysis['signal'] === 'SELL' && $analysis['confidence'] > 70) {
            $hasPosition = !empty(array_filter($currentPositions, fn($p) => $p['symbol'] === $symbol && $p['side'] === 'Sell'));
            if (!$hasPosition) {
                $decision['action'] = 'OPEN_SHORT';
            }
        } elseif ($analysis['signal'] === 'SELL' && $analysis['confidence'] > 60) {
            // Закрыть лонг позицию
            $hasLongPosition = !empty(array_filter($currentPositions, fn($p) => $p['symbol'] === $symbol && $p['side'] === 'Buy'));
            if ($hasLongPosition) {
                $decision['action'] = 'CLOSE_LONG';
            }
        } elseif ($analysis['signal'] === 'BUY' && $analysis['confidence'] > 60) {
            // Закрыть шорт позицию
            $hasShortPosition = !empty(array_filter($currentPositions, fn($p) => $p['symbol'] === $symbol && $p['side'] === 'Sell'));
            if ($hasShortPosition) {
                $decision['action'] = 'CLOSE_SHORT';
            }
        }

        return $decision;
    }

    private function buildAnalysisPrompt(string $symbol, array $marketData): string
    {
        $prompt = "You are a professional cryptocurrency trading analyst and risk manager. Analyze the market for {$symbol} and provide a trading recommendation.\n\n";
        
        if (!empty($marketData)) {
            $prompt .= "Current market data:\n";
            $prompt .= "- Symbol: " . ($marketData['symbol'] ?? $symbol) . "\n";
            $prompt .= "- Last Price: $" . number_format(floatval($marketData['lastPrice'] ?? 0), 2) . "\n";
            $prompt .= "- 24h Change: " . number_format(floatval($marketData['price24hPcnt'] ?? 0), 2) . "%\n";
            $prompt .= "- 24h Volume: " . number_format(floatval($marketData['volume24h'] ?? 0), 0) . "\n";
            $prompt .= "- 24h Turnover: $" . number_format(floatval($marketData['turnover24h'] ?? 0), 0) . "\n";
            
            if (isset($marketData['highPrice24h'])) {
                $prompt .= "- 24h High: $" . number_format(floatval($marketData['highPrice24h']), 2) . "\n";
            }
            if (isset($marketData['lowPrice24h'])) {
                $prompt .= "- 24h Low: $" . number_format(floatval($marketData['lowPrice24h']), 2) . "\n";
            }
            if (isset($marketData['prevPrice24h'])) {
                $prompt .= "- Previous Close: $" . number_format(floatval($marketData['prevPrice24h']), 2) . "\n";
            }
        }
        
        $prompt .= "\nAnalyze the following:\n";
        $prompt .= "1. Price trend and momentum\n";
        $prompt .= "2. Volume analysis\n";
        $prompt .= "3. Market sentiment\n";
        $prompt .= "4. Risk assessment\n\n";
        
        // Ограничения по риску и параметрам сделки из настроек
        $trading = $this->settingsService->getTradingSettings();
        $maxPositionUsdt = isset($trading['max_position_usdt']) ? (float) $trading['max_position_usdt'] : 100.0;
        $minLeverage = isset($trading['min_leverage']) ? max(1, (int) $trading['min_leverage']) : 1;
        $maxLeverage = isset($trading['max_leverage']) ? max($minLeverage, (int) $trading['max_leverage']) : 5;
        $aggr = $trading['aggressiveness'] ?? 'balanced';

        $prompt .= "Risk and trading constraints:\n";
        $prompt .= "- Max position size per trade: {$maxPositionUsdt} USDT\n";
        $prompt .= "- Allowed leverage range: from {$minLeverage}x to {$maxLeverage}x\n";
        $prompt .= "- Aggressiveness level: {$aggr} (conservative = small size & low leverage, balanced = medium, aggressive = closer to limits)\n\n";

        $prompt .= "Provide a trading signal with:\n";
        $prompt .= "- Signal: BUY (if bullish), SELL (if bearish), or HOLD (if neutral/uncertain)\n";
        $prompt .= "- Confidence: 0-100 (how confident you are in this signal)\n";
        $prompt .= "- Reason: Brief explanation (2-3 sentences)\n";
        $prompt .= "- position_size_usdt: recommended position size in USDT (0 .. {$maxPositionUsdt})\n";
        $prompt .= "- leverage: integer leverage within [{$minLeverage}, {$maxLeverage}] suitable for this setup and aggressiveness\n\n";
        
        $prompt .= "IMPORTANT: Respond ONLY with valid JSON in this exact format:\n";
        $prompt .= "{\"signal\": \"BUY|SELL|HOLD\", \"confidence\": <number 0-100>, \"reason\": \"<your reasoning>\", \"position_size_usdt\": <number>, \"leverage\": <integer>}\n\n";
        $prompt .= "Do not include any text before or after the JSON.";
        
        return $prompt;
    }

    private function parseAnalysisResponse(string $content, string $symbol): array
    {
        // Попытка извлечь JSON из ответа
        if (preg_match('/\{[^}]+\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) {
                return [
                    'symbol' => $symbol,
                    'signal' => $json['signal'] ?? 'HOLD',
                    'confidence' => intval($json['confidence'] ?? 50),
                    'reason' => $json['reason'] ?? 'Analysis completed',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'position_size_usdt' => isset($json['position_size_usdt']) ? (float) $json['position_size_usdt'] : null,
                    'leverage' => isset($json['leverage']) ? (int) $json['leverage'] : null,
                ];
            }
        }

        // Fallback - парсинг текста
        $signal = 'HOLD';
        if (stripos($content, 'BUY') !== false) $signal = 'BUY';
        if (stripos($content, 'SELL') !== false) $signal = 'SELL';

        return [
            'symbol' => $symbol,
            'signal' => $signal,
            'confidence' => 70,
            'reason' => substr($content, 0, 200),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    private function getMockAnalysis(string $symbol): array
    {
        $signals = ['BUY', 'SELL', 'HOLD'];
        $signal = $signals[rand(0, 2)];
        
        return [
            'symbol' => $symbol,
            'signal' => $signal,
            'confidence' => rand(60, 95),
            'reason' => "Мокап анализа: рынок показывает признаки " . 
                       ['роста', 'падения', 'консолидации'][rand(0, 2)] . 
                       ". Рекомендуется {$signal}.",
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Диагностика подключения к ChatGPT (OpenAI API).
     */
    public function testConnection(): array
    {
        $chatgpt = $this->settingsService->getChatGPTSettings();
        $deepseek = $this->settingsService->getDeepseekSettings();

        $chatOk = !empty($chatgpt['api_key']) && ($chatgpt['enabled'] ?? false);
        $deepOk = !empty($deepseek['api_key']) && ($deepseek['enabled'] ?? false);

        if (!$chatOk && !$deepOk) {
            return [
                'ok' => false,
                'reason' => 'Не настроен ни один LLM-провайдер (ChatGPT или DeepSeek)',
            ];
        }

        $lastRaw = null;

        // 1. Пробуем ChatGPT, если доступен
        if ($chatOk) {
            try {
                $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $chatgpt['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $chatgpt['model'] ?? 'gpt-4',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are a health-check endpoint for an application. Reply with a short JSON object only.',
                            ],
                            [
                                'role' => 'user',
                                'content' => 'Return JSON: {"ok": true}',
                            ],
                        ],
                        'max_tokens' => 20,
                        'temperature' => 0,
                    ],
                ]);

                $status = $response->getStatusCode();
                $body = $response->getContent(false);
                $lastRaw = $body;

                if ($status === 200) {
                    $data = json_decode($body, true);
                    if (isset($data['choices'][0]['message']['content']) &&
                        stripos($data['choices'][0]['message']['content'], '"ok"') !== false) {
                        return [
                            'ok' => true,
                            'message' => 'Подключение к ChatGPT успешно. Ключ и модель работают.',
                        ];
                    }
                }
            } catch (\Exception $e) {
                $lastRaw = $e->getMessage();
                $this->log('ChatGPT testConnection error: ' . $e->getMessage());
            }
        }

        // 2. Пробуем DeepSeek, если доступен
        if ($deepOk) {
            try {
                $response = $this->httpClient->request('POST', 'https://api.deepseek.com/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $deepseek['api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $deepseek['model'] ?? 'deepseek-chat',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are a health-check endpoint for an application. Reply with a short JSON object only.',
                            ],
                            [
                                'role' => 'user',
                                'content' => 'Return JSON: {"ok": true}',
                            ],
                        ],
                        'max_tokens' => 20,
                        'temperature' => 0,
                    ],
                ]);

                $status = $response->getStatusCode();
                $body = $response->getContent(false);
                $lastRaw = $body;

                if ($status === 200) {
                    $data = json_decode($body, true);
                    if (isset($data['choices'][0]['message']['content']) &&
                        stripos($data['choices'][0]['message']['content'], '"ok"') !== false) {
                        return [
                            'ok' => true,
                            'message' => 'Подключение к DeepSeek успешно. Ключ и модель работают.',
                        ];
                    }
                }
            } catch (\Exception $e) {
                $lastRaw = $e->getMessage();
                $this->log('DeepSeek testConnection error: ' . $e->getMessage());
            }
        }

        return [
            'ok' => false,
            'error' => 'LLM ответил не так, как ожидалось или вернул ошибку',
            'raw' => $lastRaw,
        ];
    }
}

