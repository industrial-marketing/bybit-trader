<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends alerts to Telegram and/or a generic webhook (Slack, Discord, etc.)
 * Configuration lives under settings['alerts'].
 */
class AlertService
{
    private const COOLDOWN_FILE = 'alert_repeated_cooldowns.json';

    public function __construct(
        private readonly HttpClientInterface   $httpClient,
        private readonly SettingsService       $settingsService,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly string                $projectDir,
    ) {}

    private function cfg(): array
    {
        return $this->settingsService->getSettings()['alerts'] ?? [];
    }

    // ── Public alert shortcuts ────────────────────────────────────

    public function alertLLMFailure(string $provider, string $error): void
    {
        $this->circuitBreaker->recordFailure(
            CircuitBreakerService::TYPE_LLM,
            "LLM failure ({$provider}): " . mb_substr($error, 0, 100)
        );
        if ($this->cfg()['on_llm_failure'] ?? true) {
            $this->send('ERROR', "LLM failure ({$provider})", ['error' => mb_substr($error, 0, 300)]);
        }
    }

    public function alertInvalidResponse(string $symbol, string $raw): void
    {
        $this->circuitBreaker->recordFailure(
            CircuitBreakerService::TYPE_LLM_INVALID,
            "Invalid LLM response for {$symbol}"
        );
        if ($this->cfg()['on_invalid_response'] ?? true) {
            $this->send('WARN', "Invalid LLM response for {$symbol}", ['raw_preview' => mb_substr($raw, 0, 200)]);
        }
    }

    public function alertRiskLimit(string $reason, array $context = []): void
    {
        if ($this->cfg()['on_risk_limit'] ?? true) {
            $this->send('WARN', "Risk limit: {$reason}", $context);
        }
    }

    public function alertBybitError(string $method, string $error, int $retCode = 0): void
    {
        $this->circuitBreaker->recordFailure(
            CircuitBreakerService::TYPE_BYBIT,
            "Bybit error [{$method}] retCode={$retCode}: " . mb_substr($error, 0, 100)
        );
        if ($this->cfg()['on_bybit_error'] ?? true) {
            $this->send('ERROR', "Bybit API error [{$method}]", [
                'retCode' => $retCode ?: 'n/a',
                'error'   => mb_substr($error, 0, 200),
            ]);
        }
    }

    public function alertRepeatedFailures(string $symbol, int $count): void
    {
        $threshold = (int)($this->cfg()['repeated_failure_threshold'] ?? 3);
        if (($this->cfg()['on_repeated_failures'] ?? true) && $count >= $threshold) {
            $cooldownMin = max(1, (int)($this->cfg()['repeated_failure_cooldown_minutes'] ?? 60));
            if ($this->checkRepeatedCooldown($symbol, $cooldownMin)) {
                return;
            }
            $this->send('ERROR', "Repeated failures for {$symbol}", ['consecutive_errors' => $count]);
            $this->saveRepeatedCooldown($symbol);
        }
    }

    private function getCooldownPath(): string
    {
        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($this->projectDir . DIRECTORY_SEPARATOR . 'var');
        return rtrim($varDir, '/\\') . DIRECTORY_SEPARATOR . self::COOLDOWN_FILE;
    }

    private function checkRepeatedCooldown(string $symbol, int $cooldownMinutes): bool
    {
        $path = $this->getCooldownPath();
        $data = AtomicFileStorage::read($path);
        $key  = 'repeated_' . $symbol;
        $last = $data[$key] ?? null;
        if ($last === null) {
            return false;
        }
        try {
            $lastTs = is_numeric($last) ? (int)$last : strtotime($last);
            return (time() - $lastTs) < $cooldownMinutes * 60;
        } catch (\Throwable) {
            return false;
        }
    }

    private function saveRepeatedCooldown(string $symbol): void
    {
        $path = $this->getCooldownPath();
        $key  = 'repeated_' . $symbol;
        AtomicFileStorage::update($path, function (array $data) use ($key): array {
            $data[$key] = time();
            return $data;
        });
    }

    // ── Core send ─────────────────────────────────────────────────

    /**
     * Send to all configured destinations (Telegram, webhook).
     * Fire-and-forget: errors are only logged, not thrown.
     */
    public function send(string $level, string $message, array $context = []): void
    {
        $cfg  = $this->cfg();
        $text = $this->buildText($level, $message, $context);

        $token  = $cfg['telegram_bot_token'] ?? '';
        $chatId = $cfg['telegram_chat_id']   ?? '';
        if ($token !== '' && $chatId !== '') {
            try {
                $this->httpClient->request('POST', "https://api.telegram.org/bot{$token}/sendMessage", [
                    'json'    => ['chat_id' => $chatId, 'text' => $text],
                    'timeout' => 5,
                ])->getContent(false);
            } catch (\Exception $e) {
                LogSanitizer::log('Alert', 'Telegram failed: ' . $e->getMessage(), $this->settingsService);
            }
        }

        $webhook = $cfg['webhook_url'] ?? '';
        if ($webhook !== '') {
            try {
                $this->httpClient->request('POST', $webhook, [
                    'json'    => ['text' => $text],
                    'timeout' => 5,
                ])->getContent(false);
            } catch (\Exception $e) {
                LogSanitizer::log('Alert', 'Webhook failed: ' . $e->getMessage(), $this->settingsService);
            }
        }
    }

    /**
     * Like send(), but returns ['ok' => bool, 'error' => string] for use in test endpoints.
     * Reads and validates all destination responses.
     */
    public function sendTest(string $message, array $context = []): array
    {
        $cfg  = $this->cfg();
        $text = $this->buildText('INFO', $message, $context);

        $token  = $cfg['telegram_bot_token'] ?? '';
        $chatId = $cfg['telegram_chat_id']   ?? '';
        $webhook = $cfg['webhook_url']       ?? '';

        if ($token === '' && $webhook === '') {
            return ['ok' => false, 'error' => 'Не настроен ни Telegram, ни Webhook. Заполните токен и Chat ID.'];
        }

        $errors = [];

        if ($token !== '' || $chatId !== '') {
            if ($token === '') {
                $errors[] = 'Telegram: не заполнен Bot Token.';
            } elseif ($chatId === '') {
                $errors[] = 'Telegram: не заполнен Chat ID.';
            } else {
                try {
                    $response = $this->httpClient->request('POST', "https://api.telegram.org/bot{$token}/sendMessage", [
                        'json'    => ['chat_id' => $chatId, 'text' => $text],
                        'timeout' => 8,
                    ]);
                    $body = json_decode($response->getContent(false), true);
                    if (!($body['ok'] ?? false)) {
                        $tgError = $body['description'] ?? 'неизвестная ошибка Telegram';
                        $errors[] = "Telegram: {$tgError}";
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Telegram: ' . $e->getMessage();
                }
            }
        }

        if ($webhook !== '') {
            try {
                $response = $this->httpClient->request('POST', $webhook, [
                    'json'    => ['text' => $text],
                    'timeout' => 8,
                ]);
                $status = $response->getStatusCode();
                if ($status >= 400) {
                    $errors[] = "Webhook: HTTP {$status}";
                }
            } catch (\Exception $e) {
                $errors[] = 'Webhook: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'error' => implode('; ', $errors)];
        }

        return ['ok' => true, 'message' => 'Алерт успешно отправлен.'];
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function buildText(string $level, string $message, array $context): string
    {
        $text = sprintf("[%s][BYBIT-BOT][%s] %s", strtoupper($level), date('d.m H:i'), $message);
        foreach ($context as $k => $v) {
            $text .= "\n  {$k}: " . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }
        return $text;
    }
}
