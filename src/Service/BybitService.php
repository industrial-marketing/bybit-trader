<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class BybitService
{
    private HttpClientInterface $httpClient;
    private SettingsService $settingsService;
    private array $instrumentCache = [];

    public function __construct(HttpClientInterface $httpClient, SettingsService $settingsService)
    {
        $this->httpClient = $httpClient;
        $this->settingsService = $settingsService;
    }

    /** Безопасное логирование: редактирует API-ключи перед записью в error_log. */
    private function log(string $message): void
    {
        LogSanitizer::log('Bybit', $message, $this->settingsService);
    }

    public function getPositions(): array
    {
        $settings = $this->settingsService->getBybitSettings();
        
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            // Нет ключей – используем мок-данные
            return $this->getMockPositions();
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            // Для линейных контрактов Bybit требует symbol или settleCoin
            $params = [
                'category' => 'linear',
                'settleCoin' => 'USDT',
            ];
            $response = $this->httpClient->request('GET', $baseUrl . '/v5/position/list', [
                'query' => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings)
            ]);

            $data = $response->toArray();
            
            if (isset($data['retCode']) && $data['retCode'] === 0 && isset($data['result']['list'])) {
                return $this->formatPositions($data['result']['list']);
            }

            // Ключи заданы, но API вернул ошибку – не подменяем моками, отдаем пустой список
            return [];
        } catch (\Exception $e) {
            $this->log('API Error (getPositions): ' . $e->getMessage());
            if (isset($response)) {
                try {
                    $errorData = $response->toArray(false);
                    $this->log('API Response: ' . json_encode($errorData));
                } catch (\Exception $ex) {
                    $this->log('API Raw Response: ' . $response->getContent(false));
                }
            }
            // Ошибка при реальном запросе – возвращаем пустой список, чтобы на фронте было видно, что данных нет
            return [];
        }
    }

    public function getTrades(int $limit = 100): array
    {
        $settings = $this->settingsService->getBybitSettings();
        
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            // Нет ключей – используем мок-данные
            return $this->getMockTrades($limit);
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params = [
                'category' => 'linear',
                'settleCoin' => 'USDT',
                'limit' => $limit,
            ];
            $response = $this->httpClient->request('GET', $baseUrl . '/v5/execution/list', [
                'query' => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/execution/list', $params, $settings)
            ]);

            $data = $response->toArray();
            
            if (isset($data['retCode']) && $data['retCode'] === 0 && isset($data['result']['list'])) {
                return $this->formatTrades($data['result']['list']);
            }

            // Ключи заданы, но API вернул ошибку – не подменяем моками, отдаем пустой список
            return [];
        } catch (\Exception $e) {
            $this->log('API Error (getTrades): ' . $e->getMessage());
            if (isset($response)) {
                try {
                    $errorData = $response->toArray(false);
                    $this->log('API Response: ' . json_encode($errorData));
                } catch (\Exception $ex) {
                    $this->log('API Raw Response: ' . $response->getContent(false));
                }
            }
            // Ошибка при реальном запросе – возвращаем пустой список
            return [];
        }
    }

    public function getMarketData(string $symbol = 'BTCUSDT'): array
    {
        $settings = $this->settingsService->getBybitSettings();
        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        try {
            $response = $this->httpClient->request('GET', $baseUrl . '/v5/market/tickers', [
                'query' => [
                    'category' => 'linear',
                    'symbol' => $symbol
                ]
            ]);

            $data = $response->toArray();
            
            if (isset($data['retCode']) && $data['retCode'] === 0 && isset($data['result']['list'][0])) {
                return $data['result']['list'][0];
            }

            // Ошибка/пустой ответ – не подменяем моками, отдаем пустой массив
            return [];
        } catch (\Exception $e) {
            $this->log('Market Data Error: ' . $e->getMessage());
            return [];
        }
    }

    public function getBalance(): array
    {
        $settings = $this->settingsService->getBybitSettings();

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return [
                'totalEquity' => 0.0,
                'walletBalance' => 0.0,
                'availableBalance' => 0.0,
                'unrealisedPnl' => 0.0,
            ];
        }

        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        try {
            $params = [
                'accountType' => 'UNIFIED',
                'coin' => 'USDT',
            ];

            $response = $this->httpClient->request('GET', $baseUrl . '/v5/account/wallet-balance', [
                'query' => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/account/wallet-balance', $params, $settings),
            ]);

            $data = $response->toArray(false);

            if (!isset($data['retCode']) || $data['retCode'] !== 0 || empty($data['result']['list'][0])) {
                return [
                    'totalEquity' => 0.0,
                    'walletBalance' => 0.0,
                    'availableBalance' => 0.0,
                    'unrealisedPnl' => 0.0,
                ];
            }

            $account = $data['result']['list'][0];
            $totalEquity = isset($account['totalEquity']) ? (float) $account['totalEquity'] : 0.0;
            $totalAvailable = isset($account['totalAvailableBalance']) ? (float) $account['totalAvailableBalance'] : 0.0;

            // Данные именно по USDT – пригодятся для PnL и отладки, но на дашборде показываем общую картину по аккаунту.
            $usdtWallet = 0.0;
            $unrealisedPnl = 0.0;

            if (!empty($account['coin']) && is_array($account['coin'])) {
                foreach ($account['coin'] as $coin) {
                    if (($coin['coin'] ?? '') === 'USDT') {
                        $usdtWallet = isset($coin['walletBalance']) ? (float) $coin['walletBalance'] : 0.0;
                        $unrealisedPnl = isset($coin['unrealisedPnl']) ? (float) $coin['unrealisedPnl'] : 0.0;
                        break;
                    }
                }
            }

            // Для пользователя на дашборде:
            // - "Баланс USDT" показываем как общую стоимость аккаунта (totalEquity ~ как на сайте "≈ USD").
            // - "Доступно USDT" — как totalAvailableBalance (доступный маржинальный баланс).
            return [
                'totalEquity' => $totalEquity,
                'walletBalance' => $totalEquity,
                'availableBalance' => $totalAvailable,
                'unrealisedPnl' => $unrealisedPnl,
                'usdtWallet' => $usdtWallet,
            ];
        } catch (\Exception $e) {
            $this->log('Balance Error: ' . $e->getMessage());

            return [
                'totalEquity' => 0.0,
                'walletBalance' => 0.0,
                'availableBalance' => 0.0,
                'unrealisedPnl' => 0.0,
            ];
        }
    }

    /**
     * Топ рынков (монет) по 24ч обороту/объёму.
     */
    public function getTopMarkets(int $limit = 100, string $category = 'linear'): array
    {
        $settings = $this->settingsService->getBybitSettings();
        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        try {
            $response = $this->httpClient->request('GET', $baseUrl . '/v5/market/tickers', [
                'query' => [
                    'category' => $category,
                ],
            ]);

            $data = $response->toArray();

            if (!isset($data['retCode']) || $data['retCode'] !== 0 || empty($data['result']['list'])) {
                return [];
            }

            $list = $data['result']['list'];

            // Сортируем по обороту за 24ч (turnover24h) по убыванию
            usort($list, function (array $a, array $b): int {
                $av = isset($a['turnover24h']) ? (float) $a['turnover24h'] : 0.0;
                $bv = isset($b['turnover24h']) ? (float) $b['turnover24h'] : 0.0;
                return $bv <=> $av;
            });

            // Дедупликация по базовому активу: на тестнете/некоторых окружениях приходят и BTCUSDT (некорректные данные), и BTCPERP (корректные). Оставляем один символ на актив, предпочитая *PERP.
            $byBase = [];
            foreach ($list as $item) {
                $sym = $item['symbol'] ?? '';
                $base = $this->getBaseAssetFromSymbol($sym);
                if ($base === '') {
                    $byBase[$sym] = $item;
                    continue;
                }
                $existing = $byBase[$base] ?? null;
                $keepThis = true;
                if ($existing !== null) {
                    $existingSym = $existing['symbol'] ?? '';
                    $existingIsPerp = str_ends_with($existingSym, 'PERP');
                    $thisIsPerp = str_ends_with($sym, 'PERP');
                    if ($thisIsPerp && !$existingIsPerp) {
                        $byBase[$base] = $item;
                    } elseif (!$thisIsPerp && $existingIsPerp) {
                        $keepThis = false;
                    } else {
                        $keepThis = (float)($item['turnover24h'] ?? 0) >= (float)($existing['turnover24h'] ?? 0);
                        if ($keepThis) {
                            $byBase[$base] = $item;
                        }
                    }
                } else {
                    $byBase[$base] = $item;
                }
            }
            $list = array_values($byBase);

            // Повторная сортировка по обороту после дедупликации
            usort($list, function (array $a, array $b): int {
                $av = isset($a['turnover24h']) ? (float) $a['turnover24h'] : 0.0;
                $bv = isset($b['turnover24h']) ? (float) $b['turnover24h'] : 0.0;
                return $bv <=> $av;
            });

            $list = array_slice($list, 0, $limit);

            return array_map(function (array $item): array {
                $lastPrice = isset($item['lastPrice']) ? (float) $item['lastPrice'] : 0.0;
                $changePcnt = isset($item['price24hPcnt']) ? (float) $item['price24hPcnt'] * 100 : 0.0; // Bybit даёт долю, умножаем на 100
                $volume24h = isset($item['volume24h']) ? (float) $item['volume24h'] : 0.0;
                $turnover24h = isset($item['turnover24h']) ? (float) $item['turnover24h'] : 0.0;

                return [
                    'symbol' => $item['symbol'] ?? '',
                    'lastPrice' => $lastPrice,
                    'price24hPcnt' => $changePcnt,
                    'highPrice24h' => isset($item['highPrice24h']) ? (float) $item['highPrice24h'] : null,
                    'lowPrice24h' => isset($item['lowPrice24h']) ? (float) $item['lowPrice24h'] : null,
                    'volume24h' => $volume24h,
                    'turnover24h' => $turnover24h,
                ];
            }, $list);
        } catch (\Exception $e) {
            $this->log('Market Top Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получает исторические свечи (kline) с Bybit и форматирует их для LLM-промпта.
     * Публичный эндпоинт — авторизация не требуется.
     *
     * @param int $intervalMinutes Таймфрейм в минутах: 1,5,15,30,60,240,1440
     * @param int $limit           Количество свечей (max 200, рекомендуется 60)
     * @param int $maxPricePoints  Максимум цен в строке вывода (токен-бюджет)
     */
    public function getKlineHistory(string $symbol, int $intervalMinutes, int $limit = 60, int $maxPricePoints = 30): string
    {
        $settings = $this->settingsService->getBybitSettings();
        $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        // Маппинг минут → Bybit interval
        $interval = match (true) {
            $intervalMinutes >= 1440 => 'D',
            $intervalMinutes >= 720  => '720',
            $intervalMinutes >= 360  => '360',
            $intervalMinutes >= 240  => '240',
            $intervalMinutes >= 120  => '120',
            $intervalMinutes >= 60   => '60',
            $intervalMinutes >= 30   => '30',
            $intervalMinutes >= 15   => '15',
            $intervalMinutes >= 5    => '5',
            $intervalMinutes >= 3    => '3',
            default                  => '1',
        };

        $tfLabel = match (true) {
            $intervalMinutes >= 1440 => '1d',
            $intervalMinutes >= 60   => ($intervalMinutes / 60) . 'h',
            default                  => "{$intervalMinutes}m",
        };

        try {
            $response = $this->httpClient->request('GET', $baseUrl . '/v5/market/kline', [
                'query' => [
                    'category' => 'linear',
                    'symbol'   => $symbol,
                    'interval' => $interval,
                    'limit'    => min($limit, 200),
                ],
            ]);

            $data = $response->toArray(false);

            if (!isset($data['retCode']) || $data['retCode'] !== 0 || empty($data['result']['list'])) {
                $err = $data['retMsg'] ?? 'no data';
                return "[kline error for {$symbol}: {$err}]";
            }

            // Bybit возвращает свечи от новейшей к старейшей — разворачиваем
            $candles = array_reverse($data['result']['list']);
            // Каждая свеча: [startTime, open, high, low, close, volume, turnover]
            $closes = array_map(fn($c) => (float)($c[4] ?? 0), $candles);
            $highs  = array_map(fn($c) => (float)($c[2] ?? 0), $candles);
            $lows   = array_map(fn($c) => (float)($c[3] ?? 0), $candles);

            $count  = count($closes);
            $first  = $closes[0] ?? 0;
            $last   = end($closes);
            $minP   = min($lows);
            $maxP   = max($highs);

            $trend = 'FLAT';
            if ($last > $first * 1.001) {
                $trend = 'UP';
            } elseif ($last < $first * 0.999) {
                $trend = 'DOWN';
            }

            // Компактный заголовок
            $header = sprintf(
                '[%d %s candles | open=%s close=%s min=%s max=%s trend=%s]',
                $count, $tfLabel, $first, $last, $minP, $maxP, $trend
            );

            // Последние N close-цен
            $recentCloses = array_slice($closes, -$maxPricePoints);
            $pricesStr    = implode(',', array_map(fn($p) => round($p, 6), $recentCloses));

            return $header . ' closes:' . $pricesStr;
        } catch (\Exception $e) {
            $this->log("getKlineHistory({$symbol},{$interval}) error: " . $e->getMessage());
            return "[kline unavailable for {$symbol}]";
        }
    }

    public function getOpenOrders(string $symbol = ''): array
    {
        $settings = $this->settingsService->getBybitSettings();
        
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            // Нет ключей – никаких реальных ордеров нет
            return [];
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params = [
                'category' => 'linear',
                'settleCoin' => 'USDT'
            ];
            
            if (!empty($symbol)) {
                $params['symbol'] = $symbol;
            }

            $response = $this->httpClient->request('GET', $baseUrl . '/v5/order/realtime', [
                'query' => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/order/realtime', $params, $settings)
            ]);

            $data = $response->toArray();
            
            if (isset($data['retCode']) && $data['retCode'] === 0 && isset($data['result']['list'])) {
                return $this->formatOrders($data['result']['list']);
            }

            return [];
        } catch (\Exception $e) {
            $this->log('API Error (getOpenOrders): ' . $e->getMessage());
            return [];
        }
    }

    public function getClosedTrades(int $limit = 100): array
    {
        $settings = $this->settingsService->getBybitSettings();
        
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            // Нет ключей – используем мок-данные
            return $this->getMockTrades($limit);
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params = [
                'category' => 'linear',
                'settleCoin' => 'USDT',
                'limit' => $limit,
            ];
            $response = $this->httpClient->request('GET', $baseUrl . '/v5/execution/list', [
                'query' => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/execution/list', $params, $settings)
            ]);

            $data = $response->toArray();
            
            if (isset($data['retCode']) && $data['retCode'] === 0 && isset($data['result']['list'])) {
                // Фильтруем только закрытые сделки
                $closedTrades = array_filter($data['result']['list'], function($trade) {
                    return isset($trade['closedPnl']) && $trade['closedPnl'] !== null && $trade['closedPnl'] !== '';
                });
                return $this->formatTrades(array_values($closedTrades));
            }

            return [];
        } catch (\Exception $e) {
            $this->log('API Error (getClosedTrades): ' . $e->getMessage());
            if (isset($response)) {
                try {
                    $errorData = $response->toArray(false);
                    $this->log('API Response: ' . json_encode($errorData));
                } catch (\Exception $ex) {
                    $this->log('API Raw Response: ' . $response->getContent(false));
                }
            }
            return [];
        }
    }

    public function getStatistics(): array
    {
        // Пытаемся взять закрытые сделки; если Bybit ничего не отдаёт (особенность testnet/UTA),
        // fallback на общий список сделок, чтобы хотя бы считать количество.
        $trades = $this->getClosedTrades(1000);
        if (empty($trades)) {
            $trades = $this->getTrades(1000);
        }

        if (empty($trades)) {
            return [
                'totalTrades' => 0,
                'winRate' => 0.0,
                'totalProfit' => 0.0,
                'averageProfit' => 0.0,
                'maxDrawdown' => 0.0,
                'profitFactor' => 0.0,
                'winningTrades' => 0,
                'losingTrades' => 0,
            ];
        }

        $closedTrades = array_filter($trades, fn($t) => isset($t['closedPnl']) && $t['closedPnl'] !== null);
        $totalTrades = count($closedTrades);
        $winningTrades = array_filter($closedTrades, fn($t) => floatval($t['closedPnl'] ?? 0) > 0);
        $losingTrades = array_filter($closedTrades, fn($t) => floatval($t['closedPnl'] ?? 0) < 0);

        $totalProfit = array_sum(array_map(fn($t) => floatval($t['closedPnl'] ?? 0), $closedTrades));
        $winRate = $totalTrades > 0 ? (count($winningTrades) / $totalTrades) * 100 : 0;
        $avgProfit = $totalTrades > 0 ? $totalProfit / $totalTrades : 0;

        $profits = array_map(fn($t) => floatval($t['closedPnl'] ?? 0), $closedTrades);
        $maxDrawdown = $profits ? min($profits) : 0;

        $winningSum = array_sum(array_map(fn($t) => floatval($t['closedPnl'] ?? 0), $winningTrades));
        $losingSum = abs(array_sum(array_map(fn($t) => floatval($t['closedPnl'] ?? 0), $losingTrades)));
        $profitFactor = $losingSum > 0 ? $winningSum / $losingSum : ($winningSum > 0 ? 999 : 0);

        return [
            'totalTrades' => $totalTrades,
            'winRate' => round($winRate, 2),
            'totalProfit' => round($totalProfit, 2),
            'averageProfit' => round($avgProfit, 2),
            'maxDrawdown' => round($maxDrawdown, 2),
            'profitFactor' => round($profitFactor, 2),
            'winningTrades' => count($winningTrades),
            'losingTrades' => count($losingTrades)
        ];
    }

    /** Базовый актив для дедупликации: BTCUSDT/BTCPERP -> BTC, 1000PEPEUSDT -> 1000PEPE */
    private function getBaseAssetFromSymbol(string $symbol): string
    {
        if ($symbol === '') {
            return '';
        }
        if (str_ends_with($symbol, 'USDT')) {
            return substr($symbol, 0, -5);
        }
        if (str_ends_with($symbol, 'PERP')) {
            return substr($symbol, 0, -4);
        }
        return $symbol;
    }

    private function getAuthHeaders(string $method, string $path, array $params, array $settings, string $body = ''): array
    {
        $apiKey = $settings['api_key'];
        $apiSecret = $settings['api_secret'];
        $timestamp = (time() * 1000);
        $recvWindow = 20000;

        // Bybit v5, signType = 2:
        // sign = HMAC_SHA256( timestamp + apiKey + recvWindow + queryString + body )
        $queryString = http_build_query($params);
        $signatureString = $timestamp . $apiKey . $recvWindow . $queryString . $body;
        $signature = hash_hmac('sha256', $signatureString, $apiSecret);

        return [
            'X-BAPI-API-KEY' => $apiKey,
            'X-BAPI-SIGN' => $signature,
            'X-BAPI-SIGN-TYPE' => '2',
            'X-BAPI-TIMESTAMP' => (string)$timestamp,
            'X-BAPI-RECV-WINDOW' => (string)$recvWindow,
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Информация об инструменте (min/max qty, шаг, допустимое плечо и т.п.), с простым кешированием.
     */
    private function getInstrumentInfo(string $symbol, array $settings): array
    {
        if (isset($this->instrumentCache[$symbol])) {
            return $this->instrumentCache[$symbol];
        }

        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        try {
            $response = $this->httpClient->request('GET', $baseUrl . '/v5/market/instruments-info', [
                'query' => [
                    'category' => 'linear',
                    'symbol' => $symbol,
                ],
            ]);
            $data = $response->toArray(false);
            if (
                isset($data['retCode'], $data['result']['list'][0])
                && $data['retCode'] === 0
            ) {
                $info = $data['result']['list'][0];
                $this->instrumentCache[$symbol] = $info;
                return $info;
            }
        } catch (\Exception $e) {
            $this->log('getInstrumentInfo Error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Открыть сделку: изолированная маржа, рыночный ордер.
     */
    public function placeOrder(string $symbol, string $side, float $positionSizeUSDT, int $leverage): array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены'];
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $trading = $this->settingsService->getTradingSettings();
            $minLeverageSetting = max(1, (int)($trading['min_leverage'] ?? 1));
            $maxLeverageSetting = max($minLeverageSetting, (int)($trading['max_leverage'] ?? 5));

            // Инструмент: чтобы учесть минимальный размер ордера, шаг и допустимое плечо
            $instrument = $this->getInstrumentInfo($symbol, $settings);
            $lotFilter = $instrument['lotSizeFilter'] ?? [];
            $leverageFilter = $instrument['leverageFilter'] ?? [];

            $minOrderQty = isset($lotFilter['minOrderQty']) ? (float)$lotFilter['minOrderQty'] : 0.0;
            $maxOrderQty = isset($lotFilter['maxOrderQty']) ? (float)$lotFilter['maxOrderQty'] : 0.0;
            $qtyStep = isset($lotFilter['qtyStep']) ? (float)$lotFilter['qtyStep'] : 0.0;

            $minLeverageSymbol = isset($leverageFilter['minLeverage']) ? (int)$leverageFilter['minLeverage'] : $minLeverageSetting;
            $maxLeverageSymbol = isset($leverageFilter['maxLeverage']) ? (int)$leverageFilter['maxLeverage'] : $maxLeverageSetting;

            // Плечо ограничиваем и настройками, и ограничениями инструмента
            $leverage = max(
                $minLeverageSetting,
                $minLeverageSymbol,
                min($maxLeverageSetting, $maxLeverageSymbol, $leverage)
            );

            $market = $this->getMarketData($symbol);
            $price = isset($market['lastPrice']) ? (float)$market['lastPrice'] : 0;
            if ($price <= 0) {
                return ['ok' => false, 'error' => 'Не удалось получить цену по символу'];
            }

            // Расчёт количества контрактов из суммы в USDT с учётом минимального размера и шага
            $rawQty = $positionSizeUSDT / $price;
            if ($qtyStep > 0) {
                // Округляем в меньшую сторону до допустимого шага
                $rawQty = floor($rawQty / $qtyStep) * $qtyStep;
            }
            $qty = round($rawQty, 8);

            if ($minOrderQty > 0 && $qty < $minOrderQty) {
                $minUsdt = $minOrderQty * $price;
                return [
                    'ok' => false,
                    'error' => sprintf('Минимальный объём для %s ≈ %.2f USDT', $symbol, $minUsdt),
                    'minPositionUSDT' => $minUsdt,
                ];
            }
            if ($maxOrderQty > 0 && $qty > $maxOrderQty) {
                return [
                    'ok' => false,
                    'error' => 'Размер позиции превышает максимальный допустимый для данного инструмента',
                ];
            }

            if ($qty <= 0) {
                return ['ok' => false, 'error' => 'Объём сделки слишком мал'];
            }

            $params = [];
            $bodySetLeverage = json_encode([
                'category' => 'linear',
                'symbol' => $symbol,
                'buyLeverage' => (string)$leverage,
                'sellLeverage' => (string)$leverage,
            ]);
            $headers = $this->getAuthHeaders('POST', '/v5/position/set-leverage', $params, $settings, $bodySetLeverage);
            $this->httpClient->request('POST', $baseUrl . '/v5/position/set-leverage', [
                'headers' => $headers,
                'body' => $bodySetLeverage,
            ]);

            $bodySwitch = json_encode([
                'category' => 'linear',
                'symbol' => $symbol,
                'tradeMode' => 1,
                'buyLeverage' => (string)$leverage,
                'sellLeverage' => (string)$leverage,
            ]);
            $headersSwitch = $this->getAuthHeaders('POST', '/v5/position/switch-isolated', $params, $settings, $bodySwitch);
            $this->httpClient->request('POST', $baseUrl . '/v5/position/switch-isolated', [
                'headers' => $headersSwitch,
                'body' => $bodySwitch,
            ]);

            $bodyOrder = json_encode([
                'category' => 'linear',
                'symbol' => $symbol,
                'side' => $side,
                'orderType' => 'Market',
                'qty' => (string)$qty,
                'positionIdx' => 0,
            ]);
            $headersOrder = $this->getAuthHeaders('POST', '/v5/order/create', $params, $settings, $bodyOrder);
            $response = $this->httpClient->request('POST', $baseUrl . '/v5/order/create', [
                'headers' => $headersOrder,
                'body' => $bodyOrder,
            ]);

            $data = $response->toArray(false);
            if (isset($data['retCode']) && $data['retCode'] === 0) {
                return ['ok' => true, 'result' => $data['result'] ?? []];
            }
            return ['ok' => false, 'error' => $data['retMsg'] ?? 'Unknown error', 'retCode' => $data['retCode'] ?? null];
        } catch (\Exception $e) {
            $this->log('placeOrder Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Рыночное закрытие позиции (полностью или частично) через reduce-only ордер.
     * $currentSide — сторона открытой позиции (Buy/ Sell), $fraction — доля объёма 0..1.
     */
    public function closePositionMarket(string $symbol, string $currentSide, float $fraction = 1.0): array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены'];
        }

        $positions = $this->getPositions();
        $position = null;
        foreach ($positions as $p) {
            if (($p['symbol'] ?? '') === $symbol && ($p['side'] ?? '') === $currentSide) {
                $position = $p;
                break;
            }
        }
        if ($position === null) {
            return ['ok' => false, 'error' => 'Позиция не найдена для закрытия'];
        }

        $size = (float)($position['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Размер позиции равен нулю'];
        }

        $fraction = max(0.05, min(1.0, $fraction));
        $qty = $size * $fraction;

        $instrument = $this->getInstrumentInfo($symbol, $settings);
        $lotFilter = $instrument['lotSizeFilter'] ?? [];
        $minOrderQty = isset($lotFilter['minOrderQty']) ? (float)$lotFilter['minOrderQty'] : 0.0;
        $qtyStep = isset($lotFilter['qtyStep']) ? (float)$lotFilter['qtyStep'] : 0.0;

        if ($qtyStep > 0) {
            $qty = floor($qty / $qtyStep) * $qtyStep;
        }
        $qty = round($qty, 8);
        if ($minOrderQty > 0 && $qty < $minOrderQty) {
            // Позиция слишком мала для частичного закрытия – тихо пропускаем без ошибки
            return ['ok' => true, 'skipped' => true, 'skipReason' => 'position_too_small_for_partial_close'];
        }
        if ($qty <= 0) {
            return ['ok' => true, 'skipped' => true, 'skipReason' => 'zero_quantity_partial_close'];
        }

        $orderSide = strtoupper($currentSide) === 'BUY' ? 'SELL' : 'BUY';

        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        $params = [];

        try {
            $bodyOrder = json_encode([
                'category' => 'linear',
                'symbol' => $symbol,
                'side' => $orderSide,
                'orderType' => 'Market',
                'qty' => (string)$qty,
                'reduceOnly' => true,
                'positionIdx' => 0,
            ]);
            $headersOrder = $this->getAuthHeaders('POST', '/v5/order/create', $params, $settings, $bodyOrder);
            $response = $this->httpClient->request('POST', $baseUrl . '/v5/order/create', [
                'headers' => $headersOrder,
                'body' => $bodyOrder,
            ]);

            $data = $response->toArray(false);
            if (isset($data['retCode']) && $data['retCode'] === 0) {
                return ['ok' => true, 'result' => $data['result'] ?? []];
            }
            return ['ok' => false, 'error' => $data['retMsg'] ?? 'Unknown error', 'retCode' => $data['retCode'] ?? null];
        } catch (\Exception $e) {
            $this->log('closePositionMarket Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Установить стоп-лосс в безубыток (на цену входа) через /v5/position/trading-stop.
     */
    public function setBreakevenStopLoss(string $symbol, string $currentSide, float $entryPrice): array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены'];
        }

        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        $params = [];
        $price = max(0.0001, $entryPrice);

        try {
            $body = json_encode([
                'category' => 'linear',
                'symbol' => $symbol,
                'positionIdx' => 0,
                'tpslMode' => 'Full',
                'stopLoss' => (string)$price,
                'slTriggerBy' => 'MarkPrice',
                'slOrderType' => 'Market',
            ]);
            $headers = $this->getAuthHeaders('POST', '/v5/position/trading-stop', $params, $settings, $body);
            $response = $this->httpClient->request('POST', $baseUrl . '/v5/position/trading-stop', [
                'headers' => $headers,
                'body' => $body,
            ]);

            $data = $response->toArray(false);
            if (isset($data['retCode']) && $data['retCode'] === 0) {
                return ['ok' => true, 'result' => $data['result'] ?? []];
            }
            return ['ok' => false, 'error' => $data['retMsg'] ?? 'Unknown error', 'retCode' => $data['retCode'] ?? null];
        } catch (\Exception $e) {
            $this->log('setBreakevenStopLoss Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function formatPositions(array $positions): array
    {
        return array_map(function($pos) {
            return [
                'symbol' => $pos['symbol'] ?? '',
                'side' => $pos['side'] ?? '',
                'size' => $pos['size'] ?? '0',
                'entryPrice' => $pos['avgPrice'] ?? '0',
                'markPrice' => $pos['markPrice'] ?? '0',
                'unrealizedPnl' => $pos['unrealisedPnl'] ?? '0',
                'stopLoss' => $pos['stopLoss'] ?? null,
                'takeProfit' => $pos['takeProfit'] ?? null,
                'liquidationPrice' => $pos['liqPrice'] ?? null,
                'openedAt' => isset($pos['createdTime']) ? date('Y-m-d H:i:s', intval($pos['createdTime']) / 1000) : date('Y-m-d H:i:s'),
                'leverage' => $pos['leverage'] ?? '1'
            ];
        }, array_filter($positions, fn($p) => floatval($p['size'] ?? 0) > 0));
    }

    private function formatTrades(array $trades): array
    {
        return array_map(function($trade) {
            return [
                'id' => $trade['execId'] ?? '',
                'symbol' => $trade['symbol'] ?? '',
                'side' => $trade['side'] ?? '',
                'price' => $trade['execPrice'] ?? '0',
                'quantity' => $trade['execQty'] ?? '0',
                'closedPnl' => $trade['closedPnl'] ?? null,
                'status' => $trade['execStatus'] ?? 'Unknown',
                'openedAt' => isset($trade['execTime']) ? date('Y-m-d H:i:s', intval($trade['execTime']) / 1000) : date('Y-m-d H:i:s'),
                'orderType' => $trade['orderType'] ?? ''
            ];
        }, $trades);
    }

    private function formatOrders(array $orders): array
    {
        return array_map(function($order) {
            return [
                'orderId' => $order['orderId'] ?? '',
                'orderLinkId' => $order['orderLinkId'] ?? '',
                'symbol' => $order['symbol'] ?? '',
                'side' => $order['side'] ?? '',
                'orderType' => $order['orderType'] ?? '',
                'price' => $order['price'] ?? '0',
                'triggerPrice' => $order['triggerPrice'] ?? null,
                'qty' => $order['qty'] ?? '0',
                'leavesQty' => $order['leavesQty'] ?? '0',
                'cumExecQty' => $order['cumExecQty'] ?? '0',
                'cumExecValue' => $order['cumExecValue'] ?? '0',
                'status' => $order['orderStatus'] ?? 'Unknown',
                'timeInForce' => $order['timeInForce'] ?? 'GTC',
                'createdTime' => isset($order['createdTime']) ? date('Y-m-d H:i:s', intval($order['createdTime']) / 1000) : date('Y-m-d H:i:s'),
                'updatedTime' => isset($order['updatedTime']) ? date('Y-m-d H:i:s', intval($order['updatedTime']) / 1000) : date('Y-m-d H:i:s')
            ];
        }, $orders);
    }

    private function getMockPositions(): array
    {
        return [
            [
                'symbol' => 'BTCUSDT',
                'side' => 'Buy',
                'size' => '0.1',
                'entryPrice' => '45000.00',
                'markPrice' => '45200.00',
                'unrealizedPnl' => '20.00',
                'openedAt' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'leverage' => '10'
            ]
        ];
    }

    private function getMockTrades(int $limit): array
    {
        $trades = [];
        for ($i = 0; $i < min($limit, 50); $i++) {
            $profit = rand(-100, 200) / 1;
            $trades[] = [
                'id' => 'mock_' . ($i + 1),
                'symbol' => ['BTCUSDT', 'ETHUSDT', 'BNBUSDT'][rand(0, 2)],
                'side' => ['Buy', 'Sell'][rand(0, 1)],
                'price' => (string)(rand(40000, 50000) / 1),
                'quantity' => (string)(rand(1, 100) / 100),
                'closedPnl' => (string)$profit,
                'status' => 'Filled',
                'openedAt' => date('Y-m-d H:i:s', strtotime("-{$i} hours")),
                'orderType' => 'Market'
            ];
        }
        return $trades;
    }

    private function getMockMarketData(string $symbol): array
    {
        return [
            'symbol' => $symbol,
            'lastPrice' => (string)(rand(40000, 50000) / 1),
            'price24hPcnt' => (string)(rand(-500, 500) / 100),
            'volume24h' => (string)(rand(1000000, 5000000)),
            'turnover24h' => (string)(rand(50000000, 200000000))
        ];
    }

    private function getMockStatistics(): array
    {
        return [
            'totalTrades' => 150,
            'winRate' => 65.5,
            'totalProfit' => 1250.50,
            'averageProfit' => 8.34,
            'maxDrawdown' => -150.00,
            'profitFactor' => 1.85,
            'winningTrades' => 98,
            'losingTrades' => 52
        ];
    }

    /**
     * Диагностика подключения к Bybit: проверяем, что ключи рабочие и подпись корректна.
     */
    public function testConnection(): array
    {
        $settings = $this->settingsService->getBybitSettings();

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return [
                'ok' => false,
                'reason' => 'API ключи Bybit не заполнены в настройках',
            ];
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params = [
                'category' => 'linear',
                'settleCoin' => 'USDT',
                'limit' => 1,
            ];

            $response = $this->httpClient->request('GET', $baseUrl . '/v5/position/list', [
                'query' => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings),
            ]);

            $statusCode = $response->getStatusCode();
            $raw = $response->getContent(false);
            $data = $raw !== '' ? json_decode($raw, true) : null;

            if ($statusCode === 200 && isset($data['retCode']) && $data['retCode'] === 0) {
                return [
                    'ok' => true,
                    'message' => 'Подключение к Bybit успешно. Ключи и подпись работают.',
                ];
            }

            return [
                'ok' => false,
                'statusCode' => $statusCode,
                'retCode' => $data['retCode'] ?? null,
                'retMsg' => $data['retMsg'] ?? ($raw ?: null),
            ];
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}


