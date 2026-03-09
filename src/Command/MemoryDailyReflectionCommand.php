<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Memory\DailyReflectionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:memory-daily-reflection',
    description: 'Daily reflection: aggregate day\'s memories, write daily summaries and insights',
)]
class MemoryDailyReflectionCommand extends Command
{
    public function __construct(
        private readonly DailyReflectionService $dailyReflection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('profile-id', 'p', InputOption::VALUE_REQUIRED, 'Run for single profile only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileId = $input->getOption('profile-id');
        $pid = $profileId !== null ? (int) $profileId : null;

        $result = $this->dailyReflection->run($pid);

        $io->success(sprintf(
            'Processed %d profiles, wrote %d daily summaries.',
            $result['processed'],
            $result['written']
        ));

        if (!empty($result['errors'])) {
            $io->warning('Errors:');
            foreach ($result['errors'] as $err) {
                $io->writeln('  - ' . $err);
            }
        }

        return Command::SUCCESS;
    }
}
