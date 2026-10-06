<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Util\EnvHelper;
use App\Util\MailInlineImages;
use App\Util\MailPlainText;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Mailer
{
    /**
     * Die beiden Werte, die den Versandweg nachweislich verschlüsseln. Alles andere -
     * `none`, ein leerer Wert, ein Tippfehler - schickt Zugangsdaten und Mailinhalt im
     * Klartext über das Netz.
     *
     * PHPMailer versucht über `SMTPAutoTLS` von sich aus ein STARTTLS, wenn der Server
     * es anbietet. Darauf lässt sich aber nichts aufbauen: Bietet der Server es nicht
     * an, geht es still unverschlüsselt weiter, und dass es so war, steht nirgends.
     *
     * @var list<string>
     */
    private const ENCRYPTED_TRANSPORTS = ['tls', 'ssl'];

    /**
     * Grund, mit dem ein solcher Versand abbricht.
     *
     * Der Text trägt `invalid_config`, weil MailDeliveryService::classifyError() genau
     * darauf prüft und die Mail dann sofort beiseitelegt. Eine fehlende Einstellung
     * behebt sich nicht von selbst; sie dreimal zu wiederholen kostet nur Zeit und
     * verdeckt in der Statistik, woran es lag. Englisch wie die übrigen Meldungen von
     * PHPMailer, die durch dasselbe Feld laufen.
     */
    private const ENCRYPTION_REQUIRED_ERROR = 'invalid_config: SMTP requires SMTP_ENCRYPTION=tls'
        . ' or ssl when APP_ENV=production; refusing to send credentials in the clear.';

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

        $encryption = $this->smtpEncryption();
        if ($encryption === 'tls') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $this->mail->SMTPSecure = '';
        }

        $this->mail->Port = (int) EnvHelper::read('SMTP_PORT', '1025');
    }

    private function smtpEncryption(): string
    {
        return strtolower(trim(EnvHelper::read('SMTP_ENCRYPTION', 'none')));
    }

    /**
     * Fehlt im Produktivbetrieb die Verschlüsselung, wird nicht gesendet.
     *
     * Geprüft wird beim Senden und nicht im Konstruktor: Der Mailer hängt im Container,
     * und eine Ausnahme dort nähme jede Seite mit, die ihn nur beiläufig anfordert -
     * auch die Einstellungsseite, auf der der Fehler zu beheben wäre. Der Versandweg
     * über sendmail bleibt außen vor: Er läuft über einen lokalen Prozess, nicht über
     * das Netz, und trägt keine Zugangsdaten.
     */
    private function requiresEncryptionButHasNone(): bool
    {
        if (!$this->useSmtp) {
            return false;
        }

        if (EnvHelper::read('APP_ENV', 'development') !== 'production') {
            return false;
        }

        return !in_array($this->smtpEncryption(), self::ENCRYPTED_TRANSPORTS, true);
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
     * @param array<int, array{content: string, name: string, mime: string}> $attachments Dateien,
     *        die an der Mail hängen. Der Inhalt kommt als Zeichenkette aus der Datenbank, nicht
     *        als Pfad - im Dateisystem des Containers liegt er nicht.
     * @return array{success: bool, skipped: bool, provider_name: string, provider_message_id: ?string}
     */
    public function sendHtmlMailDetailed(string $to, string $subject, string $htmlBody, array $attachments = []): array
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

        if ($this->requiresEncryptionButHasNone()) {
            $this->lastError = self::ENCRYPTION_REQUIRED_ERROR;
            $this->logger->error(
                'Outbound mail refused because SMTP would be unencrypted.',
                [
                    'event' => 'mail.send.refused',
                    'reason' => 'encryption_required',
                    'provider_name' => $providerName,
                    'recipient_email' => $to,
                ]
            );

            return [
                'success' => false,
                'skipped' => false,
                'provider_name' => $providerName,
                'provider_message_id' => null,
            ];
        }

        try {
            $this->lastError = null;
            $this->composeMessage($to, $subject, $htmlBody, $attachments);

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

    /**
     * Bestückt die wiederverwendete PHPMailer-Instanz mit Empfänger, Betreff und Inhalt.
     *
     * Bilder aus `data:`-URIs werden zu eingebetteten Anhängen: Gmail entfernt solche Quellen
     * beim Umschreiben des HTML, das Logo im Mailkopf bliebe dort sonst unsichtbar.
     */
    private function composeMessage(
        string $to,
        string $subject,
        string $htmlBody,
        array $attachments = []
    ): void {
        // Die Instanz lebt über mehrere Mails hinweg; ohne Zurücksetzen hängen die
        // eingebetteten Bilder der Vormail an der nächsten.
        $this->mail->clearAddresses();
        $this->mail->clearAttachments();

        $this->mail->addAddress($to);
        $this->mail->isHTML(true);
        $this->mail->Subject = $subject;

        $inline = MailInlineImages::extract($htmlBody);
        foreach ($inline['images'] as $image) {
            $this->mail->addStringEmbeddedImage(
                $image['data'],
                $image['cid'],
                $image['name'],
                PHPMailer::ENCODING_BASE64,
                $image['mime']
            );
        }

        // Die Newsletter-Anhänge. Das clearAttachments() oben deckt auch sie ab -
        // ohne das hinge die Datei der Vormail an der nächsten.
        foreach ($attachments as $attachment) {
            $this->mail->addStringAttachment(
                $attachment['content'],
                $attachment['name'],
                PHPMailer::ENCODING_BASE64,
                $attachment['mime']
            );
        }

        $this->mail->Body = $inline['html'];

        // Die Textfassung für Programme, die kein HTML anzeigen. Nicht über strip_tags():
        // das entfernt nur die Auszeichnung und ließ den gesamten CSS-Block der Vorlage
        // als Fließtext stehen, siehe MailPlainText.
        $this->mail->AltBody = MailPlainText::fromHtml($inline['html']);
    }

    /**
     * Baut die fertige MIME-Nachricht, ohne sie zu verschicken — für Diagnose und Tests.
     *
     * @param array<int, array{content: string, name: string, mime: string}> $attachments
     */
    public function buildMimeMessage(string $to, string $subject, string $htmlBody, array $attachments = []): string
    {
        $this->composeMessage($to, $subject, $htmlBody, $attachments);
        $this->mail->preSend();

        return $this->mail->getSentMIMEMessage();
    }

    /**
     * @param array<int, array{content: string, name: string, mime: string}> $attachments
     */
    public function sendHtmlMail(string $to, string $subject, string $htmlBody, array $attachments = []): bool
    {
        $result = $this->sendHtmlMailDetailed($to, $subject, $htmlBody, $attachments);

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
