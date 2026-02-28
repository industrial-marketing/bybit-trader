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
    // Сводный статус (для UI)
    // ─────────────────────────────────────────────────────────────────

    public function getRiskStatus(array $positions): array
    {
        $tradingEnabled = $this->isTradingEnabled();
        $dailyCheck     = $this->checkDailyLossLimit();
        $exposureCheck  = $this->checkMaxExposure($positions);
        $strictMode     = $this->isStrictMode();

        $trading        = $this->settings->getTradingSettings();

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

        return [
            'trading_enabled'        => $tradingEnabled,
            'can_trade'              => $tradingEnabled && $dailyCheck['ok'],
            'can_open_new'           => $tradingEnabled && $dailyCheck['ok'] && $exposureCheck['ok'],
            'daily_loss_check'       => $dailyCheck,
            'exposure_check'         => $exposureCheck,
            'strict_mode'            => $strictMode,
            'daily_loss_limit_usdt'  => (float)($trading['daily_loss_limit_usdt']  ?? 0),
            'max_total_exposure_usdt'=> (float)($trading['max_total_exposure_usdt'] ?? 0),
            'action_cooldown_minutes'=> (int)($trading['action_cooldown_minutes']   ?? 0),
            'alerts'                 => $alerts,
        ];
    }
}
