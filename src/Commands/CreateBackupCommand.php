<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\BackupLockedException;
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
            $this->logger->info('CLI backup created.', [
                'event' => 'backup.create.completed',
                'type' => $type,
                'id' => $metadata['id'],
            ]);

            if ($output->isVerbose()) {
                $output->writeln(sprintf('<info>Backup created: %s</info>', $metadata['id']));
            }

            return Command::SUCCESS;
        } catch (BackupLockedException $exception) {
            // Kein `error`: Gleichzeitigkeit ist kein Defekt, und als Fehler
            // protokolliert stünde das morgen in der Loganalyse wie einer. Der
            // Rückgabewert bleibt FAILURE - in diesem Lauf entstand kein Backup.
            $this->logger->warning('CLI backup skipped, another backup run holds the lock.', [
                'event' => 'backup.create.skipped',
                'type' => $type,
            ]);
            $output->writeln('<comment>Es läuft gerade schon ein Backup-Vorgang.</comment>');

            return Command::FAILURE;
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
