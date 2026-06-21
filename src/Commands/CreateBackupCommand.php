<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\BackupService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateBackupCommand extends Command
{
    protected static string $defaultName = 'backup:create';

    public function __construct(
        private readonly BackupService $backupService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('backup:create');
        $this->setDescription('Create a database backup (manual or automatic).');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Backup type: auto or manual', 'auto');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getOption('type');

        if (!in_array($type, [BackupService::TYPE_MANUAL, BackupService::TYPE_AUTO], true)) {
            $output->writeln('<error>Invalid --type. Allowed values: auto, manual.</error>');
            return Command::INVALID;
        }

        try {
            $metadata = $this->backupService->create($type, null);
            $output->writeln(sprintf('<info>Backup created: %s</info>', $metadata['id']));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->logger->error('CLI backup creation failed.', [
                'event' => 'backup.create.failed',
                'type' => $type,
                'exception' => $exception,
            ]);
            $output->writeln('<error>Backup creation failed: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
