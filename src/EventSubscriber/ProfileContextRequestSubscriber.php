<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Settings\ProfileContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets ProfileContext.activeProfileId from session for web requests.
 * Session key: active_profile_id. null/0 = file mode, >0 = use that profile from DB.
 */
class ProfileContextRequestSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY = 'active_profile_id';

    public function __construct(
        private readonly ProfileContext $profileContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 32],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session === null) {
            return;
        }

        $profileId = $session->get(self::SESSION_KEY);
        if ($profileId !== null && $profileId !== '' && (int) $profileId > 0) {
            $this->profileContext->setActiveProfileId((int) $profileId);
        }
    }
}
