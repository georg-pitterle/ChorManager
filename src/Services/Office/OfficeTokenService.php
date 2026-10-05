<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\OfficeAccessToken;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Stellt Zugangstokens für Collabora aus und löst sie wieder auf. Abgelaufene
 * Tokens räumt jedes neue Ausstellen ab - ein eigener Cron-Lauf lohnt dafür nicht.
 */
final class OfficeTokenService
{
    /** Zehn Stunden: ein langer Probenabend am Dokument, aber kein Dauerzugang. */
    public const TTL_SECONDS = 36000;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function issue(int $userId, int $fileId): IssuedOfficeToken
    {
        $now = Carbon::now();
        OfficeAccessToken::query()->where('expires_at', '<=', $now)->delete();

        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $now->copy()->addSeconds(self::TTL_SECONDS);
        OfficeAccessToken::create([
            'token_hash' => hash('sha256', $plain),
            'file_id' => $fileId,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        $this->logger->info('Office access token issued.', [
            'event' => 'office.token_issued',
            'file_id' => $fileId,
            'user_id' => $userId,
        ]);

        return new IssuedOfficeToken($plain, $expiresAt->toImmutable());
    }

    public function resolve(string $plain, int $fileId): ?OfficeAccessToken
    {
        if ($plain === '' || strlen($plain) > 128) {
            return null;
        }

        $token = OfficeAccessToken::query()->where('token_hash', hash('sha256', $plain))->first();
        if ($token === null || $token->file_id !== $fileId || $token->expires_at->lte(Carbon::now())) {
            return null;
        }

        return $token;
    }
}
