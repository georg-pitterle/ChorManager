<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Spielt Scaleway Topics and Events nach: eine eigene CA, ein davon
 * ausgestelltes Signierzertifikat und SNS-Nachrichten, die damit so signiert
 * sind, wie Scaleway (AWS-kompatibel) sie signiert.
 *
 * Die echte CA-Kette liegt unter resources/scaleway; deren privaten Schlüssel
 * hat nur Scaleway. Für Tests braucht es eine Kette, zu der der Schlüssel
 * bekannt ist - sonst ließe sich keine gültige Nachricht bauen.
 */
final class SnsTestSigner
{
    public const CERT_URL = 'https://sns.mnq.fr-par.scaleway.com/SNSSigningCert-test.pem';
    public const TOPIC_ARN = 'arn:scw:sns:fr-par:project-test:chormanager-tem';

    private string $caPem;
    private string $leafPem;
    private OpenSSLAsymmetricKey $leafKey;

    public function __construct()
    {
        $caKey = $this->newKey();
        $caCsr = openssl_csr_new(['commonName' => 'ChorManager Test MnQ Root'], $caKey);
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 365, ['x509_extensions' => 'v3_ca']);

        $this->leafKey = $this->newKey();
        $leafCsr = openssl_csr_new(['commonName' => 'sns.mnq.fr-par.scaleway.com'], $this->leafKey);
        $leafCert = openssl_csr_sign($leafCsr, $caCert, $caKey, 365, [], random_int(1, PHP_INT_MAX));

        if ($caCert === false || $leafCert === false) {
            throw new RuntimeException('Testzertifikate ließen sich nicht erzeugen.');
        }

        openssl_x509_export($caCert, $caPem);
        openssl_x509_export($leafCert, $leafPem);
        $this->caPem = $caPem;
        $this->leafPem = $leafPem;
    }

    public function caPem(): string
    {
        return $this->caPem;
    }

    public function leafPem(): string
    {
        return $this->leafPem;
    }

    /**
     * Ein selbst signiertes Zertifikat, das mit der Test-CA nichts zu tun hat.
     */
    public static function strangerPem(): string
    {
        $key = (new self())->newKey();
        $csr = openssl_csr_new(['commonName' => 'sns.mnq.fr-par.scaleway.com'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);
        openssl_x509_export($cert, $pem);

        return $pem;
    }

    /**
     * @param array<string, mixed> $temEvent
     * @return array<string, string>
     */
    public function notification(array $temEvent, string $messageId = 'sns-msg-1', string $version = '2'): array
    {
        return $this->sign([
            'Type' => 'Notification',
            'MessageId' => $messageId,
            'TopicArn' => self::TOPIC_ARN,
            'Message' => (string) json_encode($temEvent, JSON_UNESCAPED_SLASHES),
            'Timestamp' => '2026-10-16T08:00:00.000Z',
            'SignatureVersion' => $version,
            'SigningCertURL' => self::CERT_URL,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function subscriptionConfirmation(): array
    {
        return $this->sign([
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => 'sns-confirm-1',
            'Token' => 'confirm-token',
            'TopicArn' => self::TOPIC_ARN,
            'Message' => 'You have chosen to subscribe to the topic.',
            'SubscribeURL' => 'https://sns.mnq.fr-par.scaleway.com/?Action=ConfirmSubscription&Token=confirm-token',
            'Timestamp' => '2026-10-16T08:00:00.000Z',
            'SignatureVersion' => '2',
            'SigningCertURL' => self::CERT_URL,
        ]);
    }

    /**
     * Signiert eine Nachricht, deren Felder nach dem Signieren nicht mehr
     * angefasst werden - wer eine Abweichung prüfen will, ändert das Ergebnis.
     *
     * @param array<string, string> $message
     * @return array<string, string>
     */
    public function sign(array $message): array
    {
        $keys = $message['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $stringToSign = '';
        foreach ($keys as $key) {
            if (isset($message[$key])) {
                $stringToSign .= $key . "\n" . $message[$key] . "\n";
            }
        }

        $algorithm = ($message['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        openssl_sign($stringToSign, $signature, $this->leafKey, $algorithm);
        $message['Signature'] = base64_encode($signature);

        return $message;
    }

    private function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Testschlüssel ließ sich nicht erzeugen.');
        }

        return $key;
    }
}
