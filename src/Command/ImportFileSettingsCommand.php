<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AiProviderConfig;
use App\Entity\BotSettings;
use App\Entity\ExchangeIntegration;
use App\Entity\TradingProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:import-file-settings',
    description: 'Import var/settings.json into MySQL as admin profile (creates user and profile if needed)',
)]
class ImportFileSettingsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
        private readonly string $adminEmail,
        private readonly string $adminPasswordHash,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('profile-name', null, InputOption::VALUE_OPTIONAL, 'Profile name', 'File Import (Main)')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'Environment', 'testnet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $settingsFile = $this->projectDir . '/var/settings.json';

        if (!is_file($settingsFile)) {
            $io->error('File var/settings.json not found.');
            return Command::FAILURE;
        }

        $content = file_get_contents($settingsFile);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            $io->error('Invalid JSON in var/settings.json');
            return Command::FAILURE;
        }

        $profileName = (string) $input->getOption('profile-name');
        $environment = (string) $input->getOption('environment');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->adminEmail]);
        if (!$user) {
            $user = new User();
            $user->setEmail($this->adminEmail);
            $user->setPasswordHash($this->adminPasswordHash);
            $user->setName('Admin');
            $user->setRole(User::ROLE_ADMIN);
            $user->setIsActive(true);
            $this->em->persist($user);
            $this->em->flush();
            $io->note('Created admin user: ' . $this->adminEmail);
        }

        $profile = new TradingProfile();
        $profile->setUser($user);
        $profile->setName($profileName);
        $profile->setEnvironment($environment);
        $profile->setIsActive(true);
        $profile->setIsDefault(true);
        $profile->setCreatedByAdmin(false);
        $this->em->persist($profile);
        $this->em->flush();

        $bybit = $data['bybit'] ?? [];
        $ex = new ExchangeIntegration();
        $ex->setTradingProfile($profile);
        $ex->setExchangeName(ExchangeIntegration::EXCHANGE_BYBIT);
        $ex->setApiKey($bybit['api_key'] ?? '');
        $ex->setApiSecret($bybit['api_secret'] ?? '');
        $ex->setTestnetMode($bybit['testnet'] ?? true);
        $ex->setBaseUrl($bybit['base_url'] ?? null);
        $this->em->persist($ex);

        $chatgpt = $data['chatgpt'] ?? [];
        if (($chatgpt['api_key'] ?? '') !== '' || ($chatgpt['enabled'] ?? false)) {
            $ac = new AiProviderConfig();
            $ac->setTradingProfile($profile);
            $ac->setProvider(AiProviderConfig::PROVIDER_OPENAI);
            $ac->setModel($chatgpt['model'] ?? 'gpt-4');
            $ac->setApiKey($chatgpt['api_key'] ?? '');
            $ac->setEnabled($chatgpt['enabled'] ?? false);
            $ac->setTimeout($chatgpt['timeout'] ?? 60);
            $ac->setIsDefault(true);
            $this->em->persist($ac);
        }

        $deepseek = $data['deepseek'] ?? [];
        if (($deepseek['api_key'] ?? '') !== '' || ($deepseek['enabled'] ?? false)) {
            $ad = new AiProviderConfig();
            $ad->setTradingProfile($profile);
            $ad->setProvider(AiProviderConfig::PROVIDER_DEEPSEEK);
            $ad->setModel($deepseek['model'] ?? 'deepseek-chat');
            $ad->setApiKey($deepseek['api_key'] ?? '');
            $ad->setEnabled($deepseek['enabled'] ?? false);
            $ad->setTimeout($deepseek['timeout'] ?? 120);
            $ad->setIsDefault(false);
            $this->em->persist($ad);
        }

        $bot = new BotSettings();
        $bot->setTradingProfile($profile);
        $bot->setRiskSettings($data['trading'] ?? null);
        $bot->setStrategySettings($data['strategies'] ?? null);
        $bot->setNotificationsSettings($data['alerts'] ?? null);
        $this->em->persist($bot);

        $this->em->flush();

        $io->success(sprintf(
            'Imported settings to profile ID %d "%s". File-based mode still active. Phase 2 will add profile switching.',
            $profile->getId(),
            $profileName
        ));

        return Command::SUCCESS;
    }
}
