<?php

declare(strict_types=1);

namespace App\Services\Scaleway;

use Closure;
use DateTimeImmutable;
use Exception;

/**
 * Prüft, ob eine Nachricht von Scaleway Topics and Events wirklich aus unserem
 * Topic stammt.
 *
 * Topics and Events spricht das SNS-Protokoll von AWS: Jede Nachricht trägt
 * eine RSA-Signatur über ausgewählte Felder und die Adresse des Zertifikats,
 * mit dem signiert wurde. Diesem Zertifikat wird nicht blind vertraut - es muss
 * von Scaleways CA ausgestellt sein, deren Kette unter resources/scaleway liegt
 * (Vorgabe aus Scaleways "Verifying webhook signatures"). Das Zertifikat wird
 * mindestens jährlich getauscht; die CA gilt bis 2032.
 *
 * Die Signatur allein genügt nicht: Sie beweist nur, dass Scaleway die
 * Nachricht verschickt hat. Jeder Scaleway-Kunde kann ein eigenes Topic auf
 * unsere Adresse abonnieren und bekommt seine Nachrichten genauso gültig
 * signiert. Deshalb muss zusätzlich das Topic das konfigurierte sein; ist keins
 * konfiguriert, wird alles abgewiesen.
 */
final class SnsMessageVerifier
{
    /**
     * Felder, die in die Signatur eingehen, in der vorgeschriebenen Reihenfolge.
     * Fehlt ein optionales Feld (Subject), entfällt es samt Namen.
     */
    private const SIGNED_NOTIFICATION_FIELDS = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
    private const SIGNED_SUBSCRIPTION_FIELDS = [
        'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type',
    ];

    private const REQUIRED_FIELDS = [
        'Type', 'MessageId', 'TopicArn', 'Message', 'Timestamp', 'SignatureVersion', 'Signature', 'SigningCertURL',
    ];

    /**
     * Nur SHA-256. Version 1 signiert mit SHA-1 und wurde hier anfangs
     * mitgenommen, weil AWS sie jahrelang verschickt hat. SHA-1 gilt als
     * gebrochen, und Scaleway stellt seine Nachrichten mit Version 2 zu - die
     * ältere Fassung war damit nur ein Weg, die schwächere Prüfung zu
     * erzwingen, denn welche es sein soll, steht in der Nachricht selbst.
     */
    private const ALGORITHM_BY_SIGNATURE_VERSION = [
        '2' => OPENSSL_ALGO_SHA256,
    ];

    /**
     * Wie alt eine Nachricht höchstens sein darf. Ohne diese Grenze ließe sich
     * eine einmal mitgeschnittene gültige Nachricht beliebig oft erneut
     * einliefern: Die Signatur bleibt ja gültig, sie altert nicht.
     */
    private const MAX_MESSAGE_AGE_SECONDS = 3600;

    /**
     * Wie viel Vorsprung die Gegenseite haben darf. Zwei Uhren laufen nie genau
     * gleich; ohne Spielraum verlöre eine echte Nachricht ihr Rennen gegen die
     * eigene Systemzeit.
     */
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    /**
     * Die Adresse des Zertifikats kommt aus der Nachricht, also von außen.
     * Ohne Einschränkung ließe sich der Server damit Anfragen an beliebige Ziele
     * schicken, auch ins interne Netz. Die Liste schützt nur davor - ob das
     * Zertifikat echt ist, entscheidet allein die CA-Kette.
     */
    private const ALLOWED_CERTIFICATE_HOST = '/(^|\.)(scaleway\.com|scw\.cloud)$/';

    /** @var array<string, string> */
    private array $certificates = [];

    /**
     * @param Closure(string): ?string $certificateFetcher lädt das Zertifikat; null, wenn es nicht ging
     */
    public function __construct(
        private readonly string $expectedTopicArn,
        private readonly string $trustChainPath,
        private readonly Closure $certificateFetcher,
        private readonly string $certificateCacheDir
    ) {
    }

    public static function httpFetcher(): Closure
    {
        return static function (string $url): ?string {
            $context = stream_context_create([
                'http' => ['timeout' => 5, 'follow_location' => 0],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $context);

            return is_string($body) && $body !== '' ? $body : null;
        };
    }

    /**
     * Null, wenn die Nachricht echt ist und aus unserem Topic kommt; sonst ein
     * kurzer Grund fürs Log.
     *
     * @param array<array-key, mixed> $message
     */
    public function rejectionReason(array $message): ?string
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!is_string($message[$field] ?? null) || $message[$field] === '') {
                return 'malformed';
            }
        }

        $expectedTopicArn = trim($this->expectedTopicArn);
        if ($expectedTopicArn === '') {
            return 'topic_not_configured';
        }
        if (!hash_equals($expectedTopicArn, $message['TopicArn'])) {
            return 'topic_mismatch';
        }

        // Vor dem Zertifikat-Abruf: Eine abgelaufene Nachricht soll keine
        // Anfrage ins Netz auslösen. Der Zeitstempel ist hier noch ungeprüft,
        // aber das schadet nicht - wer ihn fälscht, scheitert eine Zeile
        // weiter an der Signatur, die ihn mitdeckt.
        $staleness = $this->freshnessRejection($message['Timestamp']);
        if ($staleness !== null) {
            return $staleness;
        }

        $algorithm = self::ALGORITHM_BY_SIGNATURE_VERSION[$message['SignatureVersion']] ?? null;
        if ($algorithm === null) {
            return 'signature_version_unsupported';
        }

        $certificateUrl = $message['SigningCertURL'];
        if (!$this->isAllowedCertificateUrl($certificateUrl)) {
            return 'certificate_url_not_allowed';
        }

        $certificate = $this->trustedCertificate($certificateUrl, $failure);
        if ($certificate === null) {
            return $failure;
        }

        $signature = base64_decode($message['Signature'], true);
        if ($signature === false) {
            return 'signature_invalid';
        }

        $verified = openssl_verify($this->stringToSign($message), $signature, $certificate, $algorithm);

        return $verified === 1 ? null : 'signature_invalid';
    }

    /**
     * Null, solange die Nachricht im Zeitfenster liegt; sonst der Grund.
     */
    private function freshnessRejection(string $timestamp): ?string
    {
        try {
            $sentAt = new DateTimeImmutable($timestamp);
        } catch (Exception) {
            return 'timestamp_invalid';
        }

        $ageSeconds = time() - $sentAt->getTimestamp();

        if ($ageSeconds > self::MAX_MESSAGE_AGE_SECONDS) {
            return 'stale';
        }

        return $ageSeconds < -self::MAX_CLOCK_SKEW_SECONDS ? 'timestamp_in_future' : null;
    }

    /**
     * @param array<array-key, mixed> $message
     */
    private function stringToSign(array $message): string
    {
        $fields = $message['Type'] === 'Notification'
            ? self::SIGNED_NOTIFICATION_FIELDS
            : self::SIGNED_SUBSCRIPTION_FIELDS;

        $stringToSign = '';
        foreach ($fields as $field) {
            if (isset($message[$field]) && is_scalar($message[$field])) {
                $stringToSign .= $field . "\n" . $message[$field] . "\n";
            }
        }

        return $stringToSign;
    }

    private function isAllowedCertificateUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return preg_match(self::ALLOWED_CERTIFICATE_HOST, strtolower($parts['host'] ?? '')) === 1;
    }

    /**
     * Holt das Zertifikat aus dem Speicher, vom Cache auf der Platte oder aus
     * dem Netz - und prüft es gegen die CA-Kette, gleich woher es kommt. Ein
     * manipulierter Cache bringt also nichts durch; zwischengespeichert wird
     * erst, was die Prüfung bestanden hat.
     */
    private function trustedCertificate(string $url, ?string &$failure): ?string
    {
        if (isset($this->certificates[$url])) {
            return $this->certificates[$url];
        }

        $cacheFile = rtrim($this->certificateCacheDir, '/') . '/' . hash('sha256', $url) . '.pem';
        $cached = is_file($cacheFile) ? @file_get_contents($cacheFile) : false;
        if (is_string($cached) && $this->isIssuedByTrustedCa($cached)) {
            return $this->certificates[$url] = $cached;
        }

        $fetched = ($this->certificateFetcher)($url);
        if ($fetched === null) {
            $failure = 'certificate_unavailable';

            return null;
        }
        if (!$this->isIssuedByTrustedCa($fetched)) {
            $failure = 'certificate_untrusted';

            return null;
        }

        if (is_dir($this->certificateCacheDir) || @mkdir($this->certificateCacheDir, 0775, true)) {
            @file_put_contents($cacheFile, $fetched, LOCK_EX);
        }

        return $this->certificates[$url] = $fetched;
    }

    private function isIssuedByTrustedCa(string $pem): bool
    {
        $certificate = @openssl_x509_read($pem);
        if ($certificate === false) {
            return false;
        }

        // Prüft Kette und Gültigkeitszeitraum. -1 ist ein Fehler bei der Prüfung
        // selbst und zählt wie ein Nein.
        return openssl_x509_checkpurpose($certificate, X509_PURPOSE_ANY, [$this->trustChainPath]) === true;
    }
}
