<?php

declare(strict_types=1);

namespace App\Service\Memory;

use App\Service\Settings\ProfileContext;
use App\Service\SettingsService;

/**
 * Retrieves relevant memories from Qdrant via semantic search.
 * Used only when memory_enabled in profile settings.
 */
class MemoryRetrievalService
{
    public function __construct(
        private readonly QdrantClientService $qdrant,
        private readonly EmbeddingService $embeddingService,
        private readonly SettingsService $settingsService,
        private readonly ProfileContext $profileContext,
    ) {
    }

    public function isEnabled(): bool
    {
        $strategies = $this->settingsService->getStrategiesSettings();
        return (bool) ($strategies['memory_enabled'] ?? false);
    }

    /**
     * Find relevant memories for current profile context. Returns [] when memory disabled or no profile.
     *
     * @return array<array{entry: MemoryEntryDto, score: float}>
     */
    public function findForCurrentContext(string $queryText, ?string $symbol = null): array
    {
        $profileId = $this->profileContext->getActiveProfileId();
        if ($profileId === null) {
            return [];
        }
        return $this->findRelevantMemories($profileId, $queryText, $symbol, null);
    }

    /**
     * Find top-K relevant memories for the given context.
     *
     * @return array<array{entry: MemoryEntryDto, score: float}>
     */
    public function findRelevantMemories(
        int $profileId,
        string $queryText,
        ?string $symbol = null,
        ?int $limit = null
    ): array {
        if (!$this->isEnabled() || !$this->qdrant->isConfigured()) {
            return [];
        }

        $strategies = $this->settingsService->getStrategiesSettings();
        $topK = $limit ?? (int) ($strategies['memory_top_k'] ?? 5);
        $lookbackDays = (int) ($strategies['memory_lookback_days'] ?? 90);
        $minScore = (float) ($strategies['memory_min_score'] ?? 0.5);
        $includeCrossSymbol = (bool) ($strategies['memory_include_cross_symbol'] ?? false);
        $includeDaily = (bool) ($strategies['memory_include_daily_summaries'] ?? true);
        $includeInsights = (bool) ($strategies['memory_include_insights'] ?? true);

        $queryEmbedding = $this->embeddingService->embedText($queryText);
        if ($queryEmbedding === null) {
            return [];
        }

        $cutoff = (new \DateTimeImmutable('-' . $lookbackDays . ' days'))->format('c');
        $types = ['trade', 'decision'];
        if ($includeDaily) {
            $types[] = 'daily_summary';
        }
        if ($includeInsights) {
            $types[] = 'insight';
        }

        $filter = [
            'profile_id' => $profileId,
            'memory_type' => $types,
            'created_at_gte' => $cutoff,
        ];
        if ($symbol !== null && $symbol !== '' && !$includeCrossSymbol) {
            $filter['symbol'] = $symbol;
        }

        $hits = $this->qdrant->search($queryEmbedding, $topK, $minScore, $filter);

        $result = [];
        foreach ($hits as $hit) {
            $entry = MemoryEntryDto::fromPayload($hit['payload']);
            $result[] = ['entry' => $entry, 'score' => $hit['score']];
        }

        return $result;
    }

    /**
     * Build compact memory block for LLM prompt.
     *
     * @param array<array{entry: MemoryEntryDto, score: float}> $scoredMemories
     */
    public function buildMemoryPromptBlock(array $scoredMemories, int $maxTokens = 800): string
    {
        if (empty($scoredMemories)) {
            return '';
        }

        $lines = [];
        $approxChars = 0;
        $limit = $maxTokens * 4; // rough 4 chars per token

        foreach ($scoredMemories as $item) {
            $entry = $item['entry'];
            $sym = $entry->getSymbol();
            $text = '- [' . ($sym ?? 'general') . '] ' . $entry->getTextContent();
            $text = mb_substr($text, 0, 200);
            if ($approxChars + mb_strlen($text) > $limit) {
                break;
            }
            $lines[] = $text;
            $approxChars += mb_strlen($text);
        }

        if (empty($lines)) {
            return '';
        }

        return "RELEVANT HISTORICAL CASES:\n" . implode("\n", $lines) . "\n\n";
    }
}
