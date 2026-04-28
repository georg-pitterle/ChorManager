<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\AppSetting;
use App\Services\MailDeliveryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessMailQueueCommand extends Command
{
    protected static string $defaultName = 'mail:process-queue';

    public function __construct(
        private readonly MailDeliveryService $deliveryService,
        private readonly LoggerInterface $logger
    ) {
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
        $batchSize = (int) (AppSetting::query()
            ->where('setting_key', 'mailqueue_batch_size')
            ->value('setting_value') ?? 50);

        $batchSize = max(1, $batchSize);

        $this->logger->debug(
            'Starting mail queue processing.',
            [
                'event' => 'mail_queue.process.start',
                'batch_size' => $batchSize,
            ]
        );

        try {
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

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Mail queue processing failed.',
                [
                    'event' => 'mail_queue.process.failed',
                    'batch_size' => $batchSize,
                    'exception' => $exception,
                ]
            );

            return Command::FAILURE;
        }
    }
}
