<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Util\EnvHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Mailer
{
    private PHPMailer $mail;
    private ?string $lastError = null;
    private bool $useSmtp = false;
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->mail = new PHPMailer(true);
        $this->configure();
    }

    private function configure(): void
    {
        $this->mail->CharSet = 'UTF-8';

        $fromEmail = EnvHelper::read('SMTP_FROM_EMAIL', 'noreply@chor.local');
        $fromName = EnvHelper::read('SMTP_FROM_NAME', 'Chor-Manager');
        $this->mail->setFrom($fromEmail, $fromName);

        if ($this->hasSmtpConfig()) {
            $this->configureSmtp();
        } else {
            $this->configureSendmail();
        }
    }

    private function hasSmtpConfig(): bool
    {
        $smtpHost = EnvHelper::read('SMTP_HOST', '');
        return $smtpHost !== '';
    }

    private function configureSmtp(): void
    {
        $this->useSmtp = true;
        $this->mail->isSMTP();
        $this->mail->Host = EnvHelper::read('SMTP_HOST', 'mailhog');
        $this->mail->SMTPAuth = EnvHelper::readBool('SMTP_AUTH', true);
        $this->mail->Username = EnvHelper::read('SMTP_USERNAME', '');
        $this->mail->Password = EnvHelper::read('SMTP_PASSWORD', '');

        $encryption = strtolower(EnvHelper::read('SMTP_ENCRYPTION', 'none'));
        if ($encryption === 'tls') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $this->mail->SMTPSecure = '';
        }

        $this->mail->Port = (int) EnvHelper::read('SMTP_PORT', '1025');
    }

    private function configureSendmail(): void
    {
        $this->useSmtp = false;
        $this->mail->isSendmail();
    }

    public function isMailSendDisabled(): bool
    {
        return EnvHelper::readBool('DISABLE_MAIL_SEND', true);
    }

    /**
     * Send a HTML mail and return delivery metadata for queue lifecycle handling.
     *
     * @return array{success: bool, skipped: bool, provider_name: string, provider_message_id: ?string}
     */
    public function sendHtmlMailDetailed(string $to, string $subject, string $htmlBody): array
    {
        $providerName = $this->useSmtp ? 'smtp' : 'sendmail';

        if ($this->isMailSendDisabled()) {
            $this->logger->info(
                'Outbound mail skipped because DISABLE_MAIL_SEND is active.',
                [
                    'event' => 'mail.send.skipped',
                    'provider_name' => 'disabled',
                    'recipient_email' => $to,
                ]
            );

            return [
                'success' => true,
                'skipped' => true,
                'provider_name' => 'disabled',
                'provider_message_id' => null,
            ];
        }

        try {
            $this->lastError = null;
            $this->mail->clearAddresses();
            $this->mail->addAddress($to);
            $this->mail->isHTML(true);
            $this->mail->Subject = $subject;
            $this->mail->Body = $htmlBody;

            // Generate plain text version from HTML
            $this->mail->AltBody = strip_tags($htmlBody);

            $result = $this->mail->send();
            if ($result) {
                $mode = $this->useSmtp ? 'SMTP' : 'sendmail';
                $this->logger->info(
                    'Mail sent successfully.',
                    [
                        'event' => 'mail.send.success',
                        'mode' => $mode,
                        'provider_name' => $providerName,
                        'recipient_email' => $to,
                    ]
                );

                $providerMessageId = trim((string) $this->mail->getLastMessageID());

                return [
                    'success' => true,
                    'skipped' => false,
                    'provider_name' => $providerName,
                    'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                ];
            }

            return [
                'success' => false,
                'skipped' => false,
                'provider_name' => $providerName,
                'provider_message_id' => null,
            ];
        } catch (Exception $e) {
            $this->lastError = $this->mail->ErrorInfo !== '' ? $this->mail->ErrorInfo : $e->getMessage();
            $mode = $this->useSmtp ? 'SMTP' : 'sendmail';
            $this->logger->error(
                'Mail send failed.',
                [
                    'event' => 'mail.send.failed',
                    'mode' => $mode,
                    'provider_name' => $providerName,
                    'recipient_email' => $to,
                    'error' => $this->lastError,
                    'exception' => $e,
                ]
            );

            return [
                'success' => false,
                'skipped' => false,
                'provider_name' => $providerName,
                'provider_message_id' => null,
            ];
        }
    }

    public function sendHtmlMail(string $to, string $subject, string $htmlBody): bool
    {
        $result = $this->sendHtmlMailDetailed($to, $subject, $htmlBody);

        return (bool) ($result['success'] ?? false);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function isUsingSmtp(): bool
    {
        return $this->useSmtp;
    }
}
