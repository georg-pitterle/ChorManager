<?php

declare(strict_types=1);

namespace App\Services;

use App\Util\EnvHelper;

final class ProviderWebhookVerifier
{
    private const DSN_TOKEN_HEADER = 'x-mail-dsn-token';
    private const DSN_TOKEN_ENV = 'MAIL_DSN_INGEST_TOKEN';

    private const SIGNATURE_HEADER_BY_PROVIDER = [
        'smtp2go' => 'x-smtp2go-signature',
        'brevo' => 'x-sib-signature',
    ];

    private const SECRET_ENV_BY_PROVIDER = [
        'smtp2go' => 'SMTP2GO_WEBHOOK_SECRET',
        'brevo' => 'BREVO_WEBHOOK_SECRET',
    ];

    public function verify(string $provider, array $headers, string $body): bool
    {
        $providerKey = strtolower(trim($provider));
        if (!isset(self::SIGNATURE_HEADER_BY_PROVIDER[$providerKey], self::SECRET_ENV_BY_PROVIDER[$providerKey])) {
            return false;
        }

        $normalizedHeaders = $this->normalizeHeaders($headers);
        $signatureHeader = self::SIGNATURE_HEADER_BY_PROVIDER[$providerKey];
        $signature = trim((string) ($normalizedHeaders[$signatureHeader] ?? ''));
        if ($signature === '') {
            return false;
        }

        $secret = EnvHelper::read(self::SECRET_ENV_BY_PROVIDER[$providerKey], '');
        if ($secret === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $body, $secret);
        $providedSignature = strtolower($signature);

        return hash_equals($expectedSignature, $providedSignature)
            || hash_equals('sha256=' . $expectedSignature, $providedSignature);
    }

    public function verifyDsn(array $headers): bool
    {
        $normalizedHeaders = $this->normalizeHeaders($headers);
        $providedToken = trim((string) ($normalizedHeaders[self::DSN_TOKEN_HEADER] ?? ''));
        if ($providedToken === '') {
            return false;
        }

        $expectedToken = trim(EnvHelper::read(self::DSN_TOKEN_ENV, ''));
        if ($expectedToken === '') {
            return false;
        }

        return hash_equals($expectedToken, $providedToken);
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalizedName = strtolower((string) $name);

            if (is_array($value)) {
                $first = $value[0] ?? '';
                $normalized[$normalizedName] = (string) $first;
                continue;
            }

            $normalized[$normalizedName] = (string) $value;
        }

        return $normalized;
    }
}
