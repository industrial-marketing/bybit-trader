<?php

declare(strict_types=1);

namespace App\Service\Memory;

use App\Service\SettingsService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Obtains text embeddings via OpenAI API (text-embedding-3-small).
 * Reuses ChatGPT API key from settings.
 */
class EmbeddingService
{
    private const MODEL = 'text-embedding-3-small';
    private const DIMENSIONS = 1536;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService $settingsService,
    ) {
    }

    /**
     * @return float[]|null Embedding vector or null on failure
     */
    public function embedText(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $cg = $this->settingsService->getChatGPTSettings();
        $ds = $this->settingsService->getDeepseekSettings();

        // Prefer OpenAI (embeddings API), fallback to DeepSeek not supported for embeddings
        $apiKey = !empty($cg['api_key']) && ($cg['enabled'] ?? false) ? $cg['api_key'] : null;
        if ($apiKey === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'input' => mb_substr($text, 0, 8191), // API limit
                ],
                'timeout' => 30,
            ]);
            $data = $response->toArray(false);
            $embedding = $data['data'][0]['embedding'] ?? null;
            return is_array($embedding) ? array_map('floatval', $embedding) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getDimensions(): int
    {
        return self::DIMENSIONS;
    }
}
