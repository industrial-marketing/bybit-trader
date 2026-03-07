<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TradingProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $usersCount = $this->em->getRepository(User::class)->count([]);
        $profilesCount = $this->em->getRepository(TradingProfile::class)->count([]);
        $approvedCount = $this->em->getRepository(TradingProfile::class)->count(['isBotApproved' => true, 'isActive' => true]);

        return $this->render('admin/dashboard.html.twig', [
            'users_count' => $usersCount,
            'profiles_count' => $profilesCount,
            'approved_count' => $approvedCount,
        ]);
    }

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $users = $this->em->getRepository(User::class)->findBy(
            [],
            ['createdAt' => 'DESC']
        );

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/profiles', name: 'admin_profiles', methods: ['GET'])]
    public function profiles(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $profiles = $this->em->getRepository(TradingProfile::class)->findBy(
            [],
            ['id' => 'DESC'],
            limit: 200
        );

        return $this->render('admin/profiles.html.twig', [
            'profiles' => $profiles,
        ]);
    }

    #[Route('/profiles/{id}/approve', name: 'admin_profile_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approveProfile(Request $request, int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_profile_approve_' . $id, $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('admin_profiles');
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($id);
        if ($profile === null) {
            $this->addFlash('error', 'Profile not found.');
            return $this->redirectToRoute('admin_profiles');
        }

        $profile->setIsBotApproved(true);
        $profile->touch();
        $this->em->flush();

        $this->addFlash('success', "Profile «{$profile->getName()}» approved for bot.");
        return $this->redirectToRoute('admin_profiles');
    }

    #[Route('/profiles/{id}/reject', name: 'admin_profile_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rejectProfile(Request $request, int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_profile_reject_' . $id, $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('admin_profiles');
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($id);
        if ($profile === null) {
            $this->addFlash('error', 'Profile not found.');
            return $this->redirectToRoute('admin_profiles');
        }

        $profile->setIsBotApproved(false);
        $profile->touch();
        $this->em->flush();

        $this->addFlash('success', "Profile «{$profile->getName()}» rejected (bot will not run).");
        return $this->redirectToRoute('admin_profiles');
    }
}
