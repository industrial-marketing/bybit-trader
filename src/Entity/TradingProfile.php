<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'trading_profile')]
class TradingProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 50)]
    private string $environment = 'testnet';

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean')]
    private bool $isDefault = false;

    #[ORM\Column(type: 'boolean')]
    private bool $createdByAdmin = false;

    /** Admin must approve profile for bot-tick to run on it */
    #[ORM\Column(type: 'boolean')]
    private bool $isBotApproved = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(mappedBy: 'tradingProfile', targetEntity: ExchangeIntegration::class, cascade: ['persist', 'remove'])]
    private ?ExchangeIntegration $exchangeIntegration = null;

    #[ORM\OneToOne(mappedBy: 'tradingProfile', targetEntity: BotSettings::class, cascade: ['persist', 'remove'])]
    private ?BotSettings $botSettings = null;

    #[ORM\OneToMany(mappedBy: 'tradingProfile', targetEntity: AiProviderConfig::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private \Doctrine\Common\Collections\Collection $aiProviderConfigs;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->aiProviderConfigs = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getExchangeIntegration(): ?ExchangeIntegration
    {
        return $this->exchangeIntegration;
    }

    public function setExchangeIntegration(?ExchangeIntegration $exchangeIntegration): self
    {
        $this->exchangeIntegration = $exchangeIntegration;
        if ($exchangeIntegration !== null && $exchangeIntegration->getTradingProfile() !== $this) {
            $exchangeIntegration->setTradingProfile($this);
        }
        return $this;
    }

    public function getBotSettings(): ?BotSettings
    {
        return $this->botSettings;
    }

    public function setBotSettings(?BotSettings $botSettings): self
    {
        $this->botSettings = $botSettings;
        if ($botSettings !== null && $botSettings->getTradingProfile() !== $this) {
            $botSettings->setTradingProfile($this);
        }
        return $this;
    }

    /** @return \Doctrine\Common\Collections\Collection<int, AiProviderConfig> */
    public function getAiProviderConfigs(): \Doctrine\Common\Collections\Collection
    {
        return $this->aiProviderConfigs;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function setEnvironment(string $environment): self
    {
        $this->environment = $environment;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function isCreatedByAdmin(): bool
    {
        return $this->createdByAdmin;
    }

    public function setCreatedByAdmin(bool $createdByAdmin): self
    {
        $this->createdByAdmin = $createdByAdmin;
        return $this;
    }

    public function isBotApproved(): bool
    {
        return $this->isBotApproved;
    }

    public function setIsBotApproved(bool $isBotApproved): self
    {
        $this->isBotApproved = $isBotApproved;
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
