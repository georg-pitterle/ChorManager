<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\AppSetting;
use App\Services\MailDeliveryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessMailQueueCommand extends Command
{
    protected static $defaultName = 'mail:process-queue';

    public function __construct(private readonly MailDeliveryService $deliveryService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail:process-queue');
        $this->setDescription('Process pending mail queue entries.');
        $this->setHelp('Processes due mail queue entries with the configured batch size.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) (AppSetting::query()
            ->where('setting_key', 'mailqueue_batch_size')
            ->value('setting_value') ?? 50);

        $batchSize = max(1, $batchSize);

        $output->writeln('Processing mail queue with batch size: ' . $batchSize);

        try {
            $repairedCount = $this->deliveryService->repairStaleSendingEntries();
            $output->writeln('Watchdog repaired stale sending entries: ' . $repairedCount);

            $stats = $this->deliveryService->processDueEntries($batchSize);

            $output->writeln('Sent: ' . $stats['sent']);
            $output->writeln('Skipped: ' . $stats['skipped']);
            $output->writeln('Failed: ' . $stats['failed']);
            $output->writeln('Dead: ' . $stats['dead']);

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>Error processing queue: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
