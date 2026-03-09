<?php

declare(strict_types=1);

namespace App\Service\Memory;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper over Qdrant REST API for vector storage.
 * Uses OpenAI text-embedding-3-small (1536 dimensions), Cosine distance.
 */
class QdrantClientService
{
    private const DISTANCE = 'Cosine';
    private const DIMENSIONS = 1536;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $host,
        private readonly int $port,
        private readonly string $collection,
        private readonly ?string $apiKey = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->collection !== '';
    }

    /**
     * Ensure collection exists. Creates if not.
     */
    public function ensureCollection(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $url = $this->baseUrl() . '/collections/' . urlencode($this->collection);
        $headers = $this->headers();

        try {
            $response = $this->httpClient->request('GET', $url . '/', $headers);
            if ($response->getStatusCode() === 200) {
                return true; // already exists
            }
        } catch (\Throwable) {
            // not found or error, try create
        }

        try {
            $response = $this->httpClient->request('PUT', $url, [
                'headers' => $headers['headers'] ?? [],
                'json' => [
                    'vectors' => [
                        'size' => self::DIMENSIONS,
                        'distance' => self::DISTANCE,
                    ],
                ],
                'timeout' => 30,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Upsert points. Each point: id (string), vector (float[]), payload (array).
     *
     * @param array<int, array{id: string, vector: float[], payload: array}> $points
     */
    public function upsertPoints(array $points): bool
    {
        if (!$this->isConfigured() || empty($points)) {
            return false;
        }

        $this->ensureCollection();

        $body = [
            'points' => array_map(static fn (array $p) => [
                'id' => $p['id'],
                'vector' => $p['vector'],
                'payload' => $p['payload'],
            ], $points),
        ];

        $url = $this->baseUrl() . '/collections/' . urlencode($this->collection) . '/points';
        $headers = $this->headers();

        try {
            $response = $this->httpClient->request('PUT', $url . '?wait=true', [
                'headers' => $headers['headers'] ?? [],
                'json' => $body,
                'timeout' => 60,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Search similar vectors.
     *
     * @param float[] $vector
     * @param array{profile_id?: int, memory_type?: string[], symbol?: string, created_at_gte?: string} $filter
     * @return array<int, array{id: string, score: float, payload: array}>
     */
    public function search(
        array $vector,
        int $limit = 5,
        ?float $scoreThreshold = null,
        array $filter = []
    ): array {
        if (!$this->isConfigured() || empty($vector)) {
            return [];
        }

        $must = [];
        if (isset($filter['profile_id'])) {
            $must[] = ['key' => 'profile_id', 'match' => ['value' => (int) $filter['profile_id']]];
        }
        if (!empty($filter['memory_type'])) {
            $must[] = ['key' => 'memory_type', 'match' => ['any' => $filter['memory_type']]];
        }
        if (isset($filter['symbol']) && $filter['symbol'] !== '') {
            // include same symbol or general (stored as "")
            $must[] = ['key' => 'symbol', 'match' => ['any' => [$filter['symbol'], '']]];
        }
        if (isset($filter['created_at_gte'])) {
            $must[] = ['key' => 'created_at', 'range' => ['gte' => $filter['created_at_gte']]];
        }

        $body = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
            'with_vector' => false,
        ];
        if ($scoreThreshold !== null) {
            $body['score_threshold'] = $scoreThreshold;
        }
        if (!empty($must)) {
            $body['filter'] = ['must' => $must];
        }

        $url = $this->baseUrl() . '/collections/' . urlencode($this->collection) . '/points/search';
        $headers = $this->headers();

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers['headers'] ?? [],
                'json' => $body,
                'timeout' => 30,
            ]);
            $data = $response->toArray(false);
            $results = $data['result'] ?? [];
            $out = [];
            foreach ($results as $hit) {
                $out[] = [
                    'id' => $hit['id'] ?? '',
                    'score' => (float) ($hit['score'] ?? 0),
                    'payload' => $hit['payload'] ?? [],
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function baseUrl(): string
    {
        $host = trim($this->host);
        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            return rtrim($host, '/') . ':' . $this->port;
        }
        $scheme = (str_contains($host, 'localhost') || preg_match('#^\d+\.\d+\.\d+\.\d+#', $host))
            ? 'http'
            : 'https';
        return $scheme . '://' . $host . ':' . $this->port;
    }

    private function headers(): array
    {
        $h = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $h['api-key'] = $this->apiKey;
        }
        return ['headers' => $h];
    }
}
