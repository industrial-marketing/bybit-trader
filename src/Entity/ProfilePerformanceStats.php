<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'profile_performance_stats')]
class ProfilePerformanceStats
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TradingProfile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TradingProfile $tradingProfile;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'float')]
    private float $pnl = 0.0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $roi = null;

    #[ORM\Column(type: 'integer')]
    private int $tradesCount = 0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $winRate = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $maxDrawdown = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $extraStatsJson = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
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

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function setPeriodFrom(\DateTimeImmutable $periodFrom): self
    {
        $this->periodFrom = $periodFrom;
        return $this;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function setPeriodTo(\DateTimeImmutable $periodTo): self
    {
        $this->periodTo = $periodTo;
        return $this;
    }

    public function getPnl(): float
    {
        return $this->pnl;
    }

    public function setPnl(float $pnl): self
    {
        $this->pnl = $pnl;
        return $this;
    }

    public function getRoi(): ?float
    {
        return $this->roi;
    }

    public function setRoi(?float $roi): self
    {
        $this->roi = $roi;
        return $this;
    }

    public function getTradesCount(): int
    {
        return $this->tradesCount;
    }

    public function setTradesCount(int $tradesCount): self
    {
        $this->tradesCount = $tradesCount;
        return $this;
    }

    public function getWinRate(): ?float
    {
        return $this->winRate;
    }

    public function setWinRate(?float $winRate): self
    {
        $this->winRate = $winRate;
        return $this;
    }

    public function getMaxDrawdown(): ?float
    {
        return $this->maxDrawdown;
    }

    public function setMaxDrawdown(?float $maxDrawdown): self
    {
        $this->maxDrawdown = $maxDrawdown;
        return $this;
    }

    public function getExtraStatsJson(): ?array
    {
        return $this->extraStatsJson;
    }

    public function setExtraStatsJson(?array $extraStatsJson): self
    {
        $this->extraStatsJson = $extraStatsJson;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
