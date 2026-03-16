<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Models\AppSetting;

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
        $settings = AppSetting::all()->pluck('setting_value', 'setting_key')->toArray();

        $this->mail->isSMTP();
        $this->mail->CharSet = 'UTF-8';
        $this->mail->Host = $settings['smtp_host'] ?? 'smtp.gmail.com';
        $this->mail->SMTPAuth = true;
        $this->mail->Username = $settings['smtp_username'] ?? '';
        $this->mail->Password = $settings['smtp_password'] ?? '';

        $encryption = strtolower($settings['smtp_encryption'] ?? 'tls');
        if ($encryption === 'tls') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $this->mail->SMTPSecure = '';
        }

        $this->mail->Port = (int)($settings['smtp_port'] ?? 587);

        $fromEmail = $settings['smtp_from_email'] ?? 'noreply@example.com';
        $fromName = $settings['smtp_from_name'] ?? 'Chor Manager';

        $this->mail->setFrom($fromEmail, $fromName);
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
