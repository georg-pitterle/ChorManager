<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\UserMailAccount;
use App\Services\MailCredentialCryptoService;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-encrypts stored IMAP passwords with the active MAIL_CREDENTIAL_KEY.
 *
 * Intended to be run once per key rotation while MAIL_CREDENTIAL_KEY holds the
 * new key and MAIL_CREDENTIAL_KEY_PREVIOUS still holds the outgoing one. The
 * run is idempotent: values already sealed with the active key are skipped.
 */
class RotateMailCredentialKeyCommand extends Command
{
    protected static string $defaultName = 'mail:rotate-key';

    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly MailCredentialCryptoService $crypto,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail:rotate-key');
        $this->setDescription('Verschlüsselt gespeicherte IMAP-Passwörter mit dem aktuellen MAIL_CREDENTIAL_KEY neu.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur berichten, nichts schreiben.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $keyId = $this->crypto->keyId();

        if ($dryRun) {
            $output->writeln('<comment>Probelauf — es wird nichts geschrieben.</comment>');
        }

        if (!$this->crypto->hasPreviousKey()) {
            $output->writeln(
                '<comment>MAIL_CREDENTIAL_KEY_PREVIOUS ist nicht gesetzt. '
                . 'Datensätze mit einem älteren Schlüssel können nicht gelesen werden.</comment>'
            );
        }

        $this->logger->info('Mail credential key rotation started.', [
            'event' => 'mail_credential.rotate.started',
            'key_id' => $keyId,
            'dry_run' => $dryRun,
        ]);

        $rewrapped = 0;
        $skipped = 0;
        $failed = 0;

        UserMailAccount::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $accounts) use (
                $dryRun,
                &$rewrapped,
                &$skipped,
                &$failed
            ): void {
                foreach ($accounts as $account) {
                    $result = $this->rewrapAccount($account, $dryRun);

                    if ($result === 'rewrapped') {
                        $rewrapped++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                }
            });

        $output->writeln(sprintf(
            '<info>Schlüssel %s — neu verschlüsselt: %d, übersprungen: %d, fehlgeschlagen: %d</info>',
            $keyId,
            $rewrapped,
            $skipped,
            $failed
        ));

        $this->logger->info('Mail credential key rotation completed.', [
            'event' => 'mail_credential.rotate.completed',
            'key_id' => $keyId,
            'dry_run' => $dryRun,
            'rewrapped' => $rewrapped,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return 'rewrapped'|'skipped'|'failed'
     */
    private function rewrapAccount(UserMailAccount $account, bool $dryRun): string
    {
        $stored = (string) $account->imap_password_enc;

        if (!$this->crypto->needsRewrap($stored)) {
            return 'skipped';
        }

        try {
            $plaintext = $this->crypto->decrypt($stored);

            try {
                if (!$dryRun) {
                    $account->imap_password_enc = $this->crypto->encrypt($plaintext);
                    $account->save();
                }
            } finally {
                sodium_memzero($plaintext);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Mail credential rewrap failed.', [
                'event' => 'mail_credential.rotate.failed',
                'user_mail_account_id' => $account->id,
                'exception' => $exception,
            ]);

            return 'failed';
        }

        return 'rewrapped';
    }
}
