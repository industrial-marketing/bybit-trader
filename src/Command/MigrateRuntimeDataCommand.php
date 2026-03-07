<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BotHistoryEvent;
use App\Entity\BotRun;
use App\Entity\CircuitBreakerState;
use App\Entity\PendingAction;
use App\Entity\PositionLock;
use App\Entity\PositionPlan;
use App\Entity\TradingProfile;
use App\Service\AtomicFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-runtime-data',
    description: 'Migrate runtime data from JSON files (var/*.json) to MySQL for a given profile',
)]
class MigrateRuntimeDataCommand extends Command
{
    private const TTL_PENDING_MINUTES = 60;
    private const BOT_HISTORY_DAYS = 14;
    private const BOT_HISTORY_MAX = 1000;
    private const BOT_RUNS_MAX = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('profile-id', 'p', InputOption::VALUE_REQUIRED, 'Target trading profile ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be migrated without writing')
            ->addOption('backup', null, InputOption::VALUE_NONE, 'Backup JSON files to var/backup/ before migration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileId = (int) ($input->getOption('profile-id') ?? 0);
        $dryRun = (bool) $input->getOption('dry-run');
        $backup = (bool) $input->getOption('backup');

        if ($profileId <= 0) {
            $io->error('Option --profile-id is required and must be a positive integer.');
            return Command::FAILURE;
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
        if ($profile === null) {
            $io->error("Trading profile {$profileId} not found.");
            return Command::FAILURE;
        }

        $varDir = $_ENV['VAR_DIR'] ?? $_SERVER['VAR_DIR'] ?? ($this->projectDir . \DIRECTORY_SEPARATOR . 'var');
        $varDir = rtrim($varDir, '/\\');

        if ($dryRun) {
            $io->note('DRY RUN — no data will be written.');
        }

        $total = 0;

        // 1. Position locks
        $count = $this->migratePositionLocks($varDir, $profile, $dryRun, $io);
        $total += $count;

        // 2. Pending actions (non-expired only)
        $count = $this->migratePendingActions($varDir, $profile, $dryRun, $io);
        $total += $count;

        // 3. Position plans
        $count = $this->migratePositionPlans($varDir, $profile, $dryRun, $io);
        $total += $count;

        // 4. Circuit breaker state
        $count = $this->migrateCircuitBreaker($varDir, $profile, $dryRun, $io);
        $total += $count;

        // 5. Bot history (last 14 days / 1000 events)
        $count = $this->migrateBotHistory($varDir, $profile, $dryRun, $io);
        $total += $count;

        // 6. Bot runs (last 200)
        $count = $this->migrateBotRuns($varDir, $profile, $dryRun, $io);
        $total += $count;

        if ($backup && !$dryRun && $total > 0) {
            $this->backupFiles($varDir, $io);
        }

        $io->success(sprintf(
            'Migrated %d records to profile %d "%s".',
            $total,
            $profileId,
            $profile->getName()
        ));

        return Command::SUCCESS;
    }

    private function migratePositionLocks(string $varDir, TradingProfile $profile, bool $dryRun, SymfonyStyle $io): int
    {
        $path = $varDir . \DIRECTORY_SEPARATOR . 'position_locks.json';
        $data = AtomicFileStorage::read($path);
        if (!is_array($data)) {
            return 0;
        }

        $count = 0;
        foreach ($data as $key => $locked) {
            if (!$locked) {
                continue;
            }
            $parts = explode('|', (string) $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $symbol = strtoupper(trim($parts[0]));
            $side = ucfirst(strtolower(trim($parts[1])));
            if ($symbol === '' || $side === '') {
                continue;
            }

            if ($dryRun) {
                $count++;
                continue;
            }

            $existing = $this->em->getRepository(PositionLock::class)->findOneBy([
                'tradingProfile' => $profile,
                'symbol' => $symbol,
                'side' => $side,
            ]);
            if ($existing !== null) {
                $existing->setLocked(true);
                $existing->touch();
            } else {
                $lock = new PositionLock();
                $lock->setTradingProfile($profile);
                $lock->setSymbol($symbol);
                $lock->setSide($side);
                $lock->setLocked(true);
                $this->em->persist($lock);
            }
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
            $io->writeln("  position_locks: {$count}");
        }
        return $count;
    }

    private function migratePendingActions(string $varDir, TradingProfile $profile, bool $dryRun, SymfonyStyle $io): int
    {
        $path = $varDir . \DIRECTORY_SEPARATOR . 'pending_actions.json';
        $data = AtomicFileStorage::read($path);
        if (!is_array($data)) {
            return 0;
        }

        $cutoff = new \DateTimeImmutable('-' . self::TTL_PENDING_MINUTES . ' minutes');
        $count = 0;

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? '';
            if ($id === '') {
                continue;
            }
            try {
                $created = new \DateTimeImmutable($item['created_at'] ?? '');
            } catch (\Exception) {
                continue;
            }
            if ($created < $cutoff) {
                continue;
            }

            if ($dryRun) {
                $count++;
                continue;
            }

            $existing = $this->em->getRepository(PendingAction::class)->findOneBy([
                'tradingProfile' => $profile,
                'externalId' => $id,
            ]);
            if ($existing !== null) {
                continue;
            }

            $entity = new PendingAction();
            $entity->setTradingProfile($profile);
            $entity->setExternalId($id);
            $entity->setSymbol($item['symbol'] ?? '');
            $entity->setAction($item['action'] ?? '');
            $entity->setPayload($item);
            $entity->setStatus($item['status'] ?? PendingAction::STATUS_PENDING);
            $this->em->persist($entity);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
            $io->writeln("  pending_actions: {$count}");
        }
        return $count;
    }

    private function migratePositionPlans(string $varDir, TradingProfile $profile, bool $dryRun, SymfonyStyle $io): int
    {
        $path = $varDir . \DIRECTORY_SEPARATOR . 'position_plans.json';
        $data = AtomicFileStorage::read($path);
        if (!is_array($data)) {
            return 0;
        }

        $count = 0;
        foreach ($data as $key => $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $parts = explode('|', (string) $key, 2);
            if (count($parts) === 2) {
                $plan['symbol'] = $plan['symbol'] ?? strtoupper(trim($parts[0]));
                $plan['side'] = $plan['side'] ?? ucfirst(strtolower(trim($parts[1])));
            }
            $symbol = strtoupper($plan['symbol'] ?? '');
            $side = ucfirst(strtolower($plan['side'] ?? ''));
            if ($symbol === '' || $side === '') {
                continue;
            }

            if ($dryRun) {
                $count++;
                continue;
            }

            $planData = $plan;
            unset($planData['symbol'], $planData['side']);

            $existing = $this->em->getRepository(PositionPlan::class)->findOneBy([
                'tradingProfile' => $profile,
                'symbol' => $symbol,
                'side' => $side,
            ]);
            if ($existing !== null) {
                $existing->setPlanData($planData);
                $existing->touch();
            } else {
                $entity = new PositionPlan();
                $entity->setTradingProfile($profile);
                $entity->setSymbol($symbol);
                $entity->setSide($side);
                $entity->setPlanData($planData);
                $this->em->persist($entity);
            }
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
            $io->writeln("  position_plans: {$count}");
        }
        return $count;
    }

    private function migrateCircuitBreaker(string $varDir, TradingProfile $profile, bool $dryRun, SymfonyStyle $io): int
    {
        $path = $varDir . \DIRECTORY_SEPARATOR . 'circuit_breaker.json';
        $data = AtomicFileStorage::read($path);
        if (!is_array($data)) {
            return 0;
        }

        $types = [CircuitBreakerState::TYPE_BYBIT, CircuitBreakerState::TYPE_LLM, CircuitBreakerState::TYPE_LLM_INVALID];
        $count = 0;

        foreach ($types as $type) {
            $entry = $data[$type] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $consecutive = (int) ($entry['consecutive'] ?? 0);
            if ($consecutive === 0 && ($entry['tripped_at'] ?? null) === null) {
                continue;
            }

            if ($dryRun) {
                $count++;
                continue;
            }

            $existing = $this->em->getRepository(CircuitBreakerState::class)->findOneBy([
                'tradingProfile' => $profile,
                'breakerType' => $type,
            ]);
            if ($existing !== null) {
                $existing->setConsecutive($consecutive);
                $existing->setTrippedAt(isset($entry['tripped_at']) ? (int) $entry['tripped_at'] : null);
                $existing->setCooldownUntil(isset($entry['cooldown_until']) ? (int) $entry['cooldown_until'] : null);
                $existing->setReason($entry['reason'] ?? null);
                $existing->setLastFailureAt(isset($entry['last_failure_at']) ? (int) $entry['last_failure_at'] : null);
                $existing->touch();
            } else {
                $entity = new CircuitBreakerState();
                $entity->setTradingProfile($profile);
                $entity->setBreakerType($type);
                $entity->setConsecutive($consecutive);
                $entity->setTrippedAt(isset($entry['tripped_at']) ? (int) $entry['tripped_at'] : null);
                $entity->setCooldownUntil(isset($entry['cooldown_until']) ? (int) $entry['cooldown_until'] : null);
                $entity->setReason($entry['reason'] ?? null);
                $entity->setLastFailureAt(isset($entry['last_failure_at']) ? (int) $entry['last_failure_at'] : null);
                $this->em->persist($entity);
            }
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
            $io->writeln("  circuit_breaker: {$count}");
        }
        return $count;
    }

    private function migrateBotHistory(string $varDir, TradingProfile $profile, bool $dryRun, SymfonyStyle $io): int
    {
        $path = $varDir . \DIRECTORY_SEPARATOR . 'bot_history.json';
        $data = AtomicFileStorage::read($path);
        if (!is_array($data)) {
            return 0;
        }

        $since = new \DateTimeImmutable('-' . self::BOT_HISTORY_DAYS . ' days');
        $filtered = [];
        foreach ($data as $e) {
            if (!is_array($e) || empty($e['timestamp'])) {
                continue;
            }
            try {
                $ts = new \DateTimeImmutable($e['timestamp']);
            } catch (\Exception) {
                continue;
            }
            if ($ts >= $since) {
                $filtered[] = $e;
            }
        }
        if (count($filtered) > self::BOT_HISTORY_MAX) {
            $filtered = array_slice($filtered, -self::BOT_HISTORY_MAX);
        }

        $count = 0;
        foreach ($filtered as $e) {
            $eventId = $e['id'] ?? '';
            if ($eventId === '') {
                continue;
            }

            if ($dryRun) {
                $count++;
                continue;
            }

            $existing = $this->em->getRepository(BotHistoryEvent::class)->findOneBy([
                'tradingProfile' => $profile,
                'eventId' => $eventId,
            ]);
            if ($existing !== null) {
                continue;
            }

            $payload = $e;
            unset($payload['id'], $payload['type'], $payload['timestamp']);
            try {
                $createdAt = new \DateTimeImmutable($e['timestamp'] ?? 'now');
            } catch (\Exception) {
                $createdAt = new \DateTimeImmutable();
            }

            $entity = new BotHistoryEvent();
            $entity->setTradingProfile($profile);
            $entity->setType($e['type'] ?? '');
            $entity->setEventId($eventId);
            $entity->setPayload($payload);
            $this->em->persist($entity);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
            $io->writeln("  bot_history: {$count}");
        }
        return $count;
    }

    private function migrateBotRuns(string $varDir, TradingProfile $profile, bool $dryRun, SymfonyStyle $io): int
    {
        $path = $varDir . \DIRECTORY_SEPARATOR . 'bot_runs.json';
        $data = AtomicFileStorage::read($path);
        if (!is_array($data)) {
            return 0;
        }

        if (count($data) > self::BOT_RUNS_MAX) {
            $data = array_slice($data, -self::BOT_RUNS_MAX);
        }

        $count = 0;
        foreach ($data as $r) {
            if (!is_array($r)) {
                continue;
            }
            $runId = $r['run_id'] ?? '';
            $bucket = $r['timeframe_bucket'] ?? '';
            if ($runId === '' || $bucket === '') {
                continue;
            }

            if ($dryRun) {
                $count++;
                continue;
            }

            $existing = $this->em->getRepository(BotRun::class)->findOneBy([
                'tradingProfile' => $profile,
                'timeframeBucket' => $bucket,
            ]);
            if ($existing !== null) {
                $existing->setRunId($runId);
                $existing->setStatus($r['status'] ?? BotRun::STATUS_DONE);
                $existing->setStartedAt($this->parseDateTime($r['started_at'] ?? null));
                $existing->setFinishedAt($this->parseDateTime($r['finished_at'] ?? null));
                $existing->setCreatedAt($this->parseDateTime($r['started_at'] ?? null));
                $count++;
                continue;
            }

            $entity = new BotRun();
            $entity->setTradingProfile($profile);
            $entity->setRunId($runId);
            $entity->setTimeframeBucket($bucket);
            $entity->setStatus($r['status'] ?? BotRun::STATUS_DONE);
            $entity->setStartedAt($this->parseDateTime($r['started_at'] ?? null));
            $entity->setFinishedAt($this->parseDateTime($r['finished_at'] ?? null));
            $entity->setCreatedAt($this->parseDateTime($r['started_at'] ?? null));
            $this->em->persist($entity);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
            $io->writeln("  bot_runs: {$count}");
        }
        return $count;
    }

    private function parseDateTime(?string $value): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable();
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return new \DateTimeImmutable();
        }
    }

    private function backupFiles(string $varDir, SymfonyStyle $io): void
    {
        $backupDir = $varDir . \DIRECTORY_SEPARATOR . 'backup';
        $files = [
            'position_locks.json',
            'pending_actions.json',
            'position_plans.json',
            'circuit_breaker.json',
            'bot_history.json',
            'bot_runs.json',
        ];
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $ts = date('Y-m-d_H-i-s');
        foreach ($files as $f) {
            $path = $varDir . \DIRECTORY_SEPARATOR . $f;
            if (is_file($path)) {
                $dest = $backupDir . \DIRECTORY_SEPARATOR . pathinfo($f, PATHINFO_FILENAME) . "_{$ts}.json";
                copy($path, $dest);
                $io->writeln("  Backup: {$dest}");
            }
        }
    }
}
