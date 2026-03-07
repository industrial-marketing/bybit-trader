<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\TradingProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppProfileExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getGlobals(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [
                'app_profiles' => [['id' => 0, 'name' => 'File (var/settings.json)', 'environment' => null]],
                'app_active_profile_id' => 0,
                'app_active_profile_name' => 'File (var/settings.json)',
            ];
        }

        $session = $request->getSession();
        $activeId = $session->get('active_profile_id');

        $profiles = [
            ['id' => 0, 'name' => 'File (var/settings.json)', 'environment' => null],
        ];

        try {
            $dbProfiles = $this->em->getRepository(TradingProfile::class)->findBy(
                ['isActive' => true],
                ['isDefault' => 'DESC', 'name' => 'ASC']
            );
            foreach ($dbProfiles as $p) {
                $profiles[] = [
                    'id' => $p->getId(),
                    'name' => $p->getName(),
                    'environment' => $p->getEnvironment(),
                ];
            }
        } catch (\Throwable) {
            // DB not available — only file option
        }

        $activeName = 'File (var/settings.json)';
        if ($activeId !== null && $activeId > 0) {
            foreach ($profiles as $p) {
                if (($p['id'] ?? 0) === $activeId) {
                    $activeName = $p['name'] . ($p['environment'] ? " ({$p['environment']})" : '');
                    break;
                }
            }
        }

        return [
            'app_profiles' => $profiles,
            'app_active_profile_id' => $activeId ?? 0,
            'app_active_profile_name' => $activeName,
        ];
    }
}
