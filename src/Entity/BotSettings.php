<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bot_settings')]
class BotSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: TradingProfile::class, inversedBy: 'botSettings', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TradingProfile $tradingProfile;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $riskSettings = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $strategySettings = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $orderSettings = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aiSettings = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $notificationsSettings = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getRiskSettings(): ?array
    {
        return $this->riskSettings;
    }

    public function setRiskSettings(?array $riskSettings): self
    {
        $this->riskSettings = $riskSettings;
        return $this;
    }

    public function getStrategySettings(): ?array
    {
        return $this->strategySettings;
    }

    public function setStrategySettings(?array $strategySettings): self
    {
        $this->strategySettings = $strategySettings;
        return $this;
    }

    public function getOrderSettings(): ?array
    {
        return $this->orderSettings;
    }

    public function setOrderSettings(?array $orderSettings): self
    {
        $this->orderSettings = $orderSettings;
        return $this;
    }

    public function getAiSettings(): ?array
    {
        return $this->aiSettings;
    }

    public function setAiSettings(?array $aiSettings): self
    {
        $this->aiSettings = $aiSettings;
        return $this;
    }

    public function getNotificationsSettings(): ?array
    {
        return $this->notificationsSettings;
    }

    public function setNotificationsSettings(?array $notificationsSettings): self
    {
        $this->notificationsSettings = $notificationsSettings;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
