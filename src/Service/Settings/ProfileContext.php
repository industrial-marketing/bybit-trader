<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Holds the active trading profile ID for the current request/CLI.
 * All settings and storage use database (profile-specific). File storage removed.
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
}
