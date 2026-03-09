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

    public static function fromPayload(array $payload): self
    {
        return new self(
            $payload['symbol'] ?? null,
            (string) ($payload['text_content'] ?? ''),
        );
    }
}
