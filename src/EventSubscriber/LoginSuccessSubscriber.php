<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Clears previous user's session data on login to prevent profile/context leakage
 * when switching between accounts (e.g. different Google accounts).
 */
class LoginSuccessSubscriber implements EventSubscriberInterface
{
    private const PROFILE_SESSION_KEY = 'active_profile_id';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => ['onLoginSuccess', 0],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $session->remove(self::PROFILE_SESSION_KEY);
        $session->migrate(true);
    }
}
