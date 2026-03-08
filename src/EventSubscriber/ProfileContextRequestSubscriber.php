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

        /** @var User|null $user */
        $user = $this->security->getUser();

        $profileId = $session->get(self::SESSION_KEY);

        if ($profileId !== null && $profileId !== '' && (int) $profileId > 0) {
            $profile = $this->em->getRepository(TradingProfile::class)->find((int) $profileId);
            // Only use session profile if it belongs to the current user
            if ($profile !== null && $user !== null && $profile->getUser()->getId() === $user->getId()) {
                $this->profileContext->setActiveProfileId((int) $profileId);
                return;
            }
            // Profile belongs to another user — clear it
            $session->remove(self::SESSION_KEY);
        }

        // Auto-set default profile when user is logged in but has none selected
        if ($user === null) {
            return;
        }

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
            $session->set(self::SESSION_KEY, $defaultProfile->getId());
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
