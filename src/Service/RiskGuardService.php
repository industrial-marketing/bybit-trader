<?php

namespace App\Service;

/**
 * Централизованный контроль рисков.
 *
 * Все проверки выполняются ДО исполнения любого торгового действия.
 * Ни одна из проверок не зависит от LLM — работают независимо.
 */
class RiskGuardService
{
    public function __construct(
        private readonly SettingsService    $settings,
        private readonly BotHistoryService  $botHistory
    ) {}

    // ─────────────────────────────────────────────────────────────────
    // 1. Global kill-switch
    // ─────────────────────────────────────────────────────────────────

    public function isTradingEnabled(): bool
    {
        return (bool)($this->settings->getTradingSettings()['trading_enabled'] ?? true);
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. Daily loss limit
    // ─────────────────────────────────────────────────────────────────

    public function checkDailyLossLimit(): array
    {
        $trading  = $this->settings->getTradingSettings();
        $limitUsd = (float)($trading['daily_loss_limit_usdt'] ?? 0);

        if ($limitUsd <= 0) {
            return ['ok' => true, 'daily_pnl' => null];
        }

        $today  = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $events = $this->botHistory->getRecentEvents(1);

        $dailyPnl = 0.0;
        foreach ($events as $e) {
            if (!str_starts_with($e['timestamp'] ?? '', $today)) {
                continue;
            }
            if (in_array($e['type'] ?? '', ['close_full', 'close_partial', 'auto_open', 'manual_close_full'], true)) {
                $dailyPnl += (float)($e['realizedPnlEstimate'] ?? 0);
            }
        }

        if ($dailyPnl < -$limitUsd) {
            return [
                'ok'        => false,
                'reason'    => 'daily_loss_limit',
                'daily_pnl' => $dailyPnl,
                'message'   => sprintf(
                    'Дневной лимит потерь превышен: %.2f USDT (лимит: −%.2f USDT). Бот на паузе.',
                    $dailyPnl, $limitUsd
                ),
            ];
        }

        return ['ok' => true, 'daily_pnl' => $dailyPnl];
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. Max total exposure
    // ─────────────────────────────────────────────────────────────────

    public function checkMaxExposure(array $positions): array
    {
        $trading     = $this->settings->getTradingSettings();
        $maxExposure = (float)($trading['max_total_exposure_usdt'] ?? 0);

        if ($maxExposure <= 0) {
            return ['ok' => true, 'total_exposure' => null];
        }

        $totalExposure = 0.0;
        foreach ($positions as $p) {
            $size     = (float)($p['size']       ?? 0);
            $entry    = (float)($p['entryPrice'] ?? 0);
            $leverage = max(1, (int)($p['leverage'] ?? 1));
            // Margin used = notional / leverage
            $totalExposure += ($size * $entry) / $leverage;
        }

        if ($totalExposure >= $maxExposure) {
            return [
                'ok'             => false,
                'reason'         => 'max_exposure',
                'total_exposure' => $totalExposure,
                'message'        => sprintf(
                    'Суммарный риск %.2f USDT превышает лимит %.2f USDT. Новые позиции заблокированы.',
                    $totalExposure, $maxExposure
                ),
            ];
        }

        return ['ok' => true, 'total_exposure' => $totalExposure];
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. Action rate-limit (cooldown per symbol)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Возвращает true, если действие над $symbol разрешено (cooldown не истёк).
     *
     * @param array $recentEvents — кешированный массив событий, чтобы не читать файл много раз.
     */
    public function isActionAllowed(string $symbol, array $recentEvents): bool
    {
        $trading  = $this->settings->getTradingSettings();
        $cooldown = (int)($trading['action_cooldown_minutes'] ?? 0);

        if ($cooldown <= 0) {
            return true;
        }

        $now         = new \DateTimeImmutable('now');
        $cooldownSec = $cooldown * 60;
        $tradeTypes  = ['close_full', 'close_partial', 'average_in', 'auto_open', 'manual_close_full'];

        foreach ($recentEvents as $e) {
            if (($e['symbol'] ?? '') !== $symbol) {
                continue;
            }
            if (!in_array($e['type'] ?? '', $tradeTypes, true)) {
                continue;
            }
            try {
                $ts   = new \DateTimeImmutable($e['timestamp'] ?? '');
                $diff = $now->getTimestamp() - $ts->getTimestamp();
                if ($diff < $cooldownSec) {
                    return false;
                }
            } catch (\Exception) {
            }
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. Strict mode (два фазы для опасных действий)
    // ─────────────────────────────────────────────────────────────────

    public function isStrictMode(): bool
    {
        return (bool)($this->settings->getTradingSettings()['bot_strict_mode'] ?? false);
    }

    /** Действия, требующие подтверждения пользователя в строгом режиме. */
    public function isDangerousAction(string $action): bool
    {
        return in_array($action, ['CLOSE_FULL', 'AVERAGE_IN_ONCE'], true);
    }

    // ─────────────────────────────────────────────────────────────────
    // 6. Data freshness guard
    // ─────────────────────────────────────────────────────────────────

    /**
     * Verifies that positions data is not stale.
     *
     * Uses the '_fetched_at_ms' field added by BybitService::getPositions().
     * Threshold is 'max_data_age_sec' from trading settings (0 = disabled, default 30s).
     *
     * Returns ['ok', 'age_sec', 'max_age_sec', 'message'].
     */
    public function checkDataFreshness(array $positions): array
    {
        $trading    = $this->settings->getTradingSettings();
        $maxAgeSec  = (int)($trading['max_data_age_sec'] ?? 30);

        if ($maxAgeSec <= 0) {
            return ['ok' => true, 'age_sec' => null, 'max_age_sec' => 0, 'message' => 'Freshness check disabled.'];
        }

        if (empty($positions)) {
            return ['ok' => true, 'age_sec' => 0.0, 'max_age_sec' => $maxAgeSec, 'message' => 'No positions — freshness N/A.'];
        }

        // Find the oldest fetch timestamp across all positions
        $nowMs       = (int)(microtime(true) * 1000);
        $oldestFetch = $nowMs;
        foreach ($positions as $p) {
            $ts = (int)($p['_fetched_at_ms'] ?? 0);
            if ($ts > 0 && $ts < $oldestFetch) {
                $oldestFetch = $ts;
            }
        }

        // If no position had the field yet (e.g., mock data), skip check
        $hasField = false;
        foreach ($positions as $p) {
            if (isset($p['_fetched_at_ms'])) {
                $hasField = true;
                break;
            }
        }
        if (!$hasField) {
            return ['ok' => true, 'age_sec' => null, 'max_age_sec' => $maxAgeSec, 'message' => 'No freshness metadata on positions.'];
        }

        $ageSec = round(($nowMs - $oldestFetch) / 1000, 2);

        if ($ageSec > $maxAgeSec) {
            return [
                'ok'        => false,
                'reason'    => 'stale_data',
                'age_sec'   => $ageSec,
                'max_age_sec' => $maxAgeSec,
                'message'   => sprintf(
                    'Данные позиций устарели: %.1fс (лимит %dс). Тик пропущен — риск решений на старых ценах.',
                    $ageSec, $maxAgeSec
                ),
            ];
        }

        return [
            'ok'          => true,
            'age_sec'     => $ageSec,
            'max_age_sec' => $maxAgeSec,
            'message'     => sprintf('Data fresh: %.1fs old (limit %ds).', $ageSec, $maxAgeSec),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Сводный статус (для UI)
    // ─────────────────────────────────────────────────────────────────

    public function getRiskStatus(array $positions): array
    {
        $tradingEnabled  = $this->isTradingEnabled();
        $dailyCheck      = $this->checkDailyLossLimit();
        $exposureCheck   = $this->checkMaxExposure($positions);
        $freshnessCheck  = $this->checkDataFreshness($positions);
        $strictMode      = $this->isStrictMode();

        $trading = $this->settings->getTradingSettings();

        $alerts = [];
        if (!$tradingEnabled) {
            $alerts[] = 'Торговля отключена (kill-switch активен).';
        }
        if (!$dailyCheck['ok']) {
            $alerts[] = $dailyCheck['message'];
        }
        if (!$exposureCheck['ok']) {
            $alerts[] = $exposureCheck['message'];
        }
        if (!$freshnessCheck['ok']) {
            $alerts[] = $freshnessCheck['message'];
        }

        return [
            'trading_enabled'        => $tradingEnabled,
            'can_trade'              => $tradingEnabled && $dailyCheck['ok'] && $freshnessCheck['ok'],
            'can_open_new'           => $tradingEnabled && $dailyCheck['ok'] && $exposureCheck['ok'] && $freshnessCheck['ok'],
            'daily_loss_check'       => $dailyCheck,
            'exposure_check'         => $exposureCheck,
            'freshness_check'        => $freshnessCheck,
            'strict_mode'            => $strictMode,
            'daily_loss_limit_usdt'  => (float)($trading['daily_loss_limit_usdt']  ?? 0),
            'max_total_exposure_usdt'=> (float)($trading['max_total_exposure_usdt'] ?? 0),
            'action_cooldown_minutes'=> (int)($trading['action_cooldown_minutes']   ?? 0),
            'max_data_age_sec'       => (int)($trading['max_data_age_sec']          ?? 30),
            'alerts'                 => $alerts,
        ];
    }
}
