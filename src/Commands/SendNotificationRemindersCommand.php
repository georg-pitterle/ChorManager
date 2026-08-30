<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\NotificationReminderService;
use App\Util\EnvHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SendNotificationRemindersCommand extends Command
{
    protected static string $defaultName = 'notification:send-reminders';

    public function __construct(
        private readonly NotificationReminderService $reminderService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('notification:send-reminders');
        $this->setDescription('Send reminder mails for tasks and sponsoring follow-ups that are becoming due.');
        $this->setHelp('Enqueues reminders for due dates within the configured windows. Runs hourly via the worker.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Anders als bei der Anmelde-Erinnerung gibt es hier kein einzelnes
        // Feature-Flag: Die Anlaesse haengen an verschiedenen Modulen, und ob
        // einer davon laeuft, entscheidet der Dienst je Anlass.
        $baseUrl = trim(EnvHelper::read('APP_URL', ''));
        if ($baseUrl === '') {
            $this->logger->error('Notification reminders skipped: APP_URL not configured.', [
                'event' => 'notification_reminder.missing_base_url',
            ]);
            $output->writeln('<error>APP_URL ist nicht gesetzt - die Links in den Mails brauchen eine Basis-URL.</error>');

            return Command::FAILURE;
        }

        try {
            $count = $this->reminderService->processDue($baseUrl);
            $this->logger->debug('Notification reminders enqueued.', [
                'event' => 'notification_reminder.enqueued',
                'count' => $count,
            ]);

            if ($output->isVerbose()) {
                $output->writeln(sprintf('%d Erinnerungsmails eingereiht.', $count));
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->logger->error('Notification reminder processing failed.', [
                'event' => 'notification_reminder.process.failed',
                'exception' => $exception,
            ]);

            return Command::FAILURE;
        }
    }
}
