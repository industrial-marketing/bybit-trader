<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\AtomicFileStorage;
use Psr\Log\LoggerInterface;

/**
 * Sends periodic Telegram updates: summary of trades, balance changes, opened/closed positions.
 * Uses AlertService for delivery; builds summary from BotHistory + Bybit balance.
 */
class TelegramUpdateService
{
    private const STATE_FILE = 'telegram_update_state.json';

    public function __construct(
        private readonly AlertService $alertService,
        private readonly BotHistoryService $botHistory,
        private readonly BybitService $bybitService,
        private readonly SettingsService $settingsService,
        private readonly ProfileContext $profileContext,
        private readonly EntityManagerInterface $em,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Run periodic update if interval elapsed. Sends to Telegram for all configured profiles.
     *
     * @return array{sent: bool, profiles: int, error?: string}
     */
    public function runIfDue(): array
    {
        $cfg = $this->settingsService->getSettings()['alerts'] ?? [];
        if (!($cfg['update_enabled'] ?? false)) {
            return ['sent' => false, 'profiles' => 0];
        }

        $token = $cfg['telegram_bot_token'] ?? '';
        $chatId = $cfg['telegram_chat_id'] ?? '';
        if ($token === '' || $chatId === '') {
            return ['sent' => false, 'profiles' => 0, 'error' => 'Telegram not configured'];
        }

        $intervalMin = max(15, (int)($cfg['update_interval_minutes'] ?? 60));
        $state = $this->loadState();
        $now = time();
        $lastSent = $state['lastSentAt'] ?? 0;
        if ($lastSent > 0 && ($now - $lastSent) < $intervalMin * 60) {
            return ['sent' => false, 'profiles' => 0];
        }

        $profiles = $this->getProfilesForUpdate();

        $since = new \DateTimeImmutable('-' . ceil($intervalMin / 60.0) . ' hours');
        if ($lastSent > 0) {
            $since = new \DateTimeImmutable('@' . $lastSent);
        }

        $sections = [];
        if (empty($profiles)) {
            $cbStatus = $this->circuitBreaker->getStatus();
            $llmOk = !($cbStatus['breakers']['llm']['open'] ?? false) && !($cbStatus['breakers']['llm_invalid']['open'] ?? false);
            $sections[] = "Нет активных профилей.\n" . ($llmOk ? '🤖 LLM: активен' : '⚠️ LLM: не активен');
        }
        foreach ($profiles as $profile) {
            $this->profileContext->setActiveProfileId($profile->getId());
            $section = $this->buildProfileSummary($profile, $since);
            if ($section !== '') {
                $sections[] = $section;
            }
        }
        $this->profileContext->setActiveProfileId(null);

        if (empty($sections)) {
            $text = "📊 *Периодическая сводка* ({$since->format('d.m H:i')} — " . date('d.m H:i') . ")\n\nНет данных для отчёта.";
        } else {
            $header = "📊 *Периодическая сводка* ({$since->format('d.m H:i')} — " . date('d.m H:i') . ")";
            $text = $header . "\n\n" . implode("\n\n---\n\n", $sections);
        }

        // Send via AlertService – but we need to send raw text. AlertService.buildText adds [INFO] etc.
        // Use a custom send or extend AlertService. For now, send directly like AlertService does.
        $sent = $this->sendRawToTelegram($token, $chatId, $text);

        if ($sent && !empty($profiles)) {
            $this->saveState($now, $profiles);
        } elseif ($sent) {
            $state = $this->loadState();
            $state['lastSentAt'] = $now;
            AtomicFileStorage::write($this->getStatePath(), $state);
        }

        return [
            'sent' => $sent,
            'profiles' => count($profiles),
            'error' => $sent ? null : 'Failed to send to Telegram',
        ];
    }

    /**
     * Force send now (e.g. from API or manual trigger).
     */
    public function sendNow(): array
    {
        $cfg = $this->settingsService->getSettings()['alerts'] ?? [];
        $intervalMin = max(15, (int)($cfg['update_interval_minutes'] ?? 60));
        $since = new \DateTimeImmutable("-{$intervalMin} minutes");

        $token = $cfg['telegram_bot_token'] ?? '';
        $chatId = $cfg['telegram_chat_id'] ?? '';
        if ($token === '' || $chatId === '') {
            return ['sent' => false, 'error' => 'Telegram not configured'];
        }

        $profiles = $this->getProfilesForUpdate();
        $sections = [];
        if (empty($profiles)) {
            $cbStatus = $this->circuitBreaker->getStatus();
            $llmOk = !($cbStatus['breakers']['llm']['open'] ?? false) && !($cbStatus['breakers']['llm_invalid']['open'] ?? false);
            $sections[] = "Нет активных профилей.\n" . ($llmOk ? '🤖 LLM: активен' : '⚠️ LLM: не активен');
        }
        foreach ($profiles as $profile) {
            $this->profileContext->setActiveProfileId($profile->getId());
            $section = $this->buildProfileSummary($profile, $since);
            if ($section !== '') {
                $sections[] = $section;
            }
        }
        $this->profileContext->setActiveProfileId(null);

        $text = "📊 *Сводка за последние {$intervalMin} мин*\n\n" . implode("\n\n---\n\n", $sections);

        $sent = $this->sendRawToTelegram($token, $chatId, $text);
        if ($sent && !empty($profiles)) {
            $this->saveState(time(), $profiles);
        } elseif ($sent) {
            $state = $this->loadState();
            $state['lastSentAt'] = time();
            AtomicFileStorage::write($this->getStatePath(), $state);
        }

        return ['sent' => $sent, 'profiles' => count($profiles), 'error' => $sent ? null : 'Send failed'];
    }

    private function buildProfileSummary(TradingProfile $profile, \DateTimeImmutable $since): string
    {
        $events = $this->botHistory->getRecentEvents(7);
        $events = array_filter($events, fn(array $e): bool => isset($e['timestamp']) && (new \DateTimeImmutable($e['timestamp'])) >= $since);

        $balance = $this->bybitService->getBalance();
        $state = $this->loadState();
        $profileKey = 'p' . $profile->getId();
        $lastBalance = $state['byProfile'][$profileKey]['lastBalance'] ?? null;

        $lines = ["*{$profile->getName()}* (ID: {$profile->getId()})"];

        $cbStatus = $this->circuitBreaker->getStatus();
        $llmOk = !($cbStatus['breakers']['llm']['open'] ?? false) && !($cbStatus['breakers']['llm_invalid']['open'] ?? false);
        if ($llmOk) {
            $lines[] = '🤖 LLM: активен';
        } else {
            $remLlm = ($cbStatus['breakers']['llm']['open'] ?? false) ? ($cbStatus['breakers']['llm']['remaining_human'] ?? null) : null;
            $remInv = ($cbStatus['breakers']['llm_invalid']['open'] ?? false) ? ($cbStatus['breakers']['llm_invalid']['remaining_human'] ?? null) : null;
            $remaining = $remLlm ?? $remInv;
            $lines[] = '⚠️ LLM: не активен' . ($remaining ? " (осталось ~{$remaining})" : '');
        }

        // Balance + positions
        $equity = round($balance['totalEquity'] ?? 0, 2);
        $unrealized = round($balance['unrealisedPnl'] ?? 0, 2);
        $positions = $this->bybitService->getPositions();
        $posCount = count($positions);
        $lines[] = "💰 Баланс: {$equity} USDT (нереал. PnL: " . ($unrealized >= 0 ? '+' : '') . "{$unrealized}) | Позиций: {$posCount}";

        if ($lastBalance !== null && is_array($lastBalance)) {
            $prevEquity = (float)($lastBalance['totalEquity'] ?? 0);
            $delta = $equity - $prevEquity;
            $lines[] = "📈 Изменение: " . ($delta >= 0 ? '+' : '') . round($delta, 2) . " USDT";
        }

        if (empty($events)) {
            $lines[] = "Событий за период: нет.";
        } else {
            $closed = $opened = $other = [];
            foreach ($events as $e) {
                $type = $e['type'] ?? '';
                $sym = $e['symbol'] ?? '?';
                $side = $e['side'] ?? '';
                $ts = isset($e['timestamp']) ? (new \DateTimeImmutable($e['timestamp']))->format('H:i') : '';
                $pnl = $e['realizedPnlEstimate'] ?? $e['pnlAtDecision'] ?? null;

                if (in_array($type, ['close_full', 'close_partial', 'close_partial_skip', 'manual_close_full'], true)) {
                    $pnlStr = $pnl !== null ? ' (' . round((float)$pnl, 2) . ' USDT)' : '';
                    $closed[] = "  • {$sym} {$side} closed {$type}{$pnlStr} {$ts}";
                } elseif (in_array($type, ['auto_open', 'average_in', 'confirmed_action'], true)) {
                    $opened[] = "  • {$sym} {$side} {$type} {$ts}";
                } elseif (in_array($type, ['rotational_add_layer', 'rotational_unload_layer'], true)) {
                    $gridLabel = $type === 'rotational_add_layer' ? 'grid add' : 'grid unload';
                    $level = $e['level'] ?? '';
                    $other[] = "  • {$sym} {$side} {$gridLabel}" . ($level ? " @{$level}" : '') . " {$ts}";
                } else {
                    $other[] = "  • {$sym} {$type} {$ts}";
                }
            }

            if (!empty($closed)) {
                $lines[] = "Закрыто:\n" . implode("\n", array_slice($closed, 0, 10));
            }
            if (!empty($opened)) {
                $lines[] = "Открыто / усреднение:\n" . implode("\n", array_slice($opened, 0, 10));
            }
            if (!empty($other)) {
                $lines[] = "Сетка / прочее:\n" . implode("\n", array_slice($other, 0, 10));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return TradingProfile[]
     */
    private function getProfilesForUpdate(): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('p')
            ->from(TradingProfile::class, 'p')
            ->innerJoin('p.exchangeIntegration', 'e')
            ->innerJoin('p.botSettings', 'b')
            ->where('p.isActive = 1')
            ->andWhere('p.isBotApproved = 1');

        $activeProfileId = $this->profileContext->getActiveProfileId();
        if ($activeProfileId !== null) {
            $active = $this->em->find(TradingProfile::class, $activeProfileId);
            if ($active !== null && $active->getUser() !== null) {
                $qb->andWhere('p.user = :user')->setParameter('user', $active->getUser());
            }
        }

        /** @var TradingProfile[] $profiles */
        return $qb->getQuery()->getResult();
    }

    private function loadState(): array
    {
        $path = $this->getStatePath();
        return AtomicFileStorage::read($path);
    }

    private function saveState(int $lastSentAt, array $profiles): void
    {
        $state = $this->loadState();
        $state['lastSentAt'] = $lastSentAt;
        $state['byProfile'] ??= [];
        foreach ($profiles as $p) {
            $this->profileContext->setActiveProfileId($p->getId());
            $state['byProfile']['p' . $p->getId()] = [
                'lastBalance' => $this->bybitService->getBalance(),
                'lastSentAt'  => $lastSentAt,
            ];
        }
        $this->profileContext->setActiveProfileId(null);
        AtomicFileStorage::write($this->getStatePath(), $state);
    }

    private function getStatePath(): string
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($this->projectDir . DIRECTORY_SEPARATOR . 'var');
        return rtrim($varDir, '/\\') . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    private function sendRawToTelegram(string $token, string $chatId, string $text): bool
    {
        try {
            $this->alertService->sendRawText($token, $chatId, $text);
            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Telegram update send failed: ' . $e->getMessage());
            return false;
        }
    }
}
