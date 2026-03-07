<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\BotRun;
use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;

class DbBotRunStorage implements BotRunStorageInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
    ) {
    }

    private function getProfile(): ?TradingProfile
    {
        $profileId = $this->profileContext->getActiveProfileId();
        if ($profileId === null) {
            return null;
        }
        return $this->em->getRepository(TradingProfile::class)->find($profileId);
    }

    public function currentBucket(int $timeframeMinutes): string
    {
        $interval = max(1, $timeframeMinutes) * 60;
        $bucket   = intdiv(time(), $interval) * $interval;
        return date('Y-m-d\TH:i', $bucket);
    }

    public function tryStart(int $timeframeMinutes, int $staleSec): ?string
    {
        $bucket = $this->currentBucket($timeframeMinutes);
        $profile = $this->getProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot start bot run: no active profile in context.');
        }

        $existing = $this->em->getRepository(BotRun::class)->findOneBy([
            'tradingProfile' => $profile,
            'timeframeBucket' => $bucket,
        ]);

        $now = new \DateTimeImmutable();

        if ($existing === null) {
            $runId = uniqid('run_', true);
            $run = new BotRun();
            $run->setTradingProfile($profile);
            $run->setRunId($runId);
            $run->setTimeframeBucket($bucket);
            $run->setStatus(BotRun::STATUS_RUNNING);
            $run->setStartedAt($now);
            $this->em->persist($run);
            $this->em->flush();
            return $runId;
        }

        $status = $existing->getStatus();
        if ($status === BotRun::STATUS_DONE || $status === BotRun::STATUS_SKIPPED) {
            return null;
        }

        if ($status === BotRun::STATUS_RUNNING) {
            $startedAt = $existing->getStartedAt()->getTimestamp();
            if (($now->getTimestamp() - $startedAt) < $staleSec) {
                return null;
            }
            $existing->setStatus(BotRun::STATUS_CRASHED);
            $existing->setFinishedAt($now);
        }

        $runId = uniqid('run_', true);
        $existing->setRunId($runId);
        $existing->setStatus(BotRun::STATUS_RUNNING);
        $existing->setStartedAt($now);
        $existing->setFinishedAt(null);
        $this->em->flush();

        return $runId;
    }

    public function finish(string $runId, string $status = 'done'): void
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return;
        }

        $run = $this->em->getRepository(BotRun::class)->findOneBy([
            'tradingProfile' => $profile,
            'runId' => $runId,
        ]);

        if ($run !== null) {
            $run->setStatus($status);
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    public function getRecentRuns(int $limit = 30): array
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return [];
        }

        $runs = $this->em->getRepository(BotRun::class)->findBy(
            ['tradingProfile' => $profile],
            ['createdAt' => 'DESC'],
            $limit
        );

        $result = [];
        foreach ($runs as $r) {
            $result[] = [
                'run_id' => $r->getRunId(),
                'timeframe_bucket' => $r->getTimeframeBucket(),
                'status' => $r->getStatus(),
                'started_at' => $r->getStartedAt()->format('c'),
                'finished_at' => $r->getFinishedAt()?->format('c'),
            ];
        }
        return $result;
    }

    public function isRunning(int $timeframeMinutes): bool
    {
        $bucket = $this->currentBucket($timeframeMinutes);
        $profile = $this->getProfile();
        if ($profile === null) {
            return false;
        }

        $run = $this->em->getRepository(BotRun::class)->findOneBy([
            'tradingProfile' => $profile,
            'timeframeBucket' => $bucket,
            'status' => BotRun::STATUS_RUNNING,
        ]);

        return $run !== null;
    }
}
