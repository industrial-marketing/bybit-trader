<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\BotSettings;
use App\Entity\ExchangeIntegration;
use App\Entity\TradingProfile;
use App\Entity\User;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets ProfileContext.activeProfileId from session for web requests.
 * When user is logged in but has no active profile, auto-sets their default profile.
 */
class ProfileContextRequestSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY = 'active_profile_id';

    public function __construct(
        private readonly ProfileContext $profileContext,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run after SessionListener (-128) so session is available for API requests
            KernelEvents::REQUEST => ['onKernelRequest', -129],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->hasSession() ? $request->getSession() : null;

        /** @var User|null $user */
        $user = $this->security->getUser();

        // 1. EXPLICIT profile_id in query — highest priority for profile-scoped bot APIs (strict isolation)
        $path = $request->getPathInfo();
        $profileScopedPaths = [
            '/api/bot/metrics', '/api/bot/decisions', '/api/bot/history', '/api/bot/runs',
            '/api/bot/risk-status', '/api/bot/pending', '/api/bot/circuit-breaker',
            '/api/positions', '/api/orders', '/api/position-plans', '/api/trades',
            '/api/closed-trades', '/api/statistics', '/api/account-info', '/api/balance',
        ];
        $isProfileScoped = false;
        foreach ($profileScopedPaths as $p) {
            if (str_starts_with($path, $p)) {
                $isProfileScoped = true;
                break;
            }
        }
        if ($user !== null && $isProfileScoped) {
            $queryProfileId = $request->query->get('profile_id');
            if ($queryProfileId !== null && $queryProfileId !== '' && (int) $queryProfileId > 0) {
                $profile = $this->em->getRepository(TradingProfile::class)->find((int) $queryProfileId);
                if ($profile !== null && $profile->getUser()->getId() === $user->getId()) {
                    $this->profileContext->setActiveProfileId((int) $queryProfileId);
                    return;
                }
            }
        }

        // 2. Try session (when available)
        $profileId = $session?->get(self::SESSION_KEY);

        if ($profileId !== null && $profileId !== '' && (int) $profileId > 0 && $session !== null) {
            $profile = $this->em->getRepository(TradingProfile::class)->find((int) $profileId);
            // Only use session profile if it belongs to the current user
            if ($profile !== null && $user !== null && $profile->getUser()->getId() === $user->getId()) {
                $this->profileContext->setActiveProfileId((int) $profileId);
                return;
            }
            // Profile belongs to another user — clear it
            $session->remove(self::SESSION_KEY);
        }

        // When anonymous: for /api/bot/tick use ACTIVE_PROFILE_ID or ?profile_id= so cron uses correct profile
        if ($user === null) {
            $path = $request->getPathInfo();
            if (str_starts_with($path, '/api/bot/')) {
                $profileId = $request->query->get('profile_id');
                if ($profileId === null || $profileId === '') {
                    $profileId = $_ENV['ACTIVE_PROFILE_ID'] ?? $_SERVER['ACTIVE_PROFILE_ID'] ?? null;
                }
                if ($profileId !== null && $profileId !== '' && is_numeric($profileId)) {
                    $profile = $this->em->getRepository(TradingProfile::class)->find((int) $profileId);
                    if ($profile !== null && $profile->isBotApproved() && $profile->isActive()) {
                        $this->profileContext->setActiveProfileId((int) $profileId);
                        return;
                    }
                }
            }
            return;
        }

        // Auto-set default profile when user is logged in but has none selected

        $defaultProfile = $this->em->getRepository(TradingProfile::class)->findOneBy(
            ['user' => $user, 'isActive' => true],
            ['isDefault' => 'DESC', 'id' => 'ASC']
        );

        if ($defaultProfile === null) {
            $defaultProfile = $this->em->getRepository(TradingProfile::class)->findOneBy(
                ['user' => $user],
                ['id' => 'ASC']
            );
        }

        if ($defaultProfile === null) {
            $defaultProfile = $this->createDefaultProfile($user);
        }

        if ($defaultProfile !== null) {
            if ($session !== null) {
                $session->set(self::SESSION_KEY, $defaultProfile->getId());
            }
            $this->profileContext->setActiveProfileId($defaultProfile->getId());
        }
    }

    private function createDefaultProfile(User $user): TradingProfile
    {
        $profile = new TradingProfile();
        $profile->setUser($user);
        $profile->setName('My Profile');
        $profile->setEnvironment('testnet');
        $profile->setIsActive(true);
        $profile->setIsDefault(true);

        $exchange = new ExchangeIntegration();
        $exchange->setTradingProfile($profile);
        $exchange->setExchangeName(ExchangeIntegration::EXCHANGE_BYBIT);
        $exchange->setTestnetMode(true);

        $botSettings = new BotSettings();
        $botSettings->setTradingProfile($profile);

        $profile->setExchangeIntegration($exchange);
        $profile->setBotSettings($botSettings);

        $this->em->persist($profile);
        $this->em->persist($exchange);
        $this->em->persist($botSettings);
        $this->em->flush();

        return $profile;
    }
}
