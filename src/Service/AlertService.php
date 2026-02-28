<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends alerts to Telegram and/or a generic webhook (Slack, Discord, etc.)
 * Configuration lives under settings['alerts'].
 */
class AlertService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService    $settingsService
    ) {}

    private function cfg(): array
    {
        return $this->settingsService->getSettings()['alerts'] ?? [];
    }

    // ── Public alert shortcuts ────────────────────────────────────

    public function alertLLMFailure(string $provider, string $error): void
    {
        if ($this->cfg()['on_llm_failure'] ?? true) {
            $this->send('ERROR', "LLM failure ({$provider})", ['error' => mb_substr($error, 0, 300)]);
        }
    }

    public function alertInvalidResponse(string $symbol, string $raw): void
    {
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
            $this->send('ERROR', "Repeated failures for {$symbol}", ['consecutive_errors' => $count]);
        }
    }

    // ── Core send ─────────────────────────────────────────────────

    /**
     * Send to all configured destinations (Telegram, webhook).
     */
    public function send(string $level, string $message, array $context = []): void
    {
        $cfg = $this->cfg();

        $text = sprintf("[%s][BYBIT-BOT][%s] %s", strtoupper($level), date('d.m H:i'), $message);
        foreach ($context as $k => $v) {
            $text .= "\n  {$k}: " . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }

        $token  = $cfg['telegram_bot_token'] ?? '';
        $chatId = $cfg['telegram_chat_id']   ?? '';
        if ($token !== '' && $chatId !== '') {
            try {
                $this->httpClient->request('POST', "https://api.telegram.org/bot{$token}/sendMessage", [
                    'json'    => ['chat_id' => $chatId, 'text' => $text],
                    'timeout' => 5,
                ]);
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
                ]);
            } catch (\Exception $e) {
                LogSanitizer::log('Alert', 'Webhook failed: ' . $e->getMessage(), $this->settingsService);
            }
        }
    }
}
