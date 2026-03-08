<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\BotSettings;
use App\Entity\ExchangeIntegration;
use App\Entity\TradingProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class GoogleAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);
                $email = $googleUser->getEmail();
                $googleId = $googleUser->getId();

                if ($email === null || $email === '') {
                    throw new AuthenticationException('Google did not provide an email address.');
                }

                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

                if ($user === null) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setName($googleUser->getName() ?? explode('@', $email)[0]);
                    $user->setRole(User::ROLE_USER);
                    $user->setIsActive(true);
                    $user->setPasswordHash(password_hash(bin2hex(random_bytes(32)), \PASSWORD_BCRYPT));
                    $this->em->persist($user);
                    $this->em->flush();

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

                    $this->em->persist($profile);
                    $this->em->persist($exchange);
                    $this->em->persist($botSettings);
                }

                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add('error', $exception->getMessage());
        return new RedirectResponse($this->router->generate('login'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('login'), Response::HTTP_TEMPORARY_REDIRECT);
    }
}
