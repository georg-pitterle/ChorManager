<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\AppSetting;
use App\Services\MailDeliveryService;
use Illuminate\Database\LostConnectionDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessMailQueueCommand extends Command
{
    protected static string $defaultName = 'mail:process-queue';

    /**
     * So lange gilt ein Datenbankausfall als vorübergehend (Neustart des db-Containers beim Deploy)
     * und wird nur als Warnung geloggt. Danach wieder als Fehler, damit das Alerting anschlägt.
     */
    private const OUTAGE_ERROR_AFTER_SECONDS = 300;

    private readonly string $outageMarkerPath;

    /**
     * @param string|null $outageMarkerPath Hält den Beginn eines Datenbankausfalls über die einzelnen
     *                                      Worker-Läufe hinweg fest; jeder Lauf ist ein eigener Prozess.
     */
    public function __construct(
        private readonly MailDeliveryService $deliveryService,
        private readonly LoggerInterface $logger,
        ?string $outageMarkerPath = null
    ) {
        $this->outageMarkerPath = $outageMarkerPath ?? sys_get_temp_dir() . '/chormanager-mail-queue-db-outage';
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail:process-queue');
        $this->setDescription('Process pending mail queue entries.');
        $this->setHelp('Processes due mail queue entries with the configured batch size.');
    }

    protected function execute(InputInterface $input, OutputInterface $_output): int
    {
        // Null, solange die Batch-Größe nicht gelesen werden konnte (z. B. Datenbank beim Start nicht erreichbar).
        $batchSize = null;

        try {
            $batchSize = max(1, (int) (AppSetting::query()
                ->where('setting_key', 'mailqueue_batch_size')
                ->value('setting_value') ?? 50));

            $this->logger->debug(
                'Starting mail queue processing.',
                [
                    'event' => 'mail_queue.process.start',
                    'batch_size' => $batchSize,
                ]
            );

            $repairedCount = $this->deliveryService->repairStaleSendingEntries();
            $this->logger->debug(
                'Stale sending entries repaired by watchdog.',
                [
                    'event' => 'mail_queue.watchdog.repaired',
                    'repaired_count' => $repairedCount,
                ]
            );

            $stats = $this->deliveryService->processDueEntries($batchSize);

            $this->logger->debug(
                'Mail queue processing completed.',
                [
                    'event' => 'mail_queue.process.completed',
                    'batch_size' => $batchSize,
                    'sent' => (int) ($stats['sent'] ?? 0),
                    'skipped' => (int) ($stats['skipped'] ?? 0),
                    'failed' => (int) ($stats['failed'] ?? 0),
                    'dead' => (int) ($stats['dead'] ?? 0),
                ]
            );

            $this->endOutage();

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $context = [
                'event' => 'mail_queue.process.failed',
                'batch_size' => $batchSize,
                'exception' => $exception,
            ];

            if ((new LostConnectionDetector())->causedByLostConnection($exception)) {
                $context['outage_seconds'] = $this->outageSeconds();

                if ($context['outage_seconds'] < self::OUTAGE_ERROR_AFTER_SECONDS) {
                    $context['event'] = 'mail_queue.process.db_unavailable';
                    $this->logger->warning('Mail queue processing skipped, database unavailable.', $context);

                    return Command::FAILURE;
                }
            }

            $this->logger->error('Mail queue processing failed.', $context);

            return Command::FAILURE;
        }
    }

    /**
     * Sekunden seit Beginn des laufenden Datenbankausfalls; beim ersten Fehlschlag wird der Beginn vermerkt.
     */
    private function outageSeconds(): int
    {
        $startedAt = is_file($this->outageMarkerPath) ? (int) file_get_contents($this->outageMarkerPath) : 0;

        if ($startedAt <= 0) {
            $startedAt = time();
            @file_put_contents($this->outageMarkerPath, (string) $startedAt);
        }

        return max(0, time() - $startedAt);
    }

    private function endOutage(): void
    {
        if (is_file($this->outageMarkerPath)) {
            @unlink($this->outageMarkerPath);
        }
    }
}
