<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'circuit_breaker_state')]
#[ORM\UniqueConstraint(name: 'uniq_profile_type', columns: ['trading_profile_id', 'breaker_type'])]
class CircuitBreakerState
{
    public const TYPE_BYBIT = 'bybit';
    public const TYPE_LLM = 'llm';
    public const TYPE_LLM_INVALID = 'llm_invalid';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TradingProfile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TradingProfile $tradingProfile;

    #[ORM\Column(type: 'string', length: 20)]
    private string $breakerType = '';

    #[ORM\Column(type: 'integer')]
    private int $consecutive = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $trippedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cooldownUntil = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $lastFailureAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getBreakerType(): string
    {
        return $this->breakerType;
    }

    public function setBreakerType(string $breakerType): self
    {
        $this->breakerType = $breakerType;
        return $this;
    }

    public function getConsecutive(): int
    {
        return $this->consecutive;
    }

    public function setConsecutive(int $consecutive): self
    {
        $this->consecutive = $consecutive;
        return $this;
    }

    public function getTrippedAt(): ?int
    {
        return $this->trippedAt;
    }

    public function setTrippedAt(?int $trippedAt): self
    {
        $this->trippedAt = $trippedAt;
        return $this;
    }

    public function getCooldownUntil(): ?int
    {
        return $this->cooldownUntil;
    }

    public function setCooldownUntil(?int $cooldownUntil): self
    {
        $this->cooldownUntil = $cooldownUntil;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getLastFailureAt(): ?int
    {
        return $this->lastFailureAt;
    }

    public function setLastFailureAt(?int $lastFailureAt): self
    {
        $this->lastFailureAt = $lastFailureAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
