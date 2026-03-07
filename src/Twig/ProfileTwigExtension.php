<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\TradingProfile;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class ProfileTwigExtension extends AbstractExtension implements GlobalsInterface
{
    private const SESSION_KEY = 'active_profile_id';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getGlobals(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return ['app_profiles' => [], 'app_active_profile_id' => null, 'app_active_profile_name' => null];
        }

        $session = $request->getSession();
        $activeId = $session->has(self::SESSION_KEY) ? (int) $session->get(self::SESSION_KEY) : null;

        $profiles = [];
        try {
            $entities = $this->em->getRepository(TradingProfile::class)->findBy(
                ['isActive' => true],
                ['isDefault' => 'DESC', 'name' => 'ASC']
            );
            $profiles[] = ['id' => 0, 'name' => 'File (var/settings.json)', 'environment' => ''];
            foreach ($entities as $p) {
                $profiles[] = [
                    'id' => $p->getId(),
                    'name' => $p->getName(),
                    'environment' => $p->getEnvironment(),
                ];
            }
        } catch (\Throwable) {
            // DB unavailable
        }

        $activeName = 'File (var/settings.json)';
        if ($activeId !== null && $activeId > 0) {
            try {
                $profile = $this->em->getRepository(TradingProfile::class)->find($activeId);
                $activeName = $profile?->getName() ?? $activeName;
            } catch (\Throwable) {
                // ignore
            }
        }

        return [
            'app_profiles' => $profiles,
            'app_active_profile_id' => $activeId ?? 0,
            'app_active_profile_name' => $activeName,
        ];
    }
}
