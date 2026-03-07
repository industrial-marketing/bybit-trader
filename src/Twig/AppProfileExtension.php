<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\TradingProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;

class AppProfileExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function getGlobals(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [
                'app_profiles' => [],
                'app_active_profile_id' => 0,
                'app_active_profile_name' => '',
            ];
        }

        /** @var User|null $user */
        $user = $this->security->getUser();
        if ($user === null) {
            return [
                'app_profiles' => [],
                'app_active_profile_id' => 0,
                'app_active_profile_name' => '',
            ];
        }

        $session = $request->getSession();
        $activeId = $session->get('active_profile_id');

        $profiles = [];
        try {
            $dbProfiles = $this->em->getRepository(TradingProfile::class)->findBy(
                ['user' => $user, 'isActive' => true],
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
            // DB not available
        }

        $activeName = '';
        if ($activeId !== null && $activeId > 0) {
            foreach ($profiles as $p) {
                if (($p['id'] ?? 0) === $activeId) {
                    $activeName = $p['name'] . ($p['environment'] ? " ({$p['environment']})" : '');
                    break;
                }
            }
        }
        if ($activeName === '' && !empty($profiles)) {
            $first = $profiles[0];
            $activeName = $first['name'] . ($first['environment'] ? " ({$first['environment']})" : '');
        }

        return [
            'app_profiles' => $profiles,
            'app_active_profile_id' => $activeId ?? 0,
            'app_active_profile_name' => $activeName,
        ];
    }
}
