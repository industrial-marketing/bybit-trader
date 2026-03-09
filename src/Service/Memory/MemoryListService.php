<?php

declare(strict_types=1);

namespace App\Service\Memory;

/**
 * Lists memory entries from Qdrant for UI/API.
 */
class MemoryListService
{
    public function __construct(
        private readonly QdrantClientService $qdrant,
    ) {
    }

    /**
     * List memory entries with filters.
     *
     * @param array{profile_id?: int, symbol?: string, memory_type?: string, date_from?: string, date_to?: string} $filters
     * @return array<int, array{id: string, profile_id: int, symbol: string, memory_type: string, text_content: string, event_time: string, created_at: string, quality_score: ?float, outcome_score: ?float, json_payload: array}>
     */
    public function list(array $filters = [], int $limit = 100, int $offsetPage = 0): array
    {
        if (!$this->qdrant->isConfigured()) {
            return [];
        }

        $filter = [];
        if (isset($filters['profile_id'])) {
            $filter['profile_id'] = (int) $filters['profile_id'];
        }
        if (isset($filters['symbol']) && $filters['symbol'] !== '') {
            $filter['symbol'] = $filters['symbol'];
        }
        if (isset($filters['memory_type']) && $filters['memory_type'] !== '') {
            $filter['memory_type'] = [$filters['memory_type']];
        }
        if (isset($filters['date_from'])) {
            $filter['created_at_gte'] = $filters['date_from'] . 'T00:00:00+00:00';
        }
        if (isset($filters['date_to'])) {
            $filter['created_at_lte'] = $filters['date_to'] . 'T23:59:59+00:00';
        }

        $scrollLimit = min(500, max(20, $limit));
        $all = [];
        $scrollOffset = null;
        $fetched = 0;
        $toSkip = $offsetPage * $limit;

        do {
            $page = $this->qdrant->scroll($filter, $scrollLimit, $scrollOffset);
            foreach ($page['points'] as $p) {
                if ($fetched < $toSkip) {
                    $fetched++;
                    continue;
                }
                $payload = $p['payload'] ?? [];
                $all[] = [
                    'id' => $p['id'] ?? '',
                    'profile_id' => (int) ($payload['profile_id'] ?? 0),
                    'symbol' => (string) ($payload['symbol'] ?? ''),
                    'memory_type' => (string) ($payload['memory_type'] ?? ''),
                    'text_content' => (string) ($payload['text_content'] ?? ''),
                    'event_time' => (string) ($payload['event_time'] ?? ''),
                    'created_at' => (string) ($payload['created_at'] ?? ''),
                    'quality_score' => isset($payload['quality_score']) ? (float) $payload['quality_score'] : null,
                    'outcome_score' => isset($payload['outcome_score']) ? (float) $payload['outcome_score'] : null,
                    'json_payload' => $payload['json_payload'] ?? [],
                ];
                if (count($all) >= $limit) {
                    break 2;
                }
            }
            $scrollOffset = $page['next_offset'];
            $fetched += count($page['points']);
        } while ($scrollOffset !== null && count($page['points']) > 0);

        usort($all, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $all;
    }
}
