<?php

declare(strict_types=1);

namespace App\Service\Memory;

use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Daily job: aggregates day's memories, writes daily_summary and insights.
 * Run via app:memory-daily-reflection (cron daily).
 */
class DailyReflectionService
{
    public function __construct(
        private readonly QdrantClientService $qdrant,
        private readonly MemoryWriteService $memoryWrite,
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
    ) {
    }

    /**
     * Run reflection for all profiles with memory_write_enabled.
     * Optionally limit to one profile.
     *
     * @return array{processed: int, written: int, errors: string[]}
     */
    public function run(?int $profileId = null): array
    {
        if (!$this->qdrant->isConfigured()) {
            return ['processed' => 0, 'written' => 0, 'errors' => ['Qdrant not configured']];
        }

        $profiles = $this->getProfilesWithMemoryWrite($profileId);
        $processed = 0;
        $written = 0;
        $errors = [];

        foreach ($profiles as $profile) {
            $this->profileContext->setActiveProfileId($profile->getId());
            $result = $this->reflectForProfile($profile);
            $processed++;
            if ($result['ok']) {
                $written++;
            } elseif ($result['error'] !== null) {
                $errors[] = "Profile {$profile->getId()}: " . $result['error'];
            }
        }

        $this->profileContext->setActiveProfileId(null);
        return ['processed' => $processed, 'written' => $written, 'errors' => $errors];
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    private function reflectForProfile(TradingProfile $profile): array
    {
        $yesterdayStart = (new \DateTimeImmutable('yesterday'))->setTime(0, 0, 0)->format('c');
        $yesterdayEnd = (new \DateTimeImmutable('yesterday'))->setTime(23, 59, 59)->format('c');

        $allPoints = [];
        $offset = null;
        do {
            $filter = [
                'profile_id' => $profile->getId(),
                'memory_type' => ['trade', 'decision'],
                'created_at_gte' => $yesterdayStart,
                'created_at_lte' => $yesterdayEnd,
            ];
            $page = $this->qdrant->scroll($filter, 200, $offset);
            foreach ($page['points'] as $p) {
                $allPoints[] = $p;
            }
            $offset = $page['next_offset'];
        } while ($offset !== null && count($page['points']) >= 200);

        if (empty($allPoints)) {
            return ['ok' => true, 'error' => null]; // nothing to summarize
        }

        $lines = [];
        $outcomes = [];
        foreach ($allPoints as $p) {
            $payload = $p['payload'] ?? [];
            $text = (string) ($payload['text_content'] ?? '');
            if ($text !== '') {
                $lines[] = $text;
            }
            $jp = $payload['json_payload'] ?? [];
            $outcome = $jp['outcome'] ?? null;
            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        $summaryText = 'Daily summary. ' . count($lines) . ' events. ';
        $summaryText .= implode(' ', array_slice($lines, 0, 15));
        $summaryText = mb_substr($summaryText, 0, 1500);

        $jsonPayload = [
            'event_count' => count($allPoints),
            'outcomes' => array_count_values($outcomes),
        ];

        $ok = $this->memoryWrite->createDailySummaryMemory($profile, $summaryText, $jsonPayload, null);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Failed to write daily summary'];
        }

        // Optional: extract 1–2 insights from outcomes
        $goodCount = ($jsonPayload['outcomes']['good'] ?? 0) + ($jsonPayload['outcomes']['neutral'] ?? 0);
        $badCount = $jsonPayload['outcomes']['bad'] ?? 0;
        if ($badCount > $goodCount && $badCount >= 2) {
            $this->memoryWrite->createInsightMemory(
                $profile,
                'Several losing trades in one day. Consider reducing size or waiting for clearer setup.',
                null
            );
        } elseif ($goodCount >= 2 && $badCount === 0) {
            $this->memoryWrite->createInsightMemory(
                $profile,
                'Successful day with multiple winning trades. Conditions aligned well.',
                null
            );
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return TradingProfile[]
     */
    private function getProfilesWithMemoryWrite(?int $profileId): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('p')
            ->from(TradingProfile::class, 'p')
            ->leftJoin('p.botSettings', 'b')
            ->where('b.id IS NOT NULL');

        if ($profileId !== null) {
            $qb->andWhere('p.id = :pid')->setParameter('pid', $profileId);
        }

        /** @var TradingProfile[] $profiles */
        $profiles = $qb->getQuery()->getResult();
        $result = [];

        foreach ($profiles as $p) {
            $bot = $p->getBotSettings();
            if ($bot === null) {
                continue;
            }
            $strategies = $bot->getStrategySettings() ?? [];
            if ((bool) ($strategies['memory_write_enabled'] ?? false)) {
                $result[] = $p;
            }
        }

        return $result;
    }
}
