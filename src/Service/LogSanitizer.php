<?php

namespace App\Service;

/**
 * Редактирует секретные данные перед записью в логи.
 * Использование: LogSanitizer::log('Prefix', $message, $settingsService);
 */
class LogSanitizer
{
    /**
     * Собирает список секретов из настроек и редактирует их в строке перед error_log.
     */
    public static function log(string $prefix, string $message, ?SettingsService $settings = null): void
    {
        $sanitized = self::sanitize($message, $settings);
        error_log($prefix . ': ' . $sanitized);
    }

    /**
     * Заменяет API-ключи и другие секреты в строке на [REDACTED].
     */
    public static function sanitize(string $text, ?SettingsService $settings = null): string
    {
        $secrets = [];

        if ($settings !== null) {
            $bybit    = $settings->getBybitSettings();
            $chatgpt  = $settings->getChatGPTSettings();
            $deepseek = $settings->getDeepseekSettings();

            foreach ([
                $bybit['api_key']    ?? '',
                $bybit['api_secret'] ?? '',
                $chatgpt['api_key']  ?? '',
                $deepseek['api_key'] ?? '',
            ] as $secret) {
                if (strlen($secret) >= 8) {
                    $secrets[] = $secret;
                }
            }
        }

        // Редактируем переменные окружения напрямую (на случай если settings ещё не инициализированы)
        foreach (['BYBIT_API_KEY', 'BYBIT_API_SECRET', 'CHATGPT_API_KEY', 'DEEPSEEK_API_KEY'] as $envName) {
            $val = $_ENV[$envName] ?? $_SERVER[$envName] ?? '';
            if (strlen($val) >= 8) {
                $secrets[] = $val;
            }
        }

        $secrets = array_unique(array_filter($secrets));

        foreach ($secrets as $secret) {
            // Показываем только первые 4 символа для идентификации
            $visible  = substr($secret, 0, 4);
            $text = str_replace($secret, $visible . '****[REDACTED]', $text);
        }

        // Дополнительно: редактируем типичные паттерны Bearer-токенов
        $text = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/', 'Bearer [REDACTED]', $text);

        return $text;
    }
}
