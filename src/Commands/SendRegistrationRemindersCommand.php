<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\RegistrationReminderService;
use App\Util\EnvHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SendRegistrationRemindersCommand extends Command
{
    protected static string $defaultName = 'registration:send-reminders';

    public function __construct(
        private readonly RegistrationReminderService $reminderService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('registration:send-reminders');
        $this->setDescription('Send registration reminder mails for events approaching their deadline.');
        $this->setHelp('Enqueues reminder mails for events whose registration deadline is within the configured window.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (EnvHelper::read('FEATURE_REGISTRATION', 'false') !== 'true') {
            $this->logger->debug('Registration reminders skipped: feature disabled.', [
                'event' => 'registration_reminder.skipped_feature_disabled',
            ]);

            if ($output->isVerbose()) {
                $output->writeln('FEATURE_REGISTRATION ist deaktiviert - nichts zu tun.');
            }

            return Command::SUCCESS;
        }

        $baseUrl = trim(EnvHelper::read('APP_URL', ''));
        if ($baseUrl === '') {
            $this->logger->error('Registration reminders skipped: APP_URL not configured.', [
                'event' => 'registration_reminder.missing_base_url',
            ]);
            $output->writeln('<error>APP_URL ist nicht gesetzt - Erinnerungslinks brauchen eine Basis-URL.</error>');

            return Command::FAILURE;
        }

        try {
            $count = $this->reminderService->processDue($baseUrl);
            $this->logger->debug('Registration reminders enqueued.', [
                'event' => 'registration_reminder.enqueued',
                'count' => $count,
            ]);

            if ($output->isVerbose()) {
                $output->writeln(sprintf('%d Erinnerungsmails eingereiht.', $count));
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->logger->error('Registration reminder processing failed.', [
                'event' => 'registration_reminder.process.failed',
                'exception' => $exception,
            ]);

            return Command::FAILURE;
        }
    }
}
