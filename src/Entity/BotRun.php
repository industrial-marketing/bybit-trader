<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bot_run')]
#[ORM\UniqueConstraint(name: 'uniq_profile_bucket', columns: ['trading_profile_id', 'timeframe_bucket'])]
#[ORM\Index(columns: ['trading_profile_id', 'created_at'])]
class BotRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_CRASHED = 'crashed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TradingProfile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TradingProfile $tradingProfile;

    #[ORM\Column(type: 'string', length: 64)]
    private string $runId = '';

    #[ORM\Column(type: 'string', length: 32)]
    private string $timeframeBucket = '';

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTradingProfile(): TradingProfile
    {
        return $this->tradingProfile;
    }

    public function setTradingProfile(TradingProfile $tradingProfile): self
    {
        $this->tradingProfile = $tradingProfile;
        return $this;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function setRunId(string $runId): self
    {
        $this->runId = $runId;
        return $this;
    }

    public function getTimeframeBucket(): string
    {
        return $this->timeframeBucket;
    }

    public function setTimeframeBucket(string $timeframeBucket): self
    {
        $this->timeframeBucket = $timeframeBucket;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
