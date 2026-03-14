<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\TradingProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-profile-environment',
    description: 'Sync profile.environment with exchange base_url (fix mainnet→testnet mismatch)',
)]
class SyncProfileEnvironmentCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profiles = $this->em->getRepository(TradingProfile::class)->findAll();
        $fixed = 0;

        foreach ($profiles as $profile) {
            $ex = $profile->getExchangeIntegration();
            if ($ex === null) {
                continue;
            }
            $baseUrl = $ex->getBaseUrl();
            if ($baseUrl === null || $baseUrl === '') {
                $baseUrl = $ex->isTestnetMode() ? 'https://api-testnet.bybit.com' : 'https://api.bybit.com';
            }
            $shouldBeMainnet = !str_contains($baseUrl, 'testnet');
            $targetEnv = $shouldBeMainnet ? 'mainnet' : 'testnet';
            if ($profile->getEnvironment() !== $targetEnv) {
                $oldEnv = $profile->getEnvironment();
                $profile->setEnvironment($targetEnv);
                $ex->setTestnetMode(!$shouldBeMainnet);
                $ex->touch();
                $profile->touch();
                $fixed++;
                $io->writeln(sprintf(
                    "  Fixed profile #%d '%s': %s → %s (base_url=%s)",
                    $profile->getId(),
                    $profile->getName(),
                    $oldEnv,
                    $targetEnv,
                    $baseUrl
                ));
            }
        }

        if ($fixed > 0) {
            $this->em->flush();
            $io->success("Synced {$fixed} profile(s).");
        } else {
            $io->info('No profiles needed sync.');
        }
        return Command::SUCCESS;
    }
}
