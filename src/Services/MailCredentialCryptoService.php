<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Log\LoggerInterface;

/**
 * Verschlüsselung der IMAP-Zugangsdaten im Ruhezustand.
 *
 * Die Mechanik steht in SecretBoxCryptoService, hier stehen nur die beiden
 * Umgebungsvariablen: MAIL_CREDENTIAL_KEY trägt den aktiven Schlüssel und ist
 * Pflicht - fehlt oder missfällt er, wirft der Konstruktor, statt Klartext
 * abzulegen. MAIL_CREDENTIAL_KEY_PREVIOUS ist freiwillig und wird nur während
 * einer Rotation gelesen. Der Schlüssel ist bewusst ein anderer als
 * WEBMAIL_SSO_SECRET und OIDC_SIGNING_KEY_SECRET.
 */
final class MailCredentialCryptoService extends SecretBoxCryptoService
{
    private const KEY_ENV = 'MAIL_CREDENTIAL_KEY';
    private const PREVIOUS_KEY_ENV = 'MAIL_CREDENTIAL_KEY_PREVIOUS';

    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct(
            self::KEY_ENV,
            self::PREVIOUS_KEY_ENV,
            $logger,
            'mail_credential.decrypt.failed'
        );
    }

    protected function decryptFailureMessage(): string
    {
        return 'Unable to decrypt mail credential';
    }
}
