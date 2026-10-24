<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\BackupImportLockedException;
use App\Services\BackupPackageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Übernimmt Backup-Pakete, die von Hand in den Backup-Ordner gelegt wurden.
 * Bewusst nur hier und nicht beim Öffnen der Backup-Seite: Ein Web-Request hat
 * ein Zeitlimit, und die Liste rufen auch der Dev-Reset und der nächtliche Lauf auf.
 */
class ImportBackupCommand extends Command
{
    protected static string $defaultName = 'backup:import';

    public function __construct(
        private readonly BackupPackageService $packages,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('backup:import');
        $this->setDescription('Import backup packages (.zip) placed in the backup directory.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $results = $this->packages->importAll();
        } catch (BackupImportLockedException) {
            $output->writeln('<error>Eine andere Übernahme läuft gerade.</error>');

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $this->logger->error('CLI backup import failed.', [
                'event' => 'backup.import.failed',
                'exception' => $exception,
            ]);
            $output->writeln('<error>Übernahme fehlgeschlagen: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($results === []) {
            $output->writeln('Keine Pakete zum Übernehmen gefunden.');

            return Command::SUCCESS;
        }

        $failed = false;
        foreach ($results as $id => $reason) {
            if ($reason === null) {
                $output->writeln('<info>Übernommen: ' . $id . '</info>');
                continue;
            }
            $failed = true;
            $output->writeln('<error>Fehlgeschlagen: ' . $id . ' – ' . $reason . '</error>');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
