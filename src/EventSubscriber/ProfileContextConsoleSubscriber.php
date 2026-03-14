<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Settings\ProfileContext;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets ProfileContext.activeProfileId from ACTIVE_PROFILE_ID env when running CLI commands.
 * When set, SettingsService uses database settings for that profile.
 */
class ProfileContextConsoleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ProfileContext $profileContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleCommandEvent::class => ['onConsoleCommand', 128],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $input = $event->getInput();
        $profileId = $input->getParameterOption('--profile-id', null);
        if ($profileId !== null) {
            $this->profileContext->setActiveProfileId((int) $profileId);
            return;
        }

        $envProfileId = $_ENV['ACTIVE_PROFILE_ID'] ?? $_SERVER['ACTIVE_PROFILE_ID'] ?? '';
        if ($envProfileId !== '' && is_numeric($envProfileId)) {
            $this->profileContext->setActiveProfileId((int) $envProfileId);
        }
    }
}
