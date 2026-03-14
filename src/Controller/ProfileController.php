<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
    ) {
    }

    #[Route('/switch', name: 'api_profile_switch', methods: ['POST'])]
    public function switchProfile(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $profileId = isset($data['profile_id']) ? (int) $data['profile_id'] : null;

        if ($profileId === null || $profileId <= 0) {
            return $this->json(['ok' => false, 'error' => 'profile_id required (file mode removed, use DB profile)'], 400);
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
        if ($profile === null) {
            return $this->json(['ok' => false, 'error' => 'Profile not found'], 404);
        }

        if ($profile->getUser()->getId() !== $user->getId()) {
            return $this->json(['ok' => false, 'error' => 'Access denied'], 403);
        }

        $request->getSession()->set('active_profile_id', $profileId);
        $this->profileContext->setActiveProfileId($profileId);

        return $this->json([
            'ok' => true,
            'profile_id' => $profileId,
            'profile_name' => $profile->getName(),
            'mode' => 'database',
        ]);
    }

    #[Route('/list', name: 'api_profile_list', methods: ['GET'])]
    public function listProfiles(): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->json([], 200);
        }

        $profiles = $this->em->getRepository(TradingProfile::class)->findBy(
            ['user' => $user, 'isActive' => true],
            ['isDefault' => 'DESC', 'name' => 'ASC']
        );

        $list = [];
        foreach ($profiles as $p) {
            $list[] = [
                'id' => $p->getId(),
                'name' => $p->getName(),
                'environment' => $p->getEnvironment(),
            ];
        }

        return $this->json($list);
    }
}
