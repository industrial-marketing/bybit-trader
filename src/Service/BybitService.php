<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Bybit API v5 client with:
 *  - Retry with exponential backoff (network errors, HTTP 5xx, retCode 10006 rate-limit)
 *  - Server time synchronization (5-min TTL cache → var/bybit_time_offset.json)
 *  - Instrument info disk cache (1h TTL → var/instrument_cache.json), auto-invalidated on qty errors
 *  - Pre-order qty/param logging (no secrets)
 *  - Canonical symbol normalisation (*PERP → underlying *USDT for kline/market data)
 */
class BybitService
{
    // retCodes that indicate qty/instrument validation failure → invalidate instrument cache
    private const QTY_ERROR_CODES = [110017, 110009, 170036, 170037, 110043];
    // retCode for rate limit exceeded
    private const RATE_LIMIT_CODE = 10006;

    private array $instrumentMemCache = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService    $settingsService
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // Logging
    // ═══════════════════════════════════════════════════════════════

    private function log(string $message): void
    {
        LogSanitizer::log('Bybit', $message, $this->settingsService);
    }

    // ═══════════════════════════════════════════════════════════════
    // HTTP layer: retry + backoff + rate-limit
    // ═══════════════════════════════════════════════════════════════

    /**
     * Executes an HTTP request with retry logic:
     *  - Retries up to $maxAttempts on network exceptions
     *  - Retries on HTTP 5xx (exponential backoff)
     *  - On HTTP 429 or Bybit retCode 10006: waits Retry-After (or 2s) then retries
     */
    private function requestWithRetry(
        string $method,
        string $url,
        array  $options      = [],
        int    $maxAttempts  = 3
    ): ResponseInterface {
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                $response   = $this->httpClient->request($method, $url, $options);
                $statusCode = $response->getStatusCode();

                if ($statusCode === 429) {
                    // Rate limited at HTTP level
                    $headers    = $response->getHeaders(false);
                    $retryAfter = (int)($headers['retry-after'][0] ?? 2);
                    $retryAfter = max(1, min($retryAfter, 30));
                    $this->log("HTTP 429 rate-limit on {$url}, waiting {$retryAfter}s (attempt {$attempt})");
                    sleep($retryAfter);
                    if ($attempt >= $maxAttempts) {
                        return $response;
                    }
                    continue;
                }

                if ($statusCode >= 500) {
                    if ($attempt >= $maxAttempts) {
                        return $response;
                    }
                    $waitUs = min(1_000_000 * (2 ** ($attempt - 1)), 8_000_000);
                    $this->log("HTTP {$statusCode} on {$url}, retry #{$attempt} in " . ($waitUs / 1_000_000) . 's');
                    usleep($waitUs);
                    continue;
                }

                // Check Bybit retCode for rate limit
                try {
                    $body = $response->toArray(false);
                    if (($body['retCode'] ?? null) === self::RATE_LIMIT_CODE) {
                        $waitUs = min(1_000_000 * (2 ** ($attempt - 1)), 8_000_000);
                        $this->log("Bybit retCode 10006 (rate-limit) on {$url}, retry #{$attempt}");
                        usleep($waitUs);
                        if ($attempt >= $maxAttempts) {
                            return $response;
                        }
                        continue;
                    }
                } catch (\Exception) {
                    // Can't decode body; don't retry on this
                }

                return $response;

            } catch (\Exception $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $waitUs = min(1_000_000 * (2 ** ($attempt - 1)), 8_000_000);
                $this->log("Network error on {$url} (attempt {$attempt}): " . $e->getMessage() . ", retrying in " . ($waitUs / 1_000_000) . 's');
                usleep($waitUs);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Time synchronisation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Returns clock offset (ms) = serverTime - localTime.
     * Cached in var/bybit_time_offset.json, TTL 5 min.
     * Falls back to 0 on error.
     */
    private function getServerTimeOffset(): int
    {
        $cacheFile = __DIR__ . '/../../var/bybit_time_offset.json';
        $ttlSec    = 300; // 5 minutes

        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true) ?? [];
            if (isset($cached['ts'], $cached['offset']) && (time() - (int)$cached['ts']) < $ttlSec) {
                return (int)$cached['offset'];
            }
        }

        try {
            $settings = $this->settingsService->getBybitSettings();
            $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

            $localBefore = (int)(microtime(true) * 1000);
            // Use direct httpClient here to avoid recursion in requestWithRetry
            $response    = $this->httpClient->request('GET', $baseUrl . '/v5/market/time');
            $localAfter  = (int)(microtime(true) * 1000);

            $data = $response->toArray(false);
            if (isset($data['result']['timeSecond'])) {
                $serverMs = (int)$data['result']['timeSecond'] * 1000;
                $localMs  = (int)(($localBefore + $localAfter) / 2);
                $offset   = $serverMs - $localMs;

                $dir = dirname($cacheFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($cacheFile, json_encode(['ts' => time(), 'offset' => $offset]));

                if (abs($offset) > 3000) {
                    $this->log(sprintf('Time offset with Bybit server: %+d ms (|offset|>3s)', $offset));
                }

                return $offset;
            }
        } catch (\Exception $e) {
            $this->log('Time sync error: ' . $e->getMessage());
        }

        return 0;
    }

    /** Invalidates the cached time offset so the next call re-syncs. */
    private function invalidateTimeOffset(): void
    {
        $cacheFile = __DIR__ . '/../../var/bybit_time_offset.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Auth headers
    // ═══════════════════════════════════════════════════════════════

    private function getAuthHeaders(
        string $method,
        string $path,
        array  $params,
        array  $settings,
        string $body = ''
    ): array {
        $apiKey    = $settings['api_key'];
        $apiSecret = $settings['api_secret'];

        // Use synced timestamp: local time + server offset
        $offset    = $this->getServerTimeOffset();
        $timestamp = (int)(microtime(true) * 1000) + $offset;
        $recvWindow = 20000;

        $queryString      = http_build_query($params);
        $signatureString  = $timestamp . $apiKey . $recvWindow . $queryString . $body;
        $signature        = hash_hmac('sha256', $signatureString, $apiSecret);

        return [
            'X-BAPI-API-KEY'     => $apiKey,
            'X-BAPI-SIGN'        => $signature,
            'X-BAPI-SIGN-TYPE'   => '2',
            'X-BAPI-TIMESTAMP'   => (string)$timestamp,
            'X-BAPI-RECV-WINDOW' => (string)$recvWindow,
            'Content-Type'       => 'application/json',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Symbol helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Canonical symbol: for kline/market-data we prefer the *USDT version
     * because it's guaranteed to exist on both testnet and mainnet.
     * BTCPERP → BTCUSDT, ETHPERP → ETHUSDT, etc.
     * Already-USDT symbols are returned unchanged.
     * Symbols with expiry dates (MNTUSDT-13MAR26) are filtered elsewhere.
     */
    private function toCanonicalSymbol(string $symbol): string
    {
        if (str_ends_with($symbol, 'PERP')) {
            $base = substr($symbol, 0, -4);
            return $base . 'USDT';
        }
        return $symbol;
    }

    /** Filters out dated contracts like MNTUSDT-13MAR26 */
    private function isDatedContract(string $symbol): bool
    {
        return (bool)preg_match('/-\d{2}[A-Z]{3}\d{2}$/', $symbol);
    }

    /** Base asset for deduplication: BTCUSDT/BTCPERP → BTC */
    private function getBaseAsset(string $symbol): string
    {
        if ($symbol === '') {
            return '';
        }
        if (str_ends_with($symbol, 'USDT')) {
            return substr($symbol, 0, -4);
        }
        if (str_ends_with($symbol, 'PERP')) {
            return substr($symbol, 0, -4);
        }
        return $symbol;
    }

    // ═══════════════════════════════════════════════════════════════
    // Instrument cache (disk, 1h TTL)
    // ═══════════════════════════════════════════════════════════════

    private function instrumentCacheFile(): string
    {
        return __DIR__ . '/../../var/instrument_cache.json';
    }

    private function loadInstrumentDiskCache(): array
    {
        $file = $this->instrumentCacheFile();
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?? [];
    }

    private function saveInstrumentDiskCache(array $cache): void
    {
        $file = $this->instrumentCacheFile();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode($cache, JSON_PRETTY_PRINT));
    }

    private function getInstrumentInfo(string $symbol, array $settings, bool $forceRefresh = false): array
    {
        $ttl = 3600; // 1 hour

        // 1. In-memory cache (per request)
        if (!$forceRefresh && isset($this->instrumentMemCache[$symbol])) {
            return $this->instrumentMemCache[$symbol];
        }

        // 2. Disk cache
        if (!$forceRefresh) {
            $diskCache = $this->loadInstrumentDiskCache();
            $entry     = $diskCache[$symbol] ?? null;
            if ($entry && isset($entry['ts'], $entry['data']) && (time() - (int)$entry['ts']) < $ttl) {
                $this->instrumentMemCache[$symbol] = $entry['data'];
                return $entry['data'];
            }
        }

        // 3. Fetch from Bybit
        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        try {
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/market/instruments-info', [
                'query' => ['category' => 'linear', 'symbol' => $symbol],
            ], 2);
            $data = $response->toArray(false);
            if (isset($data['retCode'], $data['result']['list'][0]) && $data['retCode'] === 0) {
                $info = $data['result']['list'][0];

                // Persist to disk cache
                $diskCache           = $this->loadInstrumentDiskCache();
                $diskCache[$symbol]  = ['ts' => time(), 'data' => $info];
                $this->saveInstrumentDiskCache($diskCache);
                $this->instrumentMemCache[$symbol] = $info;

                return $info;
            }
            $this->log("getInstrumentInfo({$symbol}): retCode=" . ($data['retCode'] ?? '?') . ' msg=' . ($data['retMsg'] ?? '?'));
        } catch (\Exception $e) {
            $this->log('getInstrumentInfo Error: ' . $e->getMessage());
        }

        return [];
    }

    private function invalidateInstrumentCache(string $symbol): void
    {
        unset($this->instrumentMemCache[$symbol]);
        $diskCache = $this->loadInstrumentDiskCache();
        if (isset($diskCache[$symbol])) {
            unset($diskCache[$symbol]);
            $this->saveInstrumentDiskCache($diskCache);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Public API: positions / orders / trades / balance / market
    // ═══════════════════════════════════════════════════════════════

    public function getPositions(): array
    {
        $settings = $this->settingsService->getBybitSettings();

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return $this->getMockPositions();
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params  = ['category' => 'linear', 'settleCoin' => 'USDT'];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/position/list', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings),
            ]);

            $data = $response->toArray(false);
            if (isset($data['retCode']) && $data['retCode'] === 0 && isset($data['result']['list'])) {
                return $this->formatPositions($data['result']['list']);
            }

            // Timestamp error → re-sync and do one more try
            if (in_array($data['retCode'] ?? -1, [10002], true)) {
                $this->invalidateTimeOffset();
                $response2 = $this->requestWithRetry('GET', $baseUrl . '/v5/position/list', [
                    'query'   => $params,
                    'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings),
                ], 1);
                $data2 = $response2->toArray(false);
                if (($data2['retCode'] ?? -1) === 0 && isset($data2['result']['list'])) {
                    return $this->formatPositions($data2['result']['list']);
                }
            }

            return [];
        } catch (\Exception $e) {
            $this->log('getPositions Error: ' . $e->getMessage());
            return [];
        }
    }

    public function getTrades(int $limit = 100): array
    {
        $settings = $this->settingsService->getBybitSettings();

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return $this->getMockTrades($limit);
        }

        try {
            $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params   = ['category' => 'linear', 'settleCoin' => 'USDT', 'limit' => $limit];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/execution/list', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/execution/list', $params, $settings),
            ]);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0 && isset($data['result']['list'])) {
                return $this->formatTrades($data['result']['list']);
            }
            return [];
        } catch (\Exception $e) {
            $this->log('getTrades Error: ' . $e->getMessage());
            return [];
        }
    }

    public function getMarketData(string $symbol = 'BTCUSDT'): array
    {
        $settings = $this->settingsService->getBybitSettings();
        $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        // Use canonical USDT symbol for market data
        $querySymbol = $this->toCanonicalSymbol($symbol);

        try {
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/market/tickers', [
                'query' => ['category' => 'linear', 'symbol' => $querySymbol],
            ]);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0 && isset($data['result']['list'][0])) {
                $item = $data['result']['list'][0];
                // Inject the original symbol so callers can reference it
                $item['_originalSymbol'] = $symbol;
                return $item;
            }
            return [];
        } catch (\Exception $e) {
            $this->log('getMarketData Error: ' . $e->getMessage());
            return [];
        }
    }

    public function getBalance(): array
    {
        $settings = $this->settingsService->getBybitSettings();
        $empty    = ['totalEquity' => 0.0, 'walletBalance' => 0.0, 'availableBalance' => 0.0, 'unrealisedPnl' => 0.0];

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return $empty;
        }

        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        try {
            $params   = ['accountType' => 'UNIFIED', 'coin' => 'USDT'];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/account/wallet-balance', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/account/wallet-balance', $params, $settings),
            ]);
            $data = $response->toArray(false);

            if (($data['retCode'] ?? -1) !== 0 || empty($data['result']['list'][0])) {
                return $empty;
            }

            $account       = $data['result']['list'][0];
            $totalEquity   = (float)($account['totalEquity']           ?? 0);
            $totalAvailable= (float)($account['totalAvailableBalance'] ?? 0);
            $usdtWallet    = 0.0;
            $unrealisedPnl = 0.0;

            foreach ((array)($account['coin'] ?? []) as $coin) {
                if (($coin['coin'] ?? '') === 'USDT') {
                    $usdtWallet    = (float)($coin['walletBalance'] ?? 0);
                    $unrealisedPnl = (float)($coin['unrealisedPnl'] ?? 0);
                    break;
                }
            }

            return [
                'totalEquity'      => $totalEquity,
                'walletBalance'    => $totalEquity,
                'availableBalance' => $totalAvailable,
                'unrealisedPnl'    => $unrealisedPnl,
                'usdtWallet'       => $usdtWallet,
            ];
        } catch (\Exception $e) {
            $this->log('getBalance Error: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Top markets by 24h turnover.
     * Deduplicates *USDT vs *PERP per base asset (prefers *PERP for testnet accuracy).
     * Filters dated contracts (MNTUSDT-13MAR26).
     */
    public function getTopMarkets(int $limit = 100, string $category = 'linear'): array
    {
        $settings = $this->settingsService->getBybitSettings();
        $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        try {
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/market/tickers', [
                'query' => ['category' => $category],
            ]);
            $data = $response->toArray(false);

            if (($data['retCode'] ?? -1) !== 0 || empty($data['result']['list'])) {
                return [];
            }

            $list = $data['result']['list'];

            // Filter dated contracts
            $list = array_values(array_filter($list, fn($item) => !$this->isDatedContract($item['symbol'] ?? '')));

            // Sort by turnover
            usort($list, fn($a, $b) => (float)($b['turnover24h'] ?? 0) <=> (float)($a['turnover24h'] ?? 0));

            // Deduplicate by base asset (prefer *PERP over *USDT on testnet)
            $byBase = [];
            foreach ($list as $item) {
                $sym  = $item['symbol'] ?? '';
                $base = $this->getBaseAsset($sym);
                if ($base === '') {
                    $byBase[$sym] = $item;
                    continue;
                }
                $existing = $byBase[$base] ?? null;
                if ($existing === null) {
                    $byBase[$base] = $item;
                    continue;
                }
                $existingIsPerp = str_ends_with($existing['symbol'] ?? '', 'PERP');
                $thisIsPerp     = str_ends_with($sym, 'PERP');
                if ($thisIsPerp && !$existingIsPerp) {
                    $byBase[$base] = $item;
                } elseif (!$thisIsPerp && !$existingIsPerp) {
                    // Both USDT: keep higher turnover
                    if ((float)($item['turnover24h'] ?? 0) > (float)($existing['turnover24h'] ?? 0)) {
                        $byBase[$base] = $item;
                    }
                }
                // existing is PERP and this is USDT → keep existing PERP
            }

            $list = array_values($byBase);
            usort($list, fn($a, $b) => (float)($b['turnover24h'] ?? 0) <=> (float)($a['turnover24h'] ?? 0));
            $list = array_slice($list, 0, $limit);

            return array_map(fn(array $item): array => [
                'symbol'       => $item['symbol'] ?? '',
                'lastPrice'    => (float)($item['lastPrice']    ?? 0),
                'price24hPcnt' => (float)($item['price24hPcnt'] ?? 0) * 100,
                'highPrice24h' => isset($item['highPrice24h'])  ? (float)$item['highPrice24h'] : null,
                'lowPrice24h'  => isset($item['lowPrice24h'])   ? (float)$item['lowPrice24h']  : null,
                'volume24h'    => (float)($item['volume24h']    ?? 0),
                'turnover24h'  => (float)($item['turnover24h']  ?? 0),
            ], $list);
        } catch (\Exception $e) {
            $this->log('getTopMarkets Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch kline (OHLCV) history from Bybit and format as compact LLM string.
     * Uses canonical *USDT symbol for the API call.
     */
    public function getKlineHistory(
        string $symbol,
        int    $intervalMinutes,
        int    $limit         = 60,
        int    $maxPricePoints = 30
    ): string {
        $settings    = $this->settingsService->getBybitSettings();
        $baseUrl     = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        // Always fetch kline with canonical *USDT symbol
        $klineSymbol = $this->toCanonicalSymbol($symbol);

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
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/market/kline', [
                'query' => [
                    'category' => 'linear',
                    'symbol'   => $klineSymbol,
                    'interval' => $interval,
                    'limit'    => min($limit, 200),
                ],
            ]);
            $data = $response->toArray(false);

            if (($data['retCode'] ?? -1) !== 0 || empty($data['result']['list'])) {
                $err = $data['retMsg'] ?? 'no data';
                return "[kline error for {$klineSymbol}: {$err}]";
            }

            $candles = array_reverse($data['result']['list']);
            $closes  = array_map(fn($c) => (float)($c[4] ?? 0), $candles);
            $highs   = array_map(fn($c) => (float)($c[2] ?? 0), $candles);
            $lows    = array_map(fn($c) => (float)($c[3] ?? 0), $candles);

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

            $header = sprintf(
                '[%d %s candles | open=%s close=%s min=%s max=%s trend=%s]',
                $count, $tfLabel, $first, $last, $minP, $maxP, $trend
            );

            $recentCloses = array_slice($closes, -$maxPricePoints);
            $pricesStr    = implode(',', array_map(fn($p) => round($p, 6), $recentCloses));

            return $header . ' closes:' . $pricesStr;
        } catch (\Exception $e) {
            $this->log("getKlineHistory({$klineSymbol},{$interval}) error: " . $e->getMessage());
            return "[kline unavailable for {$klineSymbol}]";
        }
    }

    public function getOpenOrders(string $symbol = ''): array
    {
        $settings = $this->settingsService->getBybitSettings();

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return [];
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params  = ['category' => 'linear', 'settleCoin' => 'USDT'];
            if ($symbol !== '') {
                $params['symbol'] = $symbol;
            }

            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/order/realtime', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/order/realtime', $params, $settings),
            ]);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0 && isset($data['result']['list'])) {
                return $this->formatOrders($data['result']['list']);
            }
            return [];
        } catch (\Exception $e) {
            $this->log('getOpenOrders Error: ' . $e->getMessage());
            return [];
        }
    }

    public function getClosedTrades(int $limit = 100): array
    {
        $settings = $this->settingsService->getBybitSettings();

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return $this->getMockTrades($limit);
        }

        try {
            $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params   = ['category' => 'linear', 'settleCoin' => 'USDT', 'limit' => $limit];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/execution/list', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/execution/list', $params, $settings),
            ]);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0 && isset($data['result']['list'])) {
                $closed = array_filter(
                    $data['result']['list'],
                    fn($t) => isset($t['closedPnl']) && $t['closedPnl'] !== null && $t['closedPnl'] !== ''
                );
                return $this->formatTrades(array_values($closed));
            }
            return [];
        } catch (\Exception $e) {
            $this->log('getClosedTrades Error: ' . $e->getMessage());
            return [];
        }
    }

    public function getStatistics(): array
    {
        $trades = $this->getClosedTrades(1000);
        if (empty($trades)) {
            $trades = $this->getTrades(1000);
        }

        if (empty($trades)) {
            return [
                'totalTrades' => 0, 'winRate' => 0.0, 'totalProfit' => 0.0,
                'averageProfit' => 0.0, 'maxDrawdown' => 0.0, 'profitFactor' => 0.0,
                'winningTrades' => 0, 'losingTrades' => 0,
            ];
        }

        $closedTrades  = array_filter($trades, fn($t) => isset($t['closedPnl']) && $t['closedPnl'] !== null);
        $totalTrades   = count($closedTrades);
        $winningTrades = array_filter($closedTrades, fn($t) => (float)($t['closedPnl'] ?? 0) > 0);
        $losingTrades  = array_filter($closedTrades, fn($t) => (float)($t['closedPnl'] ?? 0) < 0);

        $totalProfit = array_sum(array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $closedTrades));
        $winRate     = $totalTrades > 0 ? (count($winningTrades) / $totalTrades) * 100 : 0;
        $avgProfit   = $totalTrades > 0 ? $totalProfit / $totalTrades : 0;
        $profits     = array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $closedTrades);
        $maxDrawdown = $profits ? min($profits) : 0;
        $winSum      = array_sum(array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $winningTrades));
        $loseSum     = abs(array_sum(array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $losingTrades)));
        $profitFactor= $loseSum > 0 ? $winSum / $loseSum : ($winSum > 0 ? 999 : 0);

        return [
            'totalTrades'    => $totalTrades,
            'winRate'        => round($winRate, 2),
            'totalProfit'    => round($totalProfit, 2),
            'averageProfit'  => round($avgProfit, 2),
            'maxDrawdown'    => round($maxDrawdown, 2),
            'profitFactor'   => round($profitFactor, 2),
            'winningTrades'  => count($winningTrades),
            'losingTrades'   => count($losingTrades),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Trading: open / close / stop-loss
    // ═══════════════════════════════════════════════════════════════

    /**
     * Open a market order with isolated margin.
     * Validates qty against instrumentInfo; auto-refreshes instrument cache on qty errors.
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

            $minLevSetting = max(1, (int)($trading['min_leverage'] ?? 1));
            $maxLevSetting = max($minLevSetting, (int)($trading['max_leverage'] ?? 5));

            $instrument    = $this->getInstrumentInfo($symbol, $settings);
            $lotFilter     = $instrument['lotSizeFilter'] ?? [];
            $leverageFilter= $instrument['leverageFilter'] ?? [];

            $minOrderQty   = isset($lotFilter['minOrderQty'])  ? (float)$lotFilter['minOrderQty']  : 0.0;
            $maxOrderQty   = isset($lotFilter['maxOrderQty'])  ? (float)$lotFilter['maxOrderQty']  : 0.0;
            $qtyStep       = isset($lotFilter['qtyStep'])      ? (float)$lotFilter['qtyStep']      : 0.0;

            $minLevSymbol  = isset($leverageFilter['minLeverage']) ? (int)$leverageFilter['minLeverage'] : $minLevSetting;
            $maxLevSymbol  = isset($leverageFilter['maxLeverage']) ? (int)$leverageFilter['maxLeverage'] : $maxLevSetting;

            $leverage = max($minLevSetting, $minLevSymbol, min($maxLevSetting, $maxLevSymbol, $leverage));

            // Price for qty calculation
            $market = $this->getMarketData($symbol);
            $price  = isset($market['lastPrice']) ? (float)$market['lastPrice'] : 0.0;
            if ($price <= 0) {
                return ['ok' => false, 'error' => 'Не удалось получить текущую цену для ' . $symbol];
            }

            $rawQty = $positionSizeUSDT / $price;
            if ($qtyStep > 0) {
                $rawQty = floor($rawQty / $qtyStep) * $qtyStep;
                $rawQty = round($rawQty, 8);
            }
            $qty = $rawQty;

            // ── Pre-order diagnostic log (no secrets) ────────────────
            $this->log(sprintf(
                'placeOrder → symbol=%s side=%s requestedUSDT=%.4f price=%.8f rawQty=%.8f qty=%.8f leverage=%dx | ' .
                'instrument: minQty=%s maxQty=%s step=%s levRange=%d–%d',
                $symbol, $side, $positionSizeUSDT, $price, $rawQty, $qty, $leverage,
                $lotFilter['minOrderQty'] ?? '?',
                $lotFilter['maxOrderQty'] ?? '?',
                $lotFilter['qtyStep']     ?? '?',
                $minLevSymbol, $maxLevSymbol
            ));

            if ($minOrderQty > 0 && $qty < $minOrderQty) {
                return [
                    'ok'               => false,
                    'error'            => sprintf('Минимальный объём для %s ≈ %.2f USDT (minQty=%.8f, step=%.8f)', $symbol, $minOrderQty * $price, $minOrderQty, $qtyStep),
                    'minPositionUSDT'  => $minOrderQty * $price,
                ];
            }
            if ($maxOrderQty > 0 && $qty > $maxOrderQty) {
                return ['ok' => false, 'error' => sprintf('Объём %.8f превышает maxQty=%.8f для %s', $qty, $maxOrderQty, $symbol)];
            }
            if ($qty <= 0) {
                return ['ok' => false, 'error' => 'Объём сделки слишком мал (qty=0)'];
            }

            // Set leverage
            $emptyParams      = [];
            $bodySetLeverage  = json_encode(['category' => 'linear', 'symbol' => $symbol, 'buyLeverage' => (string)$leverage, 'sellLeverage' => (string)$leverage]);
            $this->requestWithRetry('POST', $baseUrl . '/v5/position/set-leverage', [
                'headers' => $this->getAuthHeaders('POST', '/v5/position/set-leverage', $emptyParams, $settings, $bodySetLeverage),
                'body'    => $bodySetLeverage,
            ]);

            // Switch to isolated
            $bodySwitch = json_encode(['category' => 'linear', 'symbol' => $symbol, 'tradeMode' => 1, 'buyLeverage' => (string)$leverage, 'sellLeverage' => (string)$leverage]);
            $this->requestWithRetry('POST', $baseUrl . '/v5/position/switch-isolated', [
                'headers' => $this->getAuthHeaders('POST', '/v5/position/switch-isolated', $emptyParams, $settings, $bodySwitch),
                'body'    => $bodySwitch,
            ]);

            // Place market order
            $bodyOrder = json_encode(['category' => 'linear', 'symbol' => $symbol, 'side' => $side, 'orderType' => 'Market', 'qty' => (string)$qty, 'positionIdx' => 0]);
            $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                'body'    => $bodyOrder,
            ]);

            $data    = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;

            // Auto-invalidate instrument cache on qty-related errors
            if (in_array($retCode, self::QTY_ERROR_CODES, true)) {
                $this->log("placeOrder qty error retCode={$retCode}, invalidating instrument cache for {$symbol}");
                $this->invalidateInstrumentCache($symbol);
            }

            if ($retCode === 0) {
                return ['ok' => true, 'result' => $data['result'] ?? []];
            }
            return ['ok' => false, 'error' => $data['retMsg'] ?? 'Unknown error', 'retCode' => $retCode];
        } catch (\Exception $e) {
            $this->log('placeOrder Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Close position (market, reduce-only). $fraction ∈ (0, 1].
     */
    public function closePositionMarket(string $symbol, string $currentSide, float $fraction = 1.0): array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены'];
        }

        $positions = $this->getPositions();
        $position  = null;
        foreach ($positions as $p) {
            if (($p['symbol'] ?? '') === $symbol && ($p['side'] ?? '') === $currentSide) {
                $position = $p;
                break;
            }
        }
        if ($position === null) {
            return ['ok' => false, 'error' => 'Позиция не найдена'];
        }

        $size     = (float)($position['size'] ?? 0);
        $fraction = max(0.05, min(1.0, $fraction));
        $qty      = $size * $fraction;

        $instrument  = $this->getInstrumentInfo($symbol, $settings);
        $lotFilter   = $instrument['lotSizeFilter'] ?? [];
        $minOrderQty = isset($lotFilter['minOrderQty']) ? (float)$lotFilter['minOrderQty'] : 0.0;
        $qtyStep     = isset($lotFilter['qtyStep'])     ? (float)$lotFilter['qtyStep']     : 0.0;

        if ($qtyStep > 0) {
            $qty = floor($qty / $qtyStep) * $qtyStep;
        }
        $qty = round($qty, 8);

        if ($minOrderQty > 0 && $qty < $minOrderQty) {
            return ['ok' => true, 'skipped' => true, 'skipReason' => 'position_too_small_for_partial_close'];
        }
        if ($qty <= 0) {
            return ['ok' => true, 'skipped' => true, 'skipReason' => 'zero_quantity'];
        }

        $orderSide = strtoupper($currentSide) === 'BUY' ? 'SELL' : 'BUY';
        $baseUrl   = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        $emptyParams = [];

        try {
            $bodyOrder = json_encode(['category' => 'linear', 'symbol' => $symbol, 'side' => $orderSide, 'orderType' => 'Market', 'qty' => (string)$qty, 'reduceOnly' => true, 'positionIdx' => 0]);
            $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                'body'    => $bodyOrder,
            ]);
            $data    = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;

            if (in_array($retCode, self::QTY_ERROR_CODES, true)) {
                $this->invalidateInstrumentCache($symbol);
            }

            if ($retCode === 0) {
                return ['ok' => true, 'result' => $data['result'] ?? []];
            }
            return ['ok' => false, 'error' => $data['retMsg'] ?? 'Unknown error', 'retCode' => $retCode];
        } catch (\Exception $e) {
            $this->log('closePositionMarket Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Set stop-loss at breakeven (entry price) via /v5/position/trading-stop.
     */
    public function setBreakevenStopLoss(string $symbol, string $currentSide, float $entryPrice): array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены'];
        }

        $baseUrl     = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        $price       = max(0.0001, $entryPrice);
        $emptyParams = [];

        try {
            $body = json_encode(['category' => 'linear', 'symbol' => $symbol, 'positionIdx' => 0, 'tpslMode' => 'Full', 'stopLoss' => (string)$price, 'slTriggerBy' => 'MarkPrice', 'slOrderType' => 'Market']);
            $response = $this->requestWithRetry('POST', $baseUrl . '/v5/position/trading-stop', [
                'headers' => $this->getAuthHeaders('POST', '/v5/position/trading-stop', $emptyParams, $settings, $body),
                'body'    => $body,
            ]);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0) {
                return ['ok' => true, 'result' => $data['result'] ?? []];
            }
            return ['ok' => false, 'error' => $data['retMsg'] ?? 'Unknown error', 'retCode' => $data['retCode'] ?? null];
        } catch (\Exception $e) {
            $this->log('setBreakevenStopLoss Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Connection test
    // ═══════════════════════════════════════════════════════════════

    public function testConnection(): array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'reason' => 'API ключи не заполнены'];
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params  = ['category' => 'linear', 'settleCoin' => 'USDT', 'limit' => 1];

            $response   = $this->requestWithRetry('GET', $baseUrl . '/v5/position/list', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings),
            ], 2);
            $statusCode = $response->getStatusCode();
            $raw        = $response->getContent(false);
            $data       = $raw !== '' ? json_decode($raw, true) : null;

            if ($statusCode === 200 && ($data['retCode'] ?? -1) === 0) {
                $offset = $this->getServerTimeOffset();
                return [
                    'ok'          => true,
                    'message'     => sprintf('Bybit подключён. Сдвиг часов: %+d мс.', $offset),
                    'timeOffset'  => $offset,
                ];
            }
            return ['ok' => false, 'statusCode' => $statusCode, 'retCode' => $data['retCode'] ?? null, 'retMsg' => $data['retMsg'] ?? $raw];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Format helpers
    // ═══════════════════════════════════════════════════════════════

    private function formatPositions(array $positions): array
    {
        return array_values(array_map(function ($pos): array {
            return [
                'symbol'           => $pos['symbol']        ?? '',
                'side'             => $pos['side']          ?? '',
                'size'             => $pos['size']          ?? '0',
                'entryPrice'       => $pos['avgPrice']      ?? '0',
                'markPrice'        => $pos['markPrice']     ?? '0',
                'unrealizedPnl'    => $pos['unrealisedPnl'] ?? '0',
                'stopLoss'         => $pos['stopLoss']      ?? null,
                'takeProfit'       => $pos['takeProfit']    ?? null,
                'liquidationPrice' => $pos['liqPrice']      ?? null,
                'leverage'         => $pos['leverage']      ?? '1',
                'openedAt'         => isset($pos['createdTime'])
                    ? date('Y-m-d H:i:s', (int)$pos['createdTime'] / 1000)
                    : date('Y-m-d H:i:s'),
            ];
        }, array_filter($positions, fn($p) => (float)($p['size'] ?? 0) > 0)));
    }

    private function formatTrades(array $trades): array
    {
        return array_map(fn($t): array => [
            'id'        => $t['execId']     ?? '',
            'symbol'    => $t['symbol']     ?? '',
            'side'      => $t['side']       ?? '',
            'price'     => $t['execPrice']  ?? '0',
            'quantity'  => $t['execQty']    ?? '0',
            'closedPnl' => $t['closedPnl']  ?? null,
            'status'    => $t['execStatus'] ?? 'Unknown',
            'openedAt'  => isset($t['execTime'])
                ? date('Y-m-d H:i:s', (int)$t['execTime'] / 1000)
                : date('Y-m-d H:i:s'),
            'orderType' => $t['orderType']  ?? '',
        ], $trades);
    }

    private function formatOrders(array $orders): array
    {
        return array_map(fn($o): array => [
            'orderId'      => $o['orderId']      ?? '',
            'orderLinkId'  => $o['orderLinkId']  ?? '',
            'symbol'       => $o['symbol']       ?? '',
            'side'         => $o['side']         ?? '',
            'orderType'    => $o['orderType']    ?? '',
            'price'        => $o['price']        ?? '0',
            'triggerPrice' => $o['triggerPrice'] ?? null,
            'qty'          => $o['qty']          ?? '0',
            'leavesQty'    => $o['leavesQty']    ?? '0',
            'cumExecQty'   => $o['cumExecQty']   ?? '0',
            'cumExecValue' => $o['cumExecValue'] ?? '0',
            'status'       => $o['orderStatus']  ?? 'Unknown',
            'timeInForce'  => $o['timeInForce']  ?? 'GTC',
            'createdTime'  => isset($o['createdTime'])
                ? date('Y-m-d H:i:s', (int)$o['createdTime'] / 1000)
                : date('Y-m-d H:i:s'),
            'updatedTime'  => isset($o['updatedTime'])
                ? date('Y-m-d H:i:s', (int)$o['updatedTime'] / 1000)
                : date('Y-m-d H:i:s'),
        ], $orders);
    }

    // ═══════════════════════════════════════════════════════════════
    // Mock data (used when API keys are absent)
    // ═══════════════════════════════════════════════════════════════

    private function getMockPositions(): array
    {
        return [[
            'symbol' => 'BTCUSDT', 'side' => 'Buy', 'size' => '0.1',
            'entryPrice' => '45000.00', 'markPrice' => '45200.00',
            'unrealizedPnl' => '20.00', 'leverage' => '10',
            'openedAt' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        ]];
    }

    private function getMockTrades(int $limit): array
    {
        $trades = [];
        for ($i = 0; $i < min($limit, 50); $i++) {
            $trades[] = [
                'id' => 'mock_' . ($i + 1),
                'symbol'    => ['BTCUSDT', 'ETHUSDT', 'BNBUSDT'][rand(0, 2)],
                'side'      => ['Buy', 'Sell'][rand(0, 1)],
                'price'     => (string)rand(40000, 50000),
                'quantity'  => (string)(rand(1, 100) / 100),
                'closedPnl' => (string)(rand(-100, 200)),
                'status'    => 'Filled',
                'openedAt'  => date('Y-m-d H:i:s', strtotime("-{$i} hours")),
                'orderType' => 'Market',
            ];
        }
        return $trades;
    }
}
