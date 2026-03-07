<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BotSettings;
use App\Entity\ExchangeIntegration;
use App\Entity\TradingProfile;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $email = trim((string) ($request->request->get('email') ?? ''));
            $name = trim((string) ($request->request->get('name') ?? ''));
            $password = (string) ($request->request->get('password') ?? '');
            $passwordConfirm = (string) ($request->request->get('password_confirm') ?? '');

            $emailConstraint = new Assert\Email();
            $emailViolations = $this->validator->validate($email, [$emailConstraint]);

            if (count($emailViolations) > 0) {
                $error = 'Invalid email address.';
            } elseif (strlen($name) < 2) {
                $error = 'Name must be at least 2 characters.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
            } elseif ($this->userRepository->findByEmail($email) !== null) {
                $error = 'An account with this email already exists.';
            } else {
                $user = new User();
                $user->setEmail($email);
                $user->setName($name);
                $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

                $em = $this->userRepository->getEntityManager();
                $em->persist($user);
                $em->flush();

                // Create default trading profile for new user
                $profile = new TradingProfile();
                $profile->setUser($user);
                $profile->setName('My Profile');
                $profile->setEnvironment('testnet');
                $profile->setIsActive(true);
                $profile->setIsDefault(true);

                $exchange = new ExchangeIntegration();
                $exchange->setTradingProfile($profile);
                $exchange->setTestnetMode(true);

                $botSettings = new BotSettings();
                $botSettings->setTradingProfile($profile);

                $profile->setExchangeIntegration($exchange);
                $profile->setBotSettings($botSettings);

                $em->persist($profile);
                $em->persist($exchange);
                $em->persist($botSettings);
                $em->flush();

                $this->addFlash('success', 'Account created. You can now sign in.');
                return $this->redirectToRoute('login');
            }
        }

        return $this->render('security/register.html.twig', [
            'error' => $error,
            'last_email' => $request->request->get('email', ''),
            'last_name' => $request->request->get('name', ''),
        ]);
    }
}
