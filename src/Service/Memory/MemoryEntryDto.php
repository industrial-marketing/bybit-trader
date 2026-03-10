<?php

declare(strict_types=1);

namespace App\Service\Memory;

/**
 * DTO for memory entry from Qdrant payload. Used by retrieval + prompt builder.
 */
final class MemoryEntryDto
{
    public function __construct(
        private readonly ?string $symbol,
        private readonly string $textContent,
        private readonly ?string $outcome = null,
    ) {
    }

    public function getSymbol(): ?string
    {
        return $this->symbol === '' ? null : $this->symbol;
    }

    public function getTextContent(): string
    {
        return $this->textContent;
    }

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public static function fromPayload(array $payload): self
    {
        $jp = $payload['json_payload'] ?? [];
        $outcome = $jp['outcome'] ?? null;

        return new self(
            $payload['symbol'] ?? null,
            (string) ($payload['text_content'] ?? ''),
            $outcome !== null && $outcome !== '' ? (string) $outcome : null,
        );
    }
}
