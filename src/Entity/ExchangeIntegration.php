<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'exchange_integration')]
class ExchangeIntegration
{
    public const EXCHANGE_BYBIT = 'bybit';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: TradingProfile::class, inversedBy: 'exchangeIntegration', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TradingProfile $tradingProfile;

    #[ORM\Column(type: 'string', length: 50)]
    private string $exchangeName = self::EXCHANGE_BYBIT;

    #[ORM\Column(type: 'string', length: 255)]
    private string $apiKey = '';

    #[ORM\Column(type: 'string', length: 512)]
    private string $apiSecret = '';

    #[ORM\Column(type: 'boolean')]
    private bool $testnetMode = true;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $baseUrl = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $extraConfig = null;

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

    public function getExchangeName(): string
    {
        return $this->exchangeName;
    }

    public function setExchangeName(string $exchangeName): self
    {
        $this->exchangeName = $exchangeName;
        return $this;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    public function setApiSecret(string $apiSecret): self
    {
        $this->apiSecret = $apiSecret;
        return $this;
    }

    public function isTestnetMode(): bool
    {
        return $this->testnetMode;
    }

    public function setTestnetMode(bool $testnetMode): self
    {
        $this->testnetMode = $testnetMode;
        return $this;
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    public function getExtraConfig(): ?array
    {
        return $this->extraConfig;
    }

    public function setExtraConfig(?array $extraConfig): self
    {
        $this->extraConfig = $extraConfig;
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
