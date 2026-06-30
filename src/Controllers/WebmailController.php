<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserMailAccount;
use App\Services\MailCredentialCryptoService;
use App\Services\SnappymailSsoTokenService;
use App\Util\AppUrlResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class WebmailController
{
    private const SSO_TOKEN_TTL_SECONDS = 45;

    private LoggerInterface $logger;
    private MailCredentialCryptoService $crypto;
    private SnappymailSsoTokenService $ssoTokenService;

    public function __construct(
        LoggerInterface $logger,
        MailCredentialCryptoService $crypto,
        SnappymailSsoTokenService $ssoTokenService
    ) {
        $this->logger = $logger;
        $this->crypto = $crypto;
        $this->ssoTokenService = $ssoTokenService;
    }

    public function start(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];

        $account = UserMailAccount::where('user_id', $userId)->first();
        if (!$account || !$account->imap_enabled) {
            $_SESSION['error'] = 'Bitte richte zuerst deinen Mailbox-Zugang im Profil ein.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        try {
            $password = $this->crypto->decrypt($account->imap_password_enc);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Webmail SSO start failed: could not decrypt stored mail credential.',
                [
                    'event' => 'webmail.start.decrypt_failed',
                    'user_id' => $userId,
                ]
            );
            $_SESSION['error'] = 'Webmail konnte nicht gestartet werden. Bitte versuche es später erneut.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        // This app is IMAP-only; there is no separate SMTP identity to track,
        // so the IMAP username is deliberately reused for email/imap_user/smtp_user.
        $payload = [
            'email' => $account->imap_username,
            'imap_user' => $account->imap_username,
            'smtp_user' => $account->imap_username,
            'password' => $password,
            'imap_host' => $account->imap_host,
            'imap_port' => (int) $account->imap_port,
            'imap_enc' => $account->imap_encryption,
            'exp' => time() + self::SSO_TOKEN_TTL_SECONDS,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $token = $this->ssoTokenService->createToken($payload);

        $baseUrl = AppUrlResolver::resolveBaseUrl($request);
        $redirectUrl = $baseUrl . '/webmail/?chormanager-sso&token=' . rawurlencode($token);

        $this->logger->info(
            'Webmail SSO redirect issued.',
            [
                'event' => 'webmail.start.redirected',
                'user_id' => $userId,
            ]
        );

        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }
}
