<?php

declare(strict_types=1);

namespace App\Service\Memory;

use App\Entity\TradingProfile;
use App\Service\SettingsService;

/**
 * Writes memory entries to Qdrant. Only writes when memory_write_enabled in profile settings.
 */
class MemoryWriteService
{
    public function __construct(
        private readonly QdrantClientService $qdrant,
        private readonly EmbeddingService $embeddingService,
        private readonly SettingsService $settingsService,
    ) {
    }

    public function isWriteEnabled(): bool
    {
        $strategies = $this->settingsService->getStrategiesSettings();
        return (bool) ($strategies['memory_write_enabled'] ?? false);
    }

    /**
     * Create trade memory after position close.
     */
    public function createTradeMemory(
        TradingProfile $profile,
        string $symbol,
        string $side,
        array $positionData,
        ?float $realizedPnl,
        string $closeReason,
        ?string $llmReasonSummary = null
    ): bool {
        if (!$this->isWriteEnabled() || !$this->qdrant->isConfigured()) {
            return false;
        }

        $entry = $symbol . ', ' . strtolower($side);
        $entry .= '. Entry ' . ($positionData['entryPrice'] ?? '?');
        $entry .= ', closed ' . ($closeReason === 'close_full' ? 'fully' : 'partially');
        if ($realizedPnl !== null) {
            $entry .= ', PnL ' . ($realizedPnl >= 0 ? '+' : '') . round($realizedPnl, 2) . ' USDT';
        }
        if ($llmReasonSummary !== null && $llmReasonSummary !== '') {
            $entry .= '. ' . mb_substr($llmReasonSummary, 0, 200);
        }

        $outcome = 'neutral';
        if ($realizedPnl !== null) {
            $outcome = $realizedPnl > 0 ? 'good' : ($realizedPnl < -5 ? 'bad' : 'neutral');
        }

        return $this->persistMemory(
            $profile,
            'trade',
            $entry,
            [
                'symbol' => $symbol,
                'side' => $side,
                'realized_pnl' => $realizedPnl,
                'close_reason' => $closeReason,
                'outcome' => $outcome,
            ],
            $symbol,
            $outcome === 'good' ? 0.8 : ($outcome === 'bad' ? 0.4 : 0.6),
            $realizedPnl !== null ? min(1.0, max(-1.0, $realizedPnl / 100)) : null
        );
    }

    /**
     * Create decision memory (LLM recommendation, executed or not).
     */
    public function createDecisionMemory(
        TradingProfile $profile,
        ?string $symbol,
        string $recommendation,
        float $confidence,
        bool $executed,
        ?array $outcomeAfter = null
    ): bool {
        if (!$this->isWriteEnabled() || !$this->qdrant->isConfigured()) {
            return false;
        }

        $entry = ($symbol ?? 'general') . '. ' . $recommendation . ' (conf ' . (int) $confidence . '%)';
        $entry .= '. ' . ($executed ? 'Executed' : 'Not executed');
        if ($outcomeAfter !== null) {
            $entry .= '. Outcome: ' . json_encode($outcomeAfter);
        }

        return $this->persistMemory(
            $profile,
            'decision',
            $entry,
            [
                'symbol' => $symbol,
                'confidence' => $confidence,
                'executed' => $executed,
                'outcome' => $outcomeAfter,
            ],
            $symbol
        );
    }

    /**
     * Create insight/heuristic memory.
     */
    public function createInsightMemory(
        TradingProfile $profile,
        string $insightText,
        ?string $symbol = null
    ): bool {
        if (!$this->isWriteEnabled() || !$this->qdrant->isConfigured()) {
            return false;
        }

        return $this->persistMemory(
            $profile,
            'insight',
            $insightText,
            ['symbol' => $symbol],
            $symbol,
            0.9
        );
    }

    private function persistMemory(
        TradingProfile $profile,
        string $memoryType,
        string $textContent,
        array $jsonPayload,
        ?string $symbol = null,
        ?float $qualityScore = null,
        ?float $outcomeScore = null
    ): bool {
        $embedding = $this->embeddingService->embedText($textContent);
        if ($embedding === null) {
            return false;
        }

        $now = (new \DateTimeImmutable())->format('c');
        $pointId = $profile->getId() . '_' . (int) (microtime(true) * 1000) . '_' . bin2hex(random_bytes(4));

        $payload = [
            'profile_id' => $profile->getId(),
            'symbol' => $symbol ?? '',
            'memory_type' => $memoryType,
            'event_time' => $now,
            'text_content' => $textContent,
            'json_payload' => $jsonPayload,
            'quality_score' => $qualityScore,
            'outcome_score' => $outcomeScore,
            'created_at' => $now,
        ];

        return $this->qdrant->upsertPoints([
            [
                'id' => $pointId,
                'vector' => $embedding,
                'payload' => array_filter($payload, fn ($v) => $v !== null),
            ],
        ]);
    }
}
