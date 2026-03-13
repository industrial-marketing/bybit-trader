<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BotSettings;
use App\Entity\ExchangeIntegration;
use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RequestStack;

#[Route('/profiles')]
class ProfilesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('', name: 'profiles_list', methods: ['GET'])]
    public function list(): Response
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirectToRoute('login');
        }

        $profiles = $this->em->getRepository(TradingProfile::class)->findBy(
            ['user' => $user, 'isActive' => true],
            ['isDefault' => 'DESC', 'name' => 'ASC']
        );

        return $this->render('profiles/list.html.twig', [
            'profiles' => $profiles,
        ]);
    }

    #[Route('/new', name: 'profiles_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirectToRoute('login');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_form', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Invalid request.');
                return $this->redirectToRoute('profiles_create');
            }
            $name = trim((string) ($request->request->get('name') ?? ''));
            $environment = trim((string) ($request->request->get('environment') ?? 'testnet'));

            if (strlen($name) < 2) {
                $this->addFlash('error', 'Profile name must be at least 2 characters.');
                return $this->render('profiles/form.html.twig', [
                    'profile' => null,
                    'name' => $name,
                    'environment' => $environment,
                ]);
            }

            $profile = new TradingProfile();
            $profile->setUser($user);
            $profile->setName($name);
            $profile->setEnvironment(in_array($environment, ['testnet', 'mainnet'], true) ? $environment : 'testnet');
            $profile->setIsActive(true);
            $profile->setIsDefault(false);

            $exchange = new ExchangeIntegration();
            $exchange->setTradingProfile($profile);
            $exchange->setTestnetMode($profile->getEnvironment() === 'testnet');
            $exchange->setBaseUrl($profile->getEnvironment() === 'mainnet' ? 'https://api.bybit.com' : 'https://api-testnet.bybit.com');

            $botSettings = new BotSettings();
            $botSettings->setTradingProfile($profile);

            $profile->setExchangeIntegration($exchange);
            $profile->setBotSettings($botSettings);

            $this->em->persist($profile);
            $this->em->persist($exchange);
            $this->em->persist($botSettings);
            $this->em->flush();

            $this->addFlash('success', 'Profile created. Configure API keys in Settings.');
            return $this->redirectToRoute('profiles_list');
        }

        return $this->render('profiles/form.html.twig', [
            'profile' => null,
            'name' => '',
            'environment' => 'testnet',
        ]);
    }

    #[Route('/{id}/edit', name: 'profiles_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirectToRoute('login');
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($id);
        if ($profile === null || $profile->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'Profile not found.');
            return $this->redirectToRoute('profiles_list');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_form', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Invalid request.');
                return $this->redirectToRoute('profiles_edit', ['id' => $id]);
            }
            $name = trim((string) ($request->request->get('name') ?? ''));
            $environment = trim((string) ($request->request->get('environment') ?? 'testnet'));

            if (strlen($name) < 2) {
                $this->addFlash('error', 'Profile name must be at least 2 characters.');
                return $this->render('profiles/form.html.twig', [
                    'profile' => $profile,
                    'name' => $name,
                    'environment' => $environment,
                ]);
            }

            $profile->setName($name);
            $profile->setEnvironment(in_array($environment, ['testnet', 'mainnet'], true) ? $environment : 'testnet');
            $profile->touch();

            $ex = $profile->getExchangeIntegration();
            if ($ex !== null) {
                $ex->setTestnetMode($profile->getEnvironment() === 'testnet');
                $ex->setBaseUrl($profile->getEnvironment() === 'mainnet' ? 'https://api.bybit.com' : 'https://api-testnet.bybit.com');
                $ex->touch();
            }

            $this->em->flush();

            $this->addFlash('success', 'Profile updated.');
            return $this->redirectToRoute('profiles_list');
        }

        return $this->render('profiles/form.html.twig', [
            'profile' => $profile,
            'name' => $profile->getName(),
            'environment' => $profile->getEnvironment(),
        ]);
    }

    #[Route('/{id}/default', name: 'profiles_set_default', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setDefault(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('profile_default_' . $id, $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('profiles_list');
        }
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirectToRoute('login');
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($id);
        if ($profile === null || $profile->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'Profile not found.');
            return $this->redirectToRoute('profiles_list');
        }

        foreach ($this->em->getRepository(TradingProfile::class)->findBy(['user' => $user]) as $p) {
            $p->setIsDefault($p->getId() === $id);
        }
        $this->em->flush();

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->hasSession()) {
            $request->getSession()->set('active_profile_id', $id);
        }
        $this->profileContext->setActiveProfileId($id);

        $this->addFlash('success', 'Default profile: ' . $profile->getName());
        return $this->redirectToRoute('profiles_list');
    }

    #[Route('/{id}/switch', name: 'profiles_switch', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function switchProfile(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('profile_switch_' . $id, $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('profiles_list');
        }
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirectToRoute('login');
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($id);
        if ($profile === null || $profile->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'Profile not found.');
            return $this->redirectToRoute('profiles_list');
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->hasSession()) {
            $request->getSession()->set('active_profile_id', $profile->getId());
        }
        $this->profileContext->setActiveProfileId($profile->getId());

        $this->addFlash('success', 'Active profile: ' . $profile->getName());
        return $this->redirectToRoute('profiles_list');
    }

    #[Route('/{id}/delete', name: 'profiles_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('profile_delete_' . $id, $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('profiles_list');
        }
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirectToRoute('login');
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($id);
        if ($profile === null || $profile->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'Profile not found.');
            return $this->redirectToRoute('profiles_list');
        }

        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->getSession();
        if ($session !== null && (int) $session->get('active_profile_id') === $id) {
            $session->set('active_profile_id', null);
            $this->profileContext->setActiveProfileId(null);
        }

        $this->em->remove($profile);
        $this->em->flush();

        $this->addFlash('success', 'Profile deleted.');
        return $this->redirectToRoute('profiles_list');
    }
}
