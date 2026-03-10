<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TelegramUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telegram-periodic-update',
    description: 'Send periodic Telegram summary (trades, balance) if interval elapsed. Run from cron every 5–15 min.',
)]
class TelegramPeriodicUpdateCommand extends Command
{
    public function __construct(
        private readonly TelegramUpdateService $telegramUpdate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force send now regardless of interval');
        $this->addOption('profile-id', 'p', InputOption::VALUE_REQUIRED, 'Use this profile\'s Telegram config (otherwise file settings)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $result = $force ? $this->telegramUpdate->sendNow() : $this->telegramUpdate->runIfDue();

        if ($result['sent']) {
            $io->success(sprintf('Telegram update sent (%d profiles).', $result['profiles']));
        } elseif ($force && !empty($result['error'])) {
            $io->error($result['error']);
            return Command::FAILURE;
        } else {
            $io->note('Update not sent (interval not elapsed or no Telegram configured).');
        }

        return Command::SUCCESS;
    }
}
