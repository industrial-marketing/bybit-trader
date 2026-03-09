<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'memory_entry')]
#[ORM\Index(columns: ['trading_profile_id', 'memory_type'])]
#[ORM\Index(columns: ['trading_profile_id', 'created_at'])]
class MemoryEntry
{
    public const TYPE_TRADE = 'trade';
    public const TYPE_DAILY_SUMMARY = 'daily_summary';
    public const TYPE_DECISION = 'decision';
    public const TYPE_INSIGHT = 'insight';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TradingProfile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TradingProfile $tradingProfile;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $symbol = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $memoryType = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $eventTime;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $sourceEntityId = null;

    #[ORM\Column(type: 'text')]
    private string $textContent = '';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $jsonPayload = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $embedding = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $qualityScore = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $outcomeScore = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tags = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->eventTime = new \DateTimeImmutable();
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

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(?string $symbol): self
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function getMemoryType(): string
    {
        return $this->memoryType;
    }

    public function setMemoryType(string $memoryType): self
    {
        $this->memoryType = $memoryType;
        return $this;
    }

    public function getEventTime(): \DateTimeImmutable
    {
        return $this->eventTime;
    }

    public function setEventTime(\DateTimeImmutable $eventTime): self
    {
        $this->eventTime = $eventTime;
        return $this;
    }

    public function getSourceEntityId(): ?string
    {
        return $this->sourceEntityId;
    }

    public function setSourceEntityId(?string $sourceEntityId): self
    {
        $this->sourceEntityId = $sourceEntityId;
        return $this;
    }

    public function getTextContent(): string
    {
        return $this->textContent;
    }

    public function setTextContent(string $textContent): self
    {
        $this->textContent = $textContent;
        return $this;
    }

    public function getJsonPayload(): ?array
    {
        return $this->jsonPayload;
    }

    public function setJsonPayload(?array $jsonPayload): self
    {
        $this->jsonPayload = $jsonPayload;
        return $this;
    }

    /** @return float[]|null */
    public function getEmbedding(): ?array
    {
        return $this->embedding;
    }

    /** @param float[]|null $embedding */
    public function setEmbedding(?array $embedding): self
    {
        $this->embedding = $embedding;
        return $this;
    }

    public function getQualityScore(): ?float
    {
        return $this->qualityScore;
    }

    public function setQualityScore(?float $qualityScore): self
    {
        $this->qualityScore = $qualityScore;
        return $this;
    }

    public function getOutcomeScore(): ?float
    {
        return $this->outcomeScore;
    }

    public function setOutcomeScore(?float $outcomeScore): self
    {
        $this->outcomeScore = $outcomeScore;
        return $this;
    }

    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function setTags(?array $tags): self
    {
        $this->tags = $tags;
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
