<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Files\FileTrashService;
use App\Services\Files\LocalFileStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Leert abgelaufene Papierkorb-Einträge der Dateiverwaltung. Läuft täglich im
 * Worker; mit --orphans entfernt er zusätzlich gespeicherte Dateien, zu denen
 * es keine Version mehr gibt (typisch nach dem Einspielen eines älteren Backups).
 */
class PurgeFileTrashCommand extends Command
{
    public function __construct(
        private readonly FileTrashService $trash,
        private readonly LocalFileStorage $storage,
        private readonly bool $moduleEnabled,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('files:purge-trash');
        $this->setDescription('Löscht abgelaufene Papierkorb-Einträge der Dateiverwaltung endgültig.');
        $this->addOption('orphans', null, InputOption::VALUE_NONE, 'Auch verwaiste Dateien in der Ablage entfernen.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur berichten, nichts löschen.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->moduleEnabled) {
            $output->writeln('Dateiverwaltung ist ausgeschaltet - nichts zu tun.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('<comment>Probelauf - es wird nichts gelöscht.</comment>');
        } else {
            $result = $this->trash->purgeExpired();
            // Im Worker landet die Ausgabe in der Log-Pipeline; deshalb strukturiert statt als Klartext.
            $this->logger->info('Expired trash entries purged.', [
                'event' => 'files.trash.purge_completed',
                'files' => $result['files'],
                'folders' => $result['folders'],
            ]);
        }

        if ((bool) $input->getOption('orphans')) {
            $orphans = $this->trash->findOrphans($this->storage);
            if ($dryRun) {
                $output->writeln(sprintf('Verwaiste Dateien: %d', count($orphans)));
            } else {
                $output->writeln(sprintf('Verwaiste Dateien entfernt: %d', $this->trash->deleteOrphans($this->storage)));
            }
        }

        return Command::SUCCESS;
    }
}
