<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Oidc\AuthorizationCodeService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Beendet alle Anmeldungen eines Kontos - außer der Sitzung, aus der heraus das
 * geschieht, wenn sie dem Konto selbst gehört.
 *
 * Drei Arten von Anmeldung gibt es: laufende Sitzungen, Angemeldet-bleiben-Token
 * und die Zugangstoken angeschlossener Anwendungen (OIDC). Ein Passwortwechsel
 * verwarf bis hierher nur die zweite. Wer ein Konto übernommen hatte und darin
 * angemeldet war, blieb es auch nach dem Wechsel - im Ernstfall, für den man das
 * Passwort wechselt, also genau die Wirkung, die man nicht will.
 *
 * Laufende Sitzungen liegen als Dateien und sind von hier nicht einzeln zu
 * erreichen. Deshalb bekommt das Konto eine Grenze (`users.sessions_valid_after`),
 * und die AuthMiddleware meldet jede Sitzung ab, die vor ihr begonnen hat. Die
 * eigene Sitzung rückt dabei auf die Grenze vor und bleibt angemeldet.
 */
class AccountLoginRevocationService
{
    public function __construct(
        private readonly RememberLoginService $rememberLoginService,
        private readonly AuthorizationCodeService $authorizationCodeService,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function revokeAllLogins(User $user, string $reason): void
    {
        $userId = (int) $user->id;
        $now = time();

        User::query()->whereKey($userId)->update(['sessions_valid_after' => $now]);

        if ((int) ($_SESSION['user_id'] ?? 0) === $userId) {
            $_SESSION['auth_epoch'] = $now;
        }

        $rememberTokens = $this->rememberLoginService->invalidateAllForUser($userId);
        if ($rememberTokens > 0) {
            // Dieselbe Zeile wie vor dem Umbau - die Log-Auswertung sucht danach.
            $this->logger->info('Remember-me tokens revoked after password change.', [
                'event' => 'auth.remember_me.revoked',
                'reason' => $reason,
                'user_id' => $userId,
                'revoked_count' => $rememberTokens,
            ]);
        }

        $oidc = $this->authorizationCodeService->revokeForUser($userId);

        $this->logger->info('All logins of the account ended.', [
            'event' => 'auth.account_logins.revoked',
            'reason' => $reason,
            'user_id' => $userId,
            'revoked_remember_tokens' => $rememberTokens,
            'revoked_oidc_codes' => $oidc['codes'],
            'revoked_oidc_tokens' => $oidc['tokens'],
        ]);
    }
}
