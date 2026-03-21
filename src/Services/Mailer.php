<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private PHPMailer $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        $this->configure();
    }

    private function configure(): void
    {
        $this->mail->isSMTP();
        $this->mail->CharSet = 'UTF-8';
        $this->mail->Host = $this->readEnv('SMTP_HOST', 'mailhog');
        $this->mail->SMTPAuth = $this->readBoolEnv('SMTP_AUTH', true);
        $this->mail->Username = $this->readEnv('SMTP_USERNAME', '');
        $this->mail->Password = $this->readEnv('SMTP_PASSWORD', '');

        $encryption = strtolower($this->readEnv('SMTP_ENCRYPTION', 'none'));
        if ($encryption === 'tls') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $this->mail->SMTPSecure = '';
        }

        $this->mail->Port = (int) $this->readEnv('SMTP_PORT', '1025');

        $fromEmail = $this->readEnv('SMTP_FROM_EMAIL', 'noreply@chor.local');
        $fromName = $this->readEnv('SMTP_FROM_NAME', 'Chor Manager');

        $this->mail->setFrom($fromEmail, $fromName);
    }

    private function readEnv(string $key, string $default): string
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : $default;
    }

    private function readBoolEnv(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        return $default;
    }

    public function sendHtmlMail(string $to, string $subject, string $htmlBody): bool
    {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($to);
            $this->mail->isHTML(true);
            $this->mail->Subject = $subject;
            $this->mail->Body = $htmlBody;

            // Generate plain text version from HTML
            $this->mail->AltBody = strip_tags($htmlBody);

            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$this->mail->ErrorInfo}");
            return false;
        }
    }
}
