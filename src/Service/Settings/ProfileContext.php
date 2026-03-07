<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Holds the active trading profile ID for the current request/CLI.
 * When null → use file-based settings (legacy).
 * When set → use database settings for that profile.
 */
class ProfileContext
{
    private ?int $activeProfileId = null;

    public function getActiveProfileId(): ?int
    {
        return $this->activeProfileId;
    }

    public function setActiveProfileId(?int $profileId): self
    {
        $this->activeProfileId = $profileId;
        return $this;
    }

    public function useFileSettings(): bool
    {
        return $this->activeProfileId === null;
    }
}
