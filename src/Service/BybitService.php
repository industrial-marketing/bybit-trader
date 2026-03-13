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
    private const QTY_ERROR_CODES = [110017, 110009, 170036, 170037];
    // retCode for rate limit exceeded
    private const RATE_LIMIT_CODE = 10006;
    // retCode: position idx not match position mode (One-Way vs Hedge)
    private const POSITION_IDX_MISMATCH = 10001;
    // set-leverage: leverage already at requested value → continue
    private const SET_LEVERAGE_NO_CHANGE = 110043;
    private array $instrumentMemCache = [];

    /** Bybit margin modes (account-level for UTA) */
    public const MARGIN_REGULAR   = 'REGULAR_MARGIN';
    public const MARGIN_ISOLATED  = 'ISOLATED_MARGIN';
    public const MARGIN_PORTFOLIO = 'PORTFOLIO_MARGIN';

    /**
     * positionIdx: 0 = one-way mode; 1 = Buy side hedge; 2 = Sell side hedge.
     */
    private function getPositionIdx(string $positionSide): int
    {
        $trading = $this->settingsService->getTradingSettings();
        $mode    = $trading['bybit_position_mode'] ?? 'one_way';
        if ($mode === 'hedge') {
            return strtoupper($positionSide) === 'BUY' ? 1 : 2;
        }
        return 0;
    }

    /** Alternate positionIdx for retry when retCode 10001 (mode mismatch). */
    private function getAlternatePositionIdx(int $posIdx, string $positionSide): int
    {
        if ($posIdx === 0) {
            return strtoupper($positionSide) === 'BUY' ? 1 : 2;
        }
        return 0;
    }

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

        $cached = AtomicFileStorage::read($cacheFile);
        if (isset($cached['ts'], $cached['offset']) && (time() - (int)$cached['ts']) < $ttlSec) {
            return (int)$cached['offset'];
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

                AtomicFileStorage::write($cacheFile, ['ts' => time(), 'offset' => $offset]);

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
        return AtomicFileStorage::read($this->instrumentCacheFile());
    }

    private function saveInstrumentDiskCache(array $cache): void
    {
        AtomicFileStorage::write($this->instrumentCacheFile(), $cache);
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
            $fetchedAtMs = (int)(microtime(true) * 1000);
            $allRaw = [];

            foreach (['USDT', 'USDC'] as $settleCoin) {
                $cursor = '';
                do {
                    $params = ['category' => 'linear', 'settleCoin' => $settleCoin, 'limit' => 200];
                    if ($cursor !== '') {
                        $params['cursor'] = $cursor;
                    }
                    $response = $this->requestWithRetry('GET', $baseUrl . '/v5/position/list', [
                        'query'   => $params,
                        'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings),
                    ]);

                    $data = $response->toArray(false);
                    $this->logRawIfFirst('position/list', $data);

                    if (in_array($data['retCode'] ?? -1, [10002], true)) {
                        $this->invalidateTimeOffset();
                        $response = $this->requestWithRetry('GET', $baseUrl . '/v5/position/list', [
                            'query'   => $params,
                            'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings),
                        ], 1);
                        $data = $response->toArray(false);
                    }

                    if (($data['retCode'] ?? -1) !== 0 || !isset($data['result']['list'])) {
                        break;
                    }

                    $allRaw = array_merge($allRaw, $data['result']['list']);
                    $cursor = $data['result']['nextPageCursor'] ?? '';
                } while ($cursor !== '');
            }

            return $this->formatPositions($allRaw, $fetchedAtMs);
        } catch (\Exception $e) {
            $this->log('getPositions Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Диагностика: сырой ответ Bybit и счётчики для отладки проблемы «позиции не показываются».
     */
    public function getPositionsDebug(): array
    {
        $settings = $this->settingsService->getBybitSettings();
        $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены', 'base_url' => $baseUrl];
        }

        try {
            $params  = ['category' => 'linear', 'settleCoin' => 'USDT', 'limit' => 200];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/position/list', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings),
            ]);

            $data = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;
            $retMsg  = $data['retMsg'] ?? '';
            $list    = $data['result']['list'] ?? [];
            $cursor  = $data['result']['nextPageCursor'] ?? '';

            $rawCount = count($list);
            $withSize = array_filter($list, fn($p) => (float)($p['size'] ?? 0) > 0);
            $symbols  = array_map(fn($p) => ($p['symbol'] ?? '') . '/' . ($p['side'] ?? ''), $withSize);

            return [
                'ok'            => $retCode === 0,
                'base_url'      => $baseUrl,
                'retCode'       => $retCode,
                'retMsg'        => $retMsg,
                'raw_count'     => $rawCount,
                'with_size_gt0' => count($withSize),
                'symbols'       => array_values($symbols),
                'nextPageCursor' => $cursor,
                'formatted'     => $this->formatPositions($list, (int)(microtime(true) * 1000)),
            ];
        } catch (\Exception $e) {
            return [
                'ok'       => false,
                'error'    => $e->getMessage(),
                'base_url' => $baseUrl,
            ];
        }
    }

    public function getTrades(int $limit = 500): array
    {
        $settings = $this->settingsService->getBybitSettings();

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return $this->getMockTrades($limit);
        }

        try {
            $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params   = ['category' => 'linear', 'settleCoin' => 'USDT', 'limit' => min($limit, 500)];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/execution/list', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/execution/list', $params, $settings),
            ]);
            $data = $response->toArray(false);
            $this->logRawIfFirst('execution/list', $data);

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

        $querySymbol = $this->toCanonicalSymbol($symbol);

        try {
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/market/tickers', [
                'query' => ['category' => 'linear', 'symbol' => $querySymbol],
            ]);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0 && isset($data['result']['list'][0])) {
                $item = $data['result']['list'][0];
                $item['_originalSymbol'] = $symbol;
                return $item;
            }
            return [];
        } catch (\Exception $e) {
            $this->log('getMarketData Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Cost-related ticker data for position management.
     *
     * Returns: fundingRate (decimal), spreadPct, markPrice, bid1Price, ask1Price.
     * Used by CostEstimatorService for min-edge checks.
     */
    public function getTickerCostInfo(string $symbol): array
    {
        $ticker = $this->getMarketData($symbol);
        if (empty($ticker)) {
            return [
                'fundingRate'  => 0.0,
                'spreadPct'   => 0.001,  // fallback 0.1%
                'markPrice'   => 0.0,
                'bid1Price'   => 0.0,
                'ask1Price'   => 0.0,
            ];
        }

        $mark   = (float)($ticker['markPrice']   ?? $ticker['lastPrice'] ?? 0);
        $bid1   = (float)($ticker['bid1Price']  ?? 0);
        $ask1   = (float)($ticker['ask1Price']  ?? 0);
        $funding= (float)($ticker['fundingRate'] ?? 0);

        $spreadPct = 0.001;
        if ($mark > 0 && $bid1 > 0 && $ask1 > 0) {
            $spreadPct = (($ask1 - $bid1) / $mark);
        }

        return [
            'fundingRate' => $funding,
            'spreadPct'   => round($spreadPct, 6),
            'markPrice'   => $mark,
            'bid1Price'   => $bid1,
            'ask1Price'   => $ask1,
        ];
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
            $this->logRawIfFirst('wallet-balance', $data);

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
     * Fetch kline and return summary string + raw arrays (single API call).
     *
     * @return array{summary: string, closes: float[], highs: float[], lows: float[]}
     */
    public function getKlineData(
        string $symbol,
        int    $intervalMinutes,
        int    $limit         = 60,
        int    $maxPricePoints = 30
    ): array {
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
                return ['summary' => "[kline error for {$klineSymbol}: {$err}]", 'closes' => [], 'highs' => [], 'lows' => []];
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
            $summary      = $header . ' closes:' . $pricesStr;

            return ['summary' => $summary, 'closes' => $closes, 'highs' => $highs, 'lows' => $lows];
        } catch (\Exception $e) {
            $this->log("getKlineData({$klineSymbol},{$interval}) error: " . $e->getMessage());
            return ['summary' => "[kline unavailable for {$klineSymbol}]", 'closes' => [], 'highs' => [], 'lows' => []];
        }
    }

    /**
     * Fetch kline and return compact LLM string. Wrapper around getKlineData().
     */
    public function getKlineHistory(
        string $symbol,
        int    $intervalMinutes,
        int    $limit         = 60,
        int    $maxPricePoints = 30
    ): string {
        $data = $this->getKlineData($symbol, $intervalMinutes, $limit, $maxPricePoints);
        return $data['summary'];
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

    /** @var array<string,int> */
    private static array $statsDiagLogCount = [];

    /**
     * Log first 1–2 raw Bybit responses for diagnostics (secrets redacted).
     */
    private function logRawIfFirst(string $endpoint, array $data): void
    {
        $key = $endpoint;
        $n   = self::$statsDiagLogCount[$key] ?? 0;
        if ($n >= 2) {
            return;
        }
        self::$statsDiagLogCount[$key] = $n + 1;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $sanitized = LogSanitizer::sanitize($json ?? '', $this->settingsService);
        $this->log("Stats diag [{$endpoint}] response #" . ($n + 1) . ": " . mb_substr($sanitized, 0, 2000));
    }

    /**
     * Fetch closed PnL via /v5/position/closed-pnl (category=linear).
     * @param int         $limit     Max records
     * @param int|null    $startTime Start timestamp ms
     * @param int|null    $endTime   End timestamp ms
     * @param string|null $cursor    Pagination cursor
     */
    public function getClosedPnl(int $limit = 100, ?int $startTime = null, ?int $endTime = null, ?string $cursor = null): array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return [];
        }

        try {
            $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $allList = [];
            $nextCursor = $cursor;
            $retCode = 0;
            $retMsg  = 'OK';

            for ($page = 0; $page < 5 && count($allList) < $limit; $page++) {
                $params = ['category' => 'linear', 'limit' => min(100, $limit - count($allList))];
                if ($startTime !== null) {
                    $params['startTime'] = $startTime;
                }
                if ($endTime !== null) {
                    $params['endTime'] = $endTime;
                }
                if ($nextCursor !== null && $nextCursor !== '') {
                    $params['cursor'] = $nextCursor;
                }
                $response = $this->requestWithRetry('GET', $baseUrl . '/v5/position/closed-pnl', [
                    'query'   => $params,
                    'headers' => $this->getAuthHeaders('GET', '/v5/position/closed-pnl', $params, $settings),
                ]);
                $data = $response->toArray(false);
                if ($page === 0) {
                    $this->logRawIfFirst('closed-pnl', $data);
                }

                $retCode = (int)($data['retCode'] ?? -1);
                if ($retCode !== 0) {
                    return ['retCode' => $retCode, 'retMsg' => $data['retMsg'] ?? '', 'list' => []];
                }
                $list = $data['result']['list'] ?? [];
                $allList = array_merge($allList, $list);
                $nextCursor = $data['result']['nextPageCursor'] ?? null;
                if (empty($nextCursor) || count($list) < 100) {
                    break;
                }
            }
            return ['retCode' => 0, 'retMsg' => $retMsg, 'list' => $allList, 'nextPageCursor' => $nextCursor];
        } catch (\Exception $e) {
            $this->log('getClosedPnl Error: ' . $e->getMessage());
            return ['retCode' => -1, 'retMsg' => $e->getMessage(), 'list' => []];
        }
    }

    public function getClosedTrades(int $limit = 200): array
    {
        $settings = $this->settingsService->getBybitSettings();

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return $this->getMockTrades($limit);
        }

        try {
            $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params   = ['category' => 'linear', 'settleCoin' => 'USDT', 'limit' => min($limit, 200)];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/execution/list', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/execution/list', $params, $settings),
            ]);
            $data = $response->toArray(false);
            $this->logRawIfFirst('execution/list', $data);

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

    /**
     * Closed trades for display: closed-pnl preferred (rich data), fallback to execution/list.
     * @param int         $limit  Records per page (default 50)
     * @param string      $period today|24h|7d|all
     * @param string|null $cursor Pagination cursor
     * @return array trades, summary, nextPageCursor
     */
    public function getClosedTradesForDisplay(int $limit = 50, string $period = 'all', ?string $cursor = null): array
    {
        $settings = $this->settingsService->getBybitSettings();
        $empty    = ['trades' => [], 'summary' => ['todayPnl' => 0, 'tradesCount' => 0, 'winRate' => 0, 'avgRoiPct' => 0, 'bestTrade' => 0, 'worstTrade' => 0, 'avgFee' => 0, 'avgDurationMs' => 0], 'nextPageCursor' => null];

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return $empty;
        }

        $endTime   = (int)(microtime(true) * 1000);
        $startTime = null;
        if ($period === 'today') {
            $startTime = strtotime('today') * 1000;
        } elseif ($period === '24h') {
            $startTime = $endTime - 86400 * 1000;
        } elseif ($period === '7d') {
            $startTime = $endTime - 7 * 86400 * 1000;
        }

        $closedPnlRaw = $this->getClosedPnl(max(100, $limit + 50), $startTime, $endTime, $cursor);
        $list         = $closedPnlRaw['list'] ?? [];

        if (empty($list)) {
            $execTrades = $this->getClosedTrades(max(100, $limit));
            return $this->buildTradesDisplayFromExec($execTrades, $empty);
        }

        $trades   = [];
        $today    = date('Y-m-d');
        $todayPnl = 0.0;
        $wins     = 0;
        $totalRoi = 0.0;
        $roiCount = 0;

        foreach (array_slice($list, 0, $limit) as $t) {
            $entryPrice  = (float)($t['avgEntryPrice'] ?? 0);
            $exitPrice   = (float)($t['avgExitPrice'] ?? $t['orderPrice'] ?? 0);
            $closedSize  = (float)($t['closedSize'] ?? $t['qty'] ?? 0);
            $closedPnl   = (float)($t['closedPnl'] ?? 0);
            $openFee     = (float)($t['openFee'] ?? 0);
            $closeFee    = (float)($t['closeFee'] ?? 0);
            $fee         = $openFee + $closeFee;
            $leverage    = max(1, (int)($t['leverage'] ?? 1));
            $positionVal = $closedSize * $entryPrice;
            $margin      = $leverage > 0 ? ($positionVal / $leverage) : $positionVal;
            $roiPct      = $margin > 0 ? (($closedPnl / $margin) * 100) : 0;

            $createdMs = (int)($t['createdTime'] ?? 0);
            $updatedMs = (int)($t['updatedTime'] ?? $createdMs);
            $duration   = $updatedMs > 0 && $createdMs > 0 ? max(0, $updatedMs - $createdMs) : 0;

            $closedAt   = $updatedMs > 0 ? date('Y-m-d H:i:s', (int)($updatedMs / 1000)) : '';
            $closedDate = $closedAt ? substr($closedAt, 0, 10) : '';
            if ($closedDate === $today) {
                $todayPnl += $closedPnl;
            }
            if ($closedPnl > 0) {
                $wins++;
            }
            if ($margin > 0) {
                $totalRoi += $roiPct;
                $roiCount++;
            }

            $execType = $t['execType'] ?? 'Trade';
            $status   = $this->mapExecTypeToStatus($execType);

            $trades[] = [
                'id'              => $t['orderId'] ?? '',
                'symbol'          => $t['symbol'] ?? '',
                'side'            => $t['side'] ?? '',
                'entryPrice'      => $entryPrice,
                'exitPrice'       => $exitPrice,
                'quantity'        => $closedSize,
                'positionSizeUsdt'=> round($positionVal, 2),
                'leverage'        => $leverage,
                'closedPnl'       => $closedPnl,
                'fee'             => $fee,
                'roiPct'          => round($roiPct, 2),
                'status'          => $status,
                'durationMs'      => $duration,
                'openedAt'        => $createdMs > 0 ? date('Y-m-d H:i:s', (int)($createdMs / 1000)) : '',
                'closedAt'        => $closedAt,
            ];
        }

        $tradesCount   = count($trades);
        $winRate       = $tradesCount > 0 ? round(100 * $wins / $tradesCount, 1) : 0;
        $avgRoiPct     = $roiCount > 0 ? round($totalRoi / $roiCount, 2) : 0;
        $durations     = array_filter(array_column($trades, 'durationMs'), fn($d) => $d > 0);
        $avgDurationMs = !empty($durations) ? (int)round(array_sum($durations) / count($durations)) : 0;
        $fees          = array_filter(array_column($trades, 'fee'), fn($f) => $f > 0);
        $avgFee        = !empty($fees) ? round(array_sum($fees) / count($fees), 4) : 0;
        $pnls          = array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $trades);
        $bestTrade     = !empty($pnls) ? max($pnls) : 0;
        $worstTrade    = !empty($pnls) ? min($pnls) : 0;

        return [
            'trades'         => array_slice($trades, 0, $limit),
            'summary'        => [
                'todayPnl'      => round($todayPnl, 2),
                'tradesCount'   => $tradesCount,
                'winRate'       => $winRate,
                'avgRoiPct'     => $avgRoiPct,
                'avgDurationMs' => $avgDurationMs,
                'avgFee'        => round($avgFee, 4),
                'bestTrade'     => round($bestTrade, 2),
                'worstTrade'    => round($worstTrade, 2),
            ],
            'nextPageCursor' => $closedPnlRaw['nextPageCursor'] ?? null,
        ];
    }

    private function mapExecTypeToStatus(string $execType): string
    {
        return match (strtolower($execType)) {
            'trade'            => 'CLOSED',
            'settle'           => 'SETTLE',
            'sessionsettlepnl' => 'FUNDING',
            'busttrade'        => 'LIQUIDATED',
            'moveposition'     => 'MOVE',
            default            => strtoupper($execType ?: 'CLOSED'),
        };
    }

    private function buildTradesDisplayFromExec(array $execTrades, array $empty): array
    {
        $trades   = [];
        $today    = date('Y-m-d');
        $todayPnl = 0.0;
        $wins     = 0;
        $totalRoi = 0.0;
        $roiCount = 0;

        foreach ($execTrades as $t) {
            $closedPnl = (float)($t['closedPnl'] ?? 0);
            $price     = (float)($t['price'] ?? 0);
            $qty       = (float)($t['quantity'] ?? 0);
            $fee       = (float)($t['execFee'] ?? 0);
            $positionVal = $price * $qty;
            $margin    = $positionVal > 0 ? ($positionVal / 5) : 0; // exec: no leverage, approximate
            $roiPct    = $margin > 0 ? (($closedPnl / $margin) * 100) : 0;

            $openedAt = $t['openedAt'] ?? '';
            $closedAt = $openedAt; // exec has exec time
            $closedDate = $closedAt ? substr($closedAt, 0, 10) : '';
            if ($closedDate === $today) {
                $todayPnl += $closedPnl;
            }
            if ($closedPnl > 0) {
                $wins++;
            }
            if ($margin > 0) {
                $totalRoi += $roiPct;
                $roiCount++;
            }

            $trades[] = [
                'id'              => $t['id'] ?? '',
                'symbol'          => $t['symbol'] ?? '',
                'side'            => $t['side'] ?? '',
                'entryPrice'      => 0,
                'exitPrice'       => $price,
                'quantity'        => $qty,
                'positionSizeUsdt'=> round($positionVal, 2),
                'leverage'        => 0,
                'closedPnl'       => $closedPnl,
                'fee'             => $fee,
                'roiPct'          => round($roiPct, 2),
                'status'          => $t['status'] ?? 'CLOSED',
                'durationMs'      => 0,
                'openedAt'        => $openedAt,
                'closedAt'        => $closedAt,
            ];
        }

        $tradesCount   = count($trades);
        $winRate       = $tradesCount > 0 ? round(100 * $wins / $tradesCount, 1) : 0;
        $avgRoiPct     = $roiCount > 0 ? round($totalRoi / $roiCount, 2) : 0;
        $durations     = array_filter(array_column($trades, 'durationMs'), fn($d) => $d > 0);
        $avgDurationMs = !empty($durations) ? (int)round(array_sum($durations) / count($durations)) : 0;

        $pnls      = array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $trades);
        $fees      = array_filter(array_column($trades, 'fee'), fn($f) => $f > 0);
        return [
            'trades'         => $trades,
            'summary'        => [
                'todayPnl'      => round($todayPnl, 2),
                'tradesCount'   => $tradesCount,
                'winRate'       => $winRate,
                'avgRoiPct'     => $avgRoiPct,
                'avgDurationMs' => 0,
                'avgFee'        => !empty($fees) ? round(array_sum($fees) / count($fees), 4) : 0,
                'bestTrade'     => !empty($pnls) ? round(max($pnls), 2) : 0,
                'worstTrade'    => !empty($pnls) ? round(min($pnls), 2) : 0,
            ],
            'nextPageCursor' => null,
        ];
    }

    public function getStatistics(): array
    {
        $settings   = $this->settingsService->getBybitSettings();
        $isTestnet   = ($settings['base_url'] ?? '') !== 'https://api.bybit.com';
        $baseResult  = [
            'totalTrades'    => 0, 'winRate' => 0.0, 'totalProfit' => 0.0, 'totalFees' => 0.0,
            'averageProfit'  => 0.0, 'maxDrawdown' => 0.0, 'profitFactor' => 0.0,
            'avgDurationMs'  => 0, 'winningTrades' => 0, 'losingTrades' => 0,
            'source'         => 'empty',
            'closedTradesCount' => 0,
            'tradesCount'    => 0,
            'bybitRetCode'   => null,
            'bybitRetMsg'    => null,
            'note'           => null,
        ];

        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            $baseResult['note'] = 'API keys not configured';
            return $baseResult;
        }

        // 1) Try closed-pnl (category=linear, native PnL endpoint, up to 200 via pagination)
        $closedPnlRaw = $this->getClosedPnl(200);
        $closedPnlList = $closedPnlRaw['list'] ?? [];
        $closedTradesCount = count($closedPnlList);

        if ($closedTradesCount > 0) {
            $trades = $this->formatClosedPnlTrades($closedPnlList);
            $baseResult['source'] = 'closedPnl';
            $baseResult['closedTradesCount'] = $closedTradesCount;
            $baseResult['bybitRetCode'] = $closedPnlRaw['retCode'] ?? null;
            $baseResult['bybitRetMsg'] = ($closedPnlRaw['retCode'] ?? 0) !== 0 ? ($closedPnlRaw['retMsg'] ?? '') : null;
        } else {
            $bybitRetCode = $closedPnlRaw['retCode'] ?? -1;
            $bybitRetMsg  = $closedPnlRaw['retMsg'] ?? '';

            // 2) Fallback: execution/list filtered by closedPnl (limit 200)
            $execClosed = $this->getClosedTrades(200);
            $closedTradesCount = count($execClosed);

            if ($closedTradesCount > 0) {
                $trades = $execClosed;
                $baseResult['source'] = 'closedTrades';
                $baseResult['closedTradesCount'] = $closedTradesCount;
            } else {
                // 3) Fallback: execution/list all (limit 500), filter by closedPnl
                $allTrades = $this->getTrades(500);
                $tradesCount = count($allTrades);
                $trades = array_filter($allTrades, fn($t) => isset($t['closedPnl']) && $t['closedPnl'] !== null);

                if (!empty($trades)) {
                    $baseResult['source'] = 'trades';
                    $baseResult['closedTradesCount'] = count($trades);
                    $baseResult['tradesCount'] = $tradesCount;
                } else {
                    $baseResult['closedTradesCount'] = 0;
                    $baseResult['tradesCount'] = $tradesCount;
                    if ($bybitRetCode !== 0) {
                        $baseResult['bybitRetCode'] = $bybitRetCode;
                        $baseResult['bybitRetMsg'] = $bybitRetMsg;
                        $baseResult['note'] = "Bybit retCode={$bybitRetCode}: " . mb_substr($bybitRetMsg, 0, 80);
                    } elseif ($isTestnet) {
                        $baseResult['note'] = 'Statistics not available on testnet (Bybit returns empty closed PnL). Switch to mainnet or place a few test trades.';
                    } else {
                        $baseResult['note'] = 'No closed PnL data (history empty, wrong category, or UTA account)';
                    }
                    return $baseResult;
                }
            }
        }

        $closedTrades  = array_filter($trades, fn($t) => isset($t['closedPnl']) && $t['closedPnl'] !== null);
        $totalTrades   = count($closedTrades);
        $winningTrades = array_filter($closedTrades, fn($t) => (float)($t['closedPnl'] ?? 0) > 0);
        $losingTrades  = array_filter($closedTrades, fn($t) => (float)($t['closedPnl'] ?? 0) < 0);

        $durations    = array_filter(array_map(fn($t) => (int)($t['durationMs'] ?? 0), $closedTrades), fn($d) => $d > 0);
        $avgDurationMs = !empty($durations) ? (int)round(array_sum($durations) / count($durations)) : 0;

        $totalProfit = array_sum(array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $closedTrades));
        $totalFees   = array_sum(array_map(fn($t) => (float)($t['execFee'] ?? 0), $trades));
        $winRate     = $totalTrades > 0 ? (count($winningTrades) / $totalTrades) * 100 : 0;
        $avgProfit   = $totalTrades > 0 ? $totalProfit / $totalTrades : 0;
        $profits     = array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $closedTrades);
        $maxDrawdown = $profits ? min($profits) : 0;
        $winSum      = array_sum(array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $winningTrades));
        $loseSum     = abs(array_sum(array_map(fn($t) => (float)($t['closedPnl'] ?? 0), $losingTrades)));
        $profitFactor= $loseSum > 0 ? $winSum / $loseSum : ($winSum > 0 ? 999 : 0);

        return array_merge($baseResult, [
            'totalTrades'    => $totalTrades,
            'winRate'        => round($winRate, 2),
            'totalProfit'    => round($totalProfit, 2),
            'totalFees'      => round($totalFees, 2),
            'averageProfit'  => round($avgProfit, 2),
            'maxDrawdown'    => round($maxDrawdown, 2),
            'profitFactor'   => round($profitFactor, 2),
            'avgDurationMs' => $avgDurationMs,
            'winningTrades'  => count($winningTrades),
            'losingTrades'   => count($losingTrades),
        ]);
    }

    /** Format closed-pnl API response to same structure as formatTrades. */
    private function formatClosedPnlTrades(array $list): array
    {
        return array_map(function ($t): array {
            $createdMs = (int)($t['createdTime'] ?? 0);
            $updatedMs = (int)($t['updatedTime'] ?? $createdMs);
            $duration  = $updatedMs > 0 && $createdMs > 0 ? max(0, $updatedMs - $createdMs) : 0;
            return [
                'id'          => $t['orderId'] ?? '',
                'symbol'      => $t['symbol'] ?? '',
                'side'        => $t['side'] ?? '',
                'price'       => $t['avgExitPrice'] ?? $t['orderPrice'] ?? '0',
                'quantity'    => $t['closedSize'] ?? $t['qty'] ?? '0',
                'closedPnl'   => $t['closedPnl'] ?? null,
                'execFee'     => isset($t['openFee'], $t['closeFee'])
                    ? (float)($t['openFee'] ?? 0) + (float)($t['closeFee'] ?? 0)
                    : null,
                'durationMs'  => $duration,
                'status'      => 'Trade',
                'openedAt'    => $createdMs > 0 ? date('Y-m-d H:i:s', (int)($createdMs / 1000)) : date('Y-m-d H:i:s'),
                'orderType'   => $t['orderType'] ?? '',
            ];
        }, $list);
    }

    // ═══════════════════════════════════════════════════════════════
    // Account info & margin mode (UTA: account-level, not per-position)
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /v5/account/info — marginMode (REGULAR_MARGIN, ISOLATED_MARGIN, PORTFOLIO_MARGIN), unifiedMarginStatus.
     * Для UTA режим маржи задаётся на уровне аккаунта; position/list.tradeMode deprecated.
     */
    public function getAccountInfo(): ?array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return null;
        }
        try {
            $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params   = [];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/account/info', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/account/info', $params, $settings),
            ], 2);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0 && isset($data['result'])) {
                return [
                    'marginMode'           => $data['result']['marginMode'] ?? 'REGULAR_MARGIN',
                    'unifiedMarginStatus'  => $data['result']['unifiedMarginStatus'] ?? null,
                    'updatedTime'          => $data['result']['updatedTime'] ?? null,
                ];
            }
            return null;
        } catch (\Exception $e) {
            $this->log('getAccountInfo Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Optional guard: проверяет margin mode и при необходимости вызывает POST /v5/account/set-margin-mode.
     * Для cross Bybit требует buyLeverage = sellLeverage (уже соблюдается в placeOrder).
     *
     * @return array{ok: bool, error?: string} — ok=true если режим совпадает или успешно переключён
     */
    public function ensureMarginMode(string $targetMode): array
    {
        $targetMode = strtoupper($targetMode);
        if (!in_array($targetMode, ['REGULAR_MARGIN', 'ISOLATED_MARGIN', 'PORTFOLIO_MARGIN'], true)) {
            return ['ok' => false, 'error' => "Invalid target margin mode: {$targetMode}"];
        }

        $info = $this->getAccountInfo();
        if ($info === null) {
            return ['ok' => false, 'error' => 'Не удалось получить account info'];
        }

        $current = $info['marginMode'] ?? 'REGULAR_MARGIN';
        if ($current === $targetMode) {
            $this->log("ensureMarginMode: already {$targetMode}");
            return ['ok' => true];
        }

        $settings = $this->settingsService->getBybitSettings();
        $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        $body     = json_encode(['setMarginMode' => $targetMode]);
        $emptyParams = [];

        try {
            $response = $this->requestWithRetry('POST', $baseUrl . '/v5/account/set-margin-mode', [
                'headers' => $this->getAuthHeaders('POST', '/v5/account/set-margin-mode', $emptyParams, $settings, $body),
                'body'    => $body,
            ]);
            $data   = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;
            $retMsg  = $data['retMsg'] ?? '';
            $this->log("ensureMarginMode set-margin-mode → retCode={$retCode} retMsg={$retMsg}");

            if ($retCode === 0) {
                return ['ok' => true];
            }
            return ['ok' => false, 'error' => $retMsg ?: 'Set margin mode failed', 'retCode' => $retCode];
        } catch (\Exception $e) {
            $this->log('ensureMarginMode Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Trading: open / close / stop-loss
    // ═══════════════════════════════════════════════════════════════

    /**
     * Open a market order.
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

            // UTA: margin mode на уровне аккаунта. ensureMarginMode перед ордером.
            $requiredMode = $trading['required_margin_mode'] ?? 'auto';
            if ($requiredMode === 'cross') {
                $guard = $this->ensureMarginMode(self::MARGIN_REGULAR);
                if (!($guard['ok'] ?? false)) {
                    return ['ok' => false, 'error' => 'Режим маржи: ' . ($guard['error'] ?? 'cross не установлен')];
                }
            } elseif ($requiredMode === 'isolated') {
                $guard = $this->ensureMarginMode(self::MARGIN_ISOLATED);
                if (!($guard['ok'] ?? false)) {
                    return ['ok' => false, 'error' => 'Режим маржи: ' . ($guard['error'] ?? 'isolated не установлен')];
                }
            }
            // auto: не проверяем, работаем с текущим режимом

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

            // min/max_position_usdt = маржа (залог), не номинальный размер
            $minMarginUsdt = max(0, (float)($trading['min_position_usdt'] ?? 0));
            $maxMarginUsdt = max(0, (float)($trading['max_position_usdt'] ?? 0));
            $marginUsdt    = $leverage > 0 ? ($positionSizeUSDT / $leverage) : 0;

            if ($minMarginUsdt > 0 && $marginUsdt < $minMarginUsdt) {
                return [
                    'ok'              => true,
                    'skipped'         => true,
                    'skipReason'      => 'below_min_position',
                    'minPositionUSDT' => $minMarginUsdt * $leverage,
                ];
            }
            if ($maxMarginUsdt > 0 && $marginUsdt > $maxMarginUsdt) {
                $positionSizeUSDT = $maxMarginUsdt * $leverage;
            }

            $rawQty = $positionSizeUSDT / $price;
            if ($qtyStep > 0) {
                $rawQty = floor($rawQty / $qtyStep) * $qtyStep;
                $rawQty = round($rawQty, 8);
            }
            $qty = $rawQty;

            $bybitMinUsdt = $minOrderQty > 0 ? $minOrderQty * $price : 0;
            if ($bybitMinUsdt > 0 && $positionSizeUSDT < $bybitMinUsdt) {
                return [
                    'ok'              => true,
                    'skipped'         => true,
                    'skipReason'      => 'below_min_position',
                    'minPositionUSDT' => $bybitMinUsdt,
                ];
            }

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

            // Set leverage — обязательно перед ордером при isolated
            $emptyParams      = [];
            $bodySetLeverage  = json_encode(['category' => 'linear', 'symbol' => $symbol, 'buyLeverage' => (string)$leverage, 'sellLeverage' => (string)$leverage]);
            $respLev = $this->requestWithRetry('POST', $baseUrl . '/v5/position/set-leverage', [
                'headers' => $this->getAuthHeaders('POST', '/v5/position/set-leverage', $emptyParams, $settings, $bodySetLeverage),
                'body'    => $bodySetLeverage,
            ]);
            $dataLev = $respLev->toArray(false);
            $rcLev   = $dataLev['retCode'] ?? -1;
            $msgLev  = $dataLev['retMsg'] ?? '';
            $this->log("placeOrder set-leverage → retCode={$rcLev} retMsg={$msgLev}");
            $levNoChange = ($rcLev === self::SET_LEVERAGE_NO_CHANGE) || (stripos($msgLev, 'leverage not modified') !== false || stripos($msgLev, 'has not been modified') !== false);
            if ($rcLev !== 0 && !$levNoChange) {
                $this->log("placeOrder abort: set-leverage failed ({$rcLev}) {$msgLev}");
                return ['ok' => false, 'error' => "set-leverage: {$msgLev}", 'retCode' => $rcLev];
            }
            if ($levNoChange) {
                $this->log("placeOrder set-leverage: leverage already set, continuing");
            }

            // Place market order (with retry on position idx mismatch)
            $posIdx   = $this->getPositionIdx($side);
            $bodyOrder = json_encode(['category' => 'linear', 'symbol' => $symbol, 'side' => $side, 'orderType' => 'Market', 'qty' => (string)$qty, 'positionIdx' => $posIdx]);
            $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                'body'    => $bodyOrder,
            ]);

            $data    = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;
            $retMsg  = $data['retMsg'] ?? '';
            $orderId = $data['result']['orderId'] ?? null;

            if ($retCode === self::POSITION_IDX_MISMATCH) {
                $posIdx   = $this->getAlternatePositionIdx($posIdx, $side);
                $bodyOrder = json_encode(['category' => 'linear', 'symbol' => $symbol, 'side' => $side, 'orderType' => 'Market', 'qty' => (string)$qty, 'positionIdx' => $posIdx]);
                $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                    'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                    'body'    => $bodyOrder,
                ]);
                $data    = $response->toArray(false);
                $retCode = $data['retCode'] ?? -1;
                $retMsg  = $data['retMsg'] ?? '';
                $orderId = $data['result']['orderId'] ?? null;
                $this->log("placeOrder create (retry alt positionIdx) → retCode={$retCode} retMsg={$retMsg} orderId=" . ($orderId ?? 'n/a'));
            } else {
                $this->log("placeOrder create → retCode={$retCode} retMsg={$retMsg} orderId=" . ($orderId ?? 'n/a'));
            }

            // Auto-invalidate instrument cache on qty-related errors
            if (in_array($retCode, self::QTY_ERROR_CODES, true)) {
                $this->log("placeOrder qty error retCode={$retCode}, invalidating instrument cache for {$symbol}");
                $this->invalidateInstrumentCache($symbol);
            }

            if ($retCode !== 0) {
                return ['ok' => false, 'error' => $retMsg ?: 'Unknown error', 'retCode' => $retCode];
            }

            // Post-check: верификация, что позиция реально открылась (не только "ордер принят")
            sleep(2);
            $positions = $this->getPositions();
            $posSide   = strtoupper($side) === 'BUY' ? 'Buy' : 'Sell';
            $positionVerified = false;
            foreach ($positions as $p) {
                if (($p['symbol'] ?? '') === $symbol && ($p['side'] ?? '') === $posSide && (float)($p['size'] ?? 0) > 0) {
                    $positionVerified = true;
                    break;
                }
            }
            if (!$positionVerified && $orderId) {
                $orderInfo = $this->getOrderFromHistory($symbol, $orderId);
                if ($orderInfo && ($orderInfo['orderStatus'] ?? '') === 'Filled') {
                    $positionVerified = true;
                }
            }

            return [
                'ok'               => true,
                'result'           => $data['result'] ?? [],
                'orderId'          => $orderId,
                'positionVerified' => $positionVerified,
            ];
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

        $size      = (float)($position['size'] ?? 0);
        $markPrice  = (float)($position['markPrice'] ?? $position['entryPrice'] ?? 0);
        $fraction   = max(0.05, min(1.0, $fraction));
        $qty        = $size * $fraction;
        $notional   = $qty * ($markPrice > 0 ? $markPrice : 1);

        $trading       = $this->settingsService->getTradingSettings();
        $minMarginUsdt = max(0, (float)($trading['min_position_usdt'] ?? 0));
        $leverage      = max(1, (float)($position['leverage'] ?? 1));
        $marginClosed  = $leverage > 0 ? ($notional / $leverage) : 0;
        if ($minMarginUsdt > 0 && $marginClosed < $minMarginUsdt) {
            return ['ok' => true, 'skipped' => true, 'skipReason' => 'below_min_position'];
        }

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

        $posIdx = isset($position['positionIdx']) ? (int)$position['positionIdx'] : $this->getPositionIdx($currentSide);
        try {
            $bodyOrder = json_encode(['category' => 'linear', 'symbol' => $symbol, 'side' => $orderSide, 'orderType' => 'Market', 'qty' => (string)$qty, 'reduceOnly' => true, 'positionIdx' => $posIdx]);
            $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                'body'    => $bodyOrder,
            ]);
            $data    = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;

            if ($retCode === self::POSITION_IDX_MISMATCH) {
                $posIdx = $this->getAlternatePositionIdx($posIdx, $currentSide);
                $this->log("closePositionMarket retry positionIdx={$posIdx} (was mismatch)");
                $bodyOrder = json_encode(['category' => 'linear', 'symbol' => $symbol, 'side' => $orderSide, 'orderType' => 'Market', 'qty' => (string)$qty, 'reduceOnly' => true, 'positionIdx' => $posIdx]);
                $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                    'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                    'body'    => $bodyOrder,
                ]);
                $data    = $response->toArray(false);
                $retCode = $data['retCode'] ?? -1;
            }

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
     * Round price to instrument tickSize (for limit orders).
     */
    public function roundPriceToTick(string $symbol, float $price): float
    {
        $settings    = $this->settingsService->getBybitSettings();
        $instrument  = $this->getInstrumentInfo($symbol, $settings);
        $priceFilter = $instrument['priceFilter'] ?? [];
        $tickSize    = isset($priceFilter['tickSize']) ? (float)$priceFilter['tickSize'] : 0.01;
        if ($tickSize <= 0) {
            $tickSize = 0.01;
        }
        $steps = round($price / $tickSize, 0);
        return round($steps * $tickSize, 8);
    }

    /**
     * Place limit order (open/add position). For rotational grid add layers.
     *
     * @param string      $symbol           e.g. BTCUSDT
     * @param string      $side             Buy or Sell
     * @param float       $price            Limit price (will be rounded to tickSize)
     * @param float       $positionSizeUSDT Notional size in USDT
     * @param int         $leverage
     * @param string|null $orderLinkId      Optional custom ID (max 36 chars)
     */
    public function placeLimitOrder(string $symbol, string $side, float $price, float $positionSizeUSDT, int $leverage, ?string $orderLinkId = null): array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены'];
        }

        try {
            $baseUrl   = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $trading   = $this->settingsService->getTradingSettings();
            $instrument = $this->getInstrumentInfo($symbol, $settings);
            $lotFilter  = $instrument['lotSizeFilter'] ?? [];
            $qtyStep    = (float)($lotFilter['qtyStep'] ?? 0.001);
            $minOrderQty = (float)($lotFilter['minOrderQty'] ?? 0);
            $priceRounded = $this->roundPriceToTick($symbol, $price);

            $rawQty = $positionSizeUSDT / $priceRounded;
            if ($qtyStep > 0) {
                $rawQty = floor($rawQty / $qtyStep) * $qtyStep;
            }
            $qty = round(max(0, $rawQty), 8);
            if ($minOrderQty > 0 && $qty < $minOrderQty) {
                return ['ok' => false, 'error' => sprintf('Минимальный объём %.8f для %s', $minOrderQty, $symbol)];
            }
            if ($qty <= 0) {
                return ['ok' => false, 'error' => 'Объём слишком мал (qty=0)'];
            }

            $requiredMode = $trading['required_margin_mode'] ?? 'auto';
            if ($requiredMode === 'cross') {
                $guard = $this->ensureMarginMode(self::MARGIN_REGULAR);
                if (!($guard['ok'] ?? false)) {
                    return ['ok' => false, 'error' => 'Режим маржи: ' . ($guard['error'] ?? 'cross не установлен')];
                }
            } elseif ($requiredMode === 'isolated') {
                $guard = $this->ensureMarginMode(self::MARGIN_ISOLATED);
                if (!($guard['ok'] ?? false)) {
                    return ['ok' => false, 'error' => 'Режим маржи: ' . ($guard['error'] ?? 'isolated не установлен')];
                }
            }

            $leverage = max(1, min(100, $leverage));
            $emptyParams = [];
            $bodySetLev  = json_encode(['category' => 'linear', 'symbol' => $symbol, 'buyLeverage' => (string)$leverage, 'sellLeverage' => (string)$leverage]);
            $this->requestWithRetry('POST', $baseUrl . '/v5/position/set-leverage', [
                'headers' => $this->getAuthHeaders('POST', '/v5/position/set-leverage', $emptyParams, $settings, $bodySetLev),
                'body'    => $bodySetLev,
            ]);

            $posIdx = $this->getPositionIdx($side);
            $orderPayload = [
                'category'    => 'linear',
                'symbol'     => $symbol,
                'side'       => $side,
                'orderType'  => 'Limit',
                'qty'        => (string)$qty,
                'price'      => (string)$priceRounded,
                'timeInForce'=> 'GTC',
                'positionIdx'=> $posIdx,
            ];
            if ($orderLinkId !== null && $orderLinkId !== '') {
                $orderPayload['orderLinkId'] = substr($orderLinkId, 0, 36);
            }

            $bodyOrder = json_encode($orderPayload);
            $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                'body'    => $bodyOrder,
            ]);

            $data    = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;
            $orderId = $data['result']['orderId'] ?? null;
            $retMsg = $data['retMsg'] ?? '';
            if ($retCode === self::POSITION_IDX_MISMATCH) {
                $this->log("placeLimitOrder position idx mismatch (retCode=10001), retrying with alternate positionIdx");
                $orderPayload['positionIdx'] = $this->getAlternatePositionIdx($posIdx, $side);
                $bodyOrder = json_encode($orderPayload);
                $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                    'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                    'body'    => $bodyOrder,
                ]);
                $data    = $response->toArray(false);
                $retCode = $data['retCode'] ?? -1;
                $orderId = $data['result']['orderId'] ?? null;
                $retMsg = $data['retMsg'] ?? '';
            }
            $this->log("placeLimitOrder → symbol={$symbol} side={$side} price={$priceRounded} qty={$qty} retCode={$retCode} retMsg={$retMsg} orderId=" . ($orderId ?? 'n/a'));

            if ($retCode !== 0) {
                return ['ok' => false, 'error' => $retMsg ?: 'Unknown error', 'retCode' => $retCode];
            }
            return ['ok' => true, 'result' => $data['result'] ?? [], 'orderId' => $orderId];
        } catch (\Exception $e) {
            $this->log('placeLimitOrder Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Place limit reduce-only order (partial close for rotational grid unload).
     *
     * @param string      $symbol      e.g. BTCUSDT
     * @param string      $currentSide Buy or Sell (position side)
     * @param float       $price       Limit price (will be rounded to tickSize)
     * @param float       $qty         Size in contracts to close
     * @param string|null $orderLinkId Optional custom ID (max 36 chars)
     */
    public function placeLimitReduceOrder(string $symbol, string $currentSide, float $price, float $qty, ?string $orderLinkId = null): array
    {
        $settings   = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены'];
        }

        $instrument = $this->getInstrumentInfo($symbol, $settings);
        $lotFilter  = $instrument['lotSizeFilter'] ?? [];
        $qtyStep    = (float)($lotFilter['qtyStep'] ?? 0.001);
        $minOrderQty = (float)($lotFilter['minOrderQty'] ?? 0);
        $priceRounded = $this->roundPriceToTick($symbol, $price);

        if ($qtyStep > 0) {
            $qty = floor($qty / $qtyStep) * $qtyStep;
        }
        $qty = round(max(0, $qty), 8);
        if ($minOrderQty > 0 && $qty < $minOrderQty) {
            return ['ok' => false, 'error' => sprintf('Минимальный объём %.8f для %s', $minOrderQty, $symbol)];
        }
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Объём слишком мал (qty=0)'];
        }

        $orderSide = strtoupper($currentSide) === 'BUY' ? 'SELL' : 'BUY';
        $baseUrl   = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        $emptyParams = [];
        $trading   = $this->settingsService->getTradingSettings();
        $posIdx    = $this->getPositionIdx($currentSide);

        $orderPayload = [
            'category'    => 'linear',
            'symbol'     => $symbol,
            'side'       => $orderSide,
            'orderType'  => 'Limit',
            'qty'        => (string)$qty,
            'price'      => (string)$priceRounded,
            'timeInForce'=> 'GTC',
            'reduceOnly' => true,
            'positionIdx'=> $posIdx,
        ];
        if ($orderLinkId !== null && $orderLinkId !== '') {
            $orderPayload['orderLinkId'] = substr($orderLinkId, 0, 36);
        }

        try {
            $bodyOrder = json_encode($orderPayload);
            $response  = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                'body'    => $bodyOrder,
            ]);
            $data    = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;
            $retMsg  = $data['retMsg'] ?? '';

            if ($retCode === self::POSITION_IDX_MISMATCH) {
                $altIdx     = $this->getAlternatePositionIdx($posIdx, $currentSide);
                $orderPayload['positionIdx'] = $altIdx;
                $bodyOrder  = json_encode($orderPayload);
                $this->log("placeLimitReduceOrder retry → positionIdx {$posIdx}→{$altIdx}");
                $response   = $this->requestWithRetry('POST', $baseUrl . '/v5/order/create', [
                    'headers' => $this->getAuthHeaders('POST', '/v5/order/create', $emptyParams, $settings, $bodyOrder),
                    'body'    => $bodyOrder,
                ]);
                $data    = $response->toArray(false);
                $retCode = $data['retCode'] ?? -1;
                $retMsg  = $data['retMsg'] ?? '';
            }

            $orderId = $data['result']['orderId'] ?? null;
            $this->log("placeLimitReduceOrder → symbol={$symbol} side={$orderSide} price={$priceRounded} qty={$qty} retCode={$retCode} retMsg={$retMsg} orderId=" . ($orderId ?? 'n/a'));

            if ($retCode !== 0) {
                return ['ok' => false, 'error' => $retMsg ?: 'Unknown error', 'retCode' => $retCode];
            }
            return ['ok' => true, 'result' => $data['result'] ?? [], 'orderId' => $orderId];
        } catch (\Exception $e) {
            $this->log('placeLimitReduceOrder Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cancel order by orderId or orderLinkId.
     */
    public function cancelOrder(string $symbol, ?string $orderId = null, ?string $orderLinkId = null): array
    {
        if (($orderId ?? '') === '' && ($orderLinkId ?? '') === '') {
            return ['ok' => false, 'error' => 'Требуется orderId или orderLinkId'];
        }

        $settings   = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return ['ok' => false, 'error' => 'API ключи не настроены'];
        }

        $baseUrl = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
        $body    = ['category' => 'linear', 'symbol' => $symbol];
        if ($orderId !== null && $orderId !== '') {
            $body['orderId'] = $orderId;
        } else {
            $body['orderLinkId'] = $orderLinkId;
        }

        try {
            $bodyJson = json_encode($body);
            $response = $this->requestWithRetry('POST', $baseUrl . '/v5/order/cancel', [
                'headers' => $this->getAuthHeaders('POST', '/v5/order/cancel', [], $settings, $bodyJson),
                'body'    => $bodyJson,
            ]);
            $data    = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;
            if ($retCode !== 0) {
                return ['ok' => false, 'error' => $data['retMsg'] ?? 'Unknown error', 'retCode' => $retCode];
            }
            return ['ok' => true, 'result' => $data['result'] ?? []];
        } catch (\Exception $e) {
            $this->log('cancelOrder Error: ' . $e->getMessage());
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
        $trading    = $this->settingsService->getTradingSettings();
        $posIdx     = $this->getPositionIdx($currentSide);

        try {
            $body = json_encode(['category' => 'linear', 'symbol' => $symbol, 'positionIdx' => $posIdx, 'tpslMode' => 'Full', 'stopLoss' => (string)$price, 'slTriggerBy' => 'MarkPrice', 'slOrderType' => 'Market']);
            $response = $this->requestWithRetry('POST', $baseUrl . '/v5/position/trading-stop', [
                'headers' => $this->getAuthHeaders('POST', '/v5/position/trading-stop', $emptyParams, $settings, $body),
                'body'    => $body,
            ]);
            $data    = $response->toArray(false);
            $retCode = $data['retCode'] ?? -1;

            if ($retCode === self::POSITION_IDX_MISMATCH) {
                $altIdx  = $this->getAlternatePositionIdx($posIdx, $currentSide);
                $body    = json_encode(['category' => 'linear', 'symbol' => $symbol, 'positionIdx' => $altIdx, 'tpslMode' => 'Full', 'stopLoss' => (string)$price, 'slTriggerBy' => 'MarkPrice', 'slOrderType' => 'Market']);
                $this->log("setBreakevenStopLoss retry → positionIdx {$posIdx}→{$altIdx}");
                $response = $this->requestWithRetry('POST', $baseUrl . '/v5/position/trading-stop', [
                    'headers' => $this->getAuthHeaders('POST', '/v5/position/trading-stop', $emptyParams, $settings, $body),
                    'body'    => $body,
                ]);
                $data    = $response->toArray(false);
                $retCode = $data['retCode'] ?? -1;
            }

            if ($retCode === 0) {
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
                $info   = $this->getAccountInfo();
                $mode   = $info['marginMode'] ?? null;
                $modeLabel = match ($mode) {
                    self::MARGIN_REGULAR   => 'Cross',
                    self::MARGIN_ISOLATED  => 'Isolated',
                    self::MARGIN_PORTFOLIO => 'Portfolio',
                    default                => $mode ?? '—',
                };

                // Try to set One-Way mode for new USDT perpetual symbols (does not affect symbols with open positions/orders)
                $positionModeSet = false;
                try {
                    $bodySwitch = json_encode(['category' => 'linear', 'coin' => 'USDT', 'mode' => 0]);
                    $respSwitch = $this->requestWithRetry('POST', $baseUrl . '/v5/position/switch-mode', [
                        'headers' => $this->getAuthHeaders('POST', '/v5/position/switch-mode', [], $settings, $bodySwitch),
                        'body'    => $bodySwitch,
                    ], 1);
                    $dataSwitch = $respSwitch->toArray(false);
                    if (($dataSwitch['retCode'] ?? -1) === 0) {
                        $positionModeSet = true;
                        $this->log('testConnection: position mode set to One-Way for new USDT symbols');
                    }
                } catch (\Throwable $t) {
                    $this->log('testConnection: switch-mode optional: ' . $t->getMessage());
                }

                $msg = sprintf('Bybit подключён. Сдвиг часов: %+d мс. Режим маржи: %s.', $offset, $modeLabel);
                if ($positionModeSet) {
                    $msg .= ' One-Way для новых символов установлен.';
                }
                return [
                    'ok'              => true,
                    'message'         => $msg,
                    'timeOffset'      => $offset,
                    'marginMode'      => $mode,
                    'marginModeLabel' => $modeLabel,
                    'positionModeSet' => $positionModeSet,
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

    private function formatPositions(array $positions, int $fetchedAtMs = 0): array
    {
        if ($fetchedAtMs === 0) {
            $fetchedAtMs = (int)(microtime(true) * 1000);
        }
        return array_values(array_map(function ($pos) use ($fetchedAtMs): array {
            $posIdx = isset($pos['positionIdx']) ? (int)$pos['positionIdx'] : null;
            return [
                'symbol'           => $pos['symbol']        ?? '',
                'side'             => $pos['side']          ?? '',
                'positionIdx'      => $posIdx,
                'size'             => $pos['size']          ?? '0',
                'entryPrice'       => $pos['avgPrice']      ?? '0',
                'markPrice'        => $pos['markPrice']     ?? '0',
                'unrealizedPnl'    => $pos['unrealisedPnl'] ?? '0',
                'curRealisedPnl'   => $pos['curRealisedPnl'] ?? null,  // realised PnL for current holding (incl. funding paid)
                'stopLoss'         => $pos['stopLoss']      ?? null,
                'takeProfit'       => $pos['takeProfit']    ?? null,
                'liquidationPrice' => $pos['liqPrice']      ?? null,
                'leverage'         => $pos['leverage']      ?? '1',
                'openedAt'         => isset($pos['createdTime'])
                    ? date('Y-m-d H:i:s', (int)$pos['createdTime'] / 1000)
                    : date('Y-m-d H:i:s'),
                '_fetched_at_ms'   => $fetchedAtMs,
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
            'execFee'   => isset($t['execFee']) ? (float)$t['execFee'] : null,
            'execType'  => $t['execType']   ?? 'Trade',
            'status'    => $this->mapExecTypeToStatus($t['execType'] ?? 'Trade'),
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
    // Execution Guard helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Fetch a single position by symbol+side. Returns null if not found.
     */
    public function getPositionBySymbol(string $symbol, string $side): ?array
    {
        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return null;
        }

        try {
            $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params   = ['category' => 'linear', 'symbol' => $symbol];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/position/list', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/position/list', $params, $settings),
            ], 2);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0 && isset($data['result']['list'])) {
                $formatted = $this->formatPositions($data['result']['list'], (int)(microtime(true) * 1000));
                foreach ($formatted as $pos) {
                    if (($pos['side'] ?? '') === $side) {
                        return $pos;
                    }
                }
            }
            return null;
        } catch (\Exception $e) {
            $this->log('getPositionBySymbol Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch a historical order by orderId to get fill details (cumExecQty, avgPrice, orderStatus).
     * Uses /v5/order/history — works for filled/cancelled orders.
     */
    public function getOrderFromHistory(string $symbol, string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }

        $settings = $this->settingsService->getBybitSettings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return null;
        }

        try {
            $baseUrl  = $settings['base_url'] ?? 'https://api-testnet.bybit.com';
            $params   = ['category' => 'linear', 'symbol' => $symbol, 'orderId' => $orderId];
            $response = $this->requestWithRetry('GET', $baseUrl . '/v5/order/history', [
                'query'   => $params,
                'headers' => $this->getAuthHeaders('GET', '/v5/order/history', $params, $settings),
            ], 2);
            $data = $response->toArray(false);
            if (($data['retCode'] ?? -1) === 0 && !empty($data['result']['list'])) {
                $o = $data['result']['list'][0];
                return [
                    'orderId'      => $o['orderId']      ?? $orderId,
                    'orderStatus'  => $o['orderStatus']  ?? 'Unknown',
                    'qty'          => (float)($o['qty']          ?? 0),
                    'cumExecQty'   => (float)($o['cumExecQty']   ?? 0),
                    'avgPrice'     => (float)($o['avgPrice']     ?? 0),
                    'leavesQty'    => (float)($o['leavesQty']    ?? 0),
                    'cancelType'   => $o['cancelType']   ?? '',
                    'rejectReason' => $o['rejectReason'] ?? '',
                ];
            }
            return null;
        } catch (\Exception $e) {
            $this->log('getOrderFromHistory Error: ' . $e->getMessage());
            return null;
        }
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
