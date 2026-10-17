<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scaleway;

use App\Services\Scaleway\SnsMessageVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SnsTestSigner;

/**
 * Die Signatur beweist nur, dass Scaleway die Nachricht verschickt hat - nicht,
 * dass sie aus unserem Topic stammt. Jeder Scaleway-Kunde kann ein eigenes Topic
 * auf unsere Adresse abonnieren und bekommt seine Nachrichten genauso gültig
 * signiert. Deshalb prüft der Verifier beides.
 */
final class SnsMessageVerifierTest extends TestCase
{
    private static ?SnsTestSigner $signer = null;
    private string $workDir;
    private int $fetchCount = 0;
    private ?string $servedPem = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$signer ??= new SnsTestSigner();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/sns-verifier-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
        file_put_contents($this->workDir . '/ca.pem', $this->signer()->caPem());
        $this->fetchCount = 0;
        $this->servedPem = $this->signer()->leafPem();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/{,cache/}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->workDir . '/cache')) {
            rmdir($this->workDir . '/cache');
        }
        rmdir($this->workDir);

        parent::tearDown();
    }

    public function testSignedNotificationFromTheConfiguredTopicIsAccepted(): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered']);

        $this->assertNull($this->verifier()->rejectionReason($message));
    }

    /**
     * SignatureVersion 1 signiert mit SHA-1. Das Verfahren gilt als gebrochen,
     * und Scaleway schickt ohnehin Version 2 - angenommen wird es deshalb nicht
     * mehr, auch wenn die Signatur für sich gültig wäre.
     */
    public function testSignatureVersionOneIsRejected(): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered'], 'sns-v1', '1');

        $this->assertSame('signature_version_unsupported', $this->verifier()->rejectionReason($message));
        $this->assertSame(0, $this->fetchCount);
    }

    public function testSignedSubscriptionConfirmationIsAccepted(): void
    {
        $this->assertNull($this->verifier()->rejectionReason($this->signer()->subscriptionConfirmation()));
    }

    public function testChangedMessageBreaksTheSignature(): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered']);
        $message['Message'] = (string) json_encode(['type' => 'email_dropped']);

        $this->assertSame('signature_invalid', $this->verifier()->rejectionReason($message));
    }

    public function testChangedSubscribeUrlBreaksTheSignature(): void
    {
        $message = $this->signer()->subscriptionConfirmation();
        $message['SubscribeURL'] = 'https://attacker.example/confirm';

        $this->assertSame('signature_invalid', $this->verifier()->rejectionReason($message));
    }

    public function testValidlySignedMessageFromAForeignTopicIsRejected(): void
    {
        $message = $this->signer()->sign([
            'Type' => 'Notification',
            'MessageId' => 'foreign-1',
            'TopicArn' => 'arn:scw:sns:fr-par:other-project:their-topic',
            'Message' => '{"type":"email_delivered"}',
            'Timestamp' => SnsTestSigner::timestamp(),
            'SignatureVersion' => '2',
            'SigningCertURL' => SnsTestSigner::CERT_URL,
        ]);

        $this->assertSame('topic_mismatch', $this->verifier()->rejectionReason($message));
    }

    public function testWithoutConfiguredTopicEverythingIsRejected(): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered']);

        $this->assertSame('topic_not_configured', $this->verifier('')->rejectionReason($message));
        $this->assertSame(0, $this->fetchCount);
    }

    public function testCertificateNotIssuedByTheTrustedCaIsRejected(): void
    {
        $this->servedPem = SnsTestSigner::strangerPem();
        $message = $this->signer()->notification(['type' => 'email_delivered']);

        $this->assertSame('certificate_untrusted', $this->verifier()->rejectionReason($message));
    }

    public function testUnreachableCertificateIsRejected(): void
    {
        $this->servedPem = null;
        $message = $this->signer()->notification(['type' => 'email_delivered']);

        $this->assertSame('certificate_unavailable', $this->verifier()->rejectionReason($message));
    }

    /**
     * Die Adresse kommt aus der Nachricht, also von außen. Ohne Prüfung ließe
     * sich der Server Anfragen an beliebige Ziele schicken - auch ins interne Netz.
     */
    #[DataProvider('foreignCertificateUrls')]
    public function testCertificateIsOnlyFetchedFromScalewayOverHttps(string $url): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered']);
        $message['SigningCertURL'] = $url;

        $this->assertSame('certificate_url_not_allowed', $this->verifier()->rejectionReason($message));
        $this->assertSame(0, $this->fetchCount);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignCertificateUrls(): array
    {
        return [
            'http' => ['http://sns.mnq.fr-par.scaleway.com/cert.pem'],
            'fremder Host' => ['https://attacker.example/cert.pem'],
            'Suffix ohne Punkt' => ['https://evilscaleway.com/cert.pem'],
            'Host im Pfad' => ['https://attacker.example/sns.mnq.fr-par.scaleway.com/cert.pem'],
            'interne Adresse' => ['https://169.254.169.254/latest'],
            'Zugangsdaten' => ['https://scaleway.com@attacker.example/cert.pem'],
        ];
    }

    public function testCertificateIsFetchedOnlyOncePerUrl(): void
    {
        $verifier = $this->verifier();

        $this->assertNull($verifier->rejectionReason($this->signer()->notification(['type' => 'a'], 'm-1')));
        $this->assertNull($verifier->rejectionReason($this->signer()->notification(['type' => 'b'], 'm-2')));
        // Eine neue Instanz entspricht der nächsten HTTP-Anfrage: Der Cache liegt auf der Platte.
        $this->assertNull($this->verifier()->rejectionReason($this->signer()->notification(['type' => 'c'], 'm-3')));

        $this->assertSame(1, $this->fetchCount);
    }

    public function testUntrustedCertificateIsNotCached(): void
    {
        $this->servedPem = SnsTestSigner::strangerPem();
        $message = $this->signer()->notification(['type' => 'email_delivered']);
        $this->verifier()->rejectionReason($message);

        $this->servedPem = $this->signer()->leafPem();

        $this->assertNull($this->verifier()->rejectionReason($message));
        $this->assertSame(2, $this->fetchCount);
    }

    #[DataProvider('incompleteMessages')]
    public function testIncompleteMessageIsRejected(string $missingField): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered']);
        unset($message[$missingField]);

        $this->assertSame('malformed', $this->verifier()->rejectionReason($message));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function incompleteMessages(): array
    {
        return [
            'Type' => ['Type'],
            'Signature' => ['Signature'],
            'SigningCertURL' => ['SigningCertURL'],
            'TopicArn' => ['TopicArn'],
            'Message' => ['Message'],
        ];
    }

    public function testNonStringFieldIsRejected(): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered']);
        $message['Message'] = ['type' => 'email_delivered'];

        $this->assertSame('malformed', $this->verifier()->rejectionReason($message));
    }

    public function testUnknownSignatureVersionIsRejected(): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered']);
        $message['SignatureVersion'] = '3';

        $this->assertSame('signature_version_unsupported', $this->verifier()->rejectionReason($message));
    }

    /**
     * Eine einmal mitgeschnittene gültige Nachricht ließe sich sonst beliebig
     * oft erneut einliefern. Geprüft wird vor dem Zertifikat-Abruf: Eine
     * abgelaufene Nachricht soll keine Anfrage ins Netz auslösen.
     */
    public function testStaleMessageIsRejected(): void
    {
        $message = $this->signer()->notification(
            ['type' => 'email_delivered'],
            'sns-stale',
            '2',
            SnsTestSigner::timestamp(-7200)
        );

        $this->assertSame('stale', $this->verifier()->rejectionReason($message));
        $this->assertSame(0, $this->fetchCount);
    }

    public function testMessageInsideTheFreshnessWindowIsAccepted(): void
    {
        $message = $this->signer()->notification(
            ['type' => 'email_delivered'],
            'sns-fresh',
            '2',
            SnsTestSigner::timestamp(-1800)
        );

        $this->assertNull($this->verifier()->rejectionReason($message));
    }

    public function testMessageFromTheFutureIsRejected(): void
    {
        $message = $this->signer()->notification(
            ['type' => 'email_delivered'],
            'sns-future',
            '2',
            SnsTestSigner::timestamp(3600)
        );

        $this->assertSame('timestamp_in_future', $this->verifier()->rejectionReason($message));
        $this->assertSame(0, $this->fetchCount);
    }

    /**
     * Die Uhren von Scaleway und uns laufen nie genau gleich. Ein paar Sekunden
     * Vorsprung dürfen keine echte Nachricht kosten.
     */
    public function testSmallClockSkewIntoTheFutureIsTolerated(): void
    {
        $message = $this->signer()->notification(
            ['type' => 'email_delivered'],
            'sns-skew',
            '2',
            SnsTestSigner::timestamp(60)
        );

        $this->assertNull($this->verifier()->rejectionReason($message));
    }

    public function testUnreadableTimestampIsRejected(): void
    {
        $message = $this->signer()->notification(['type' => 'email_delivered']);
        $message['Timestamp'] = 'übermorgen';

        $this->assertSame('timestamp_invalid', $this->verifier()->rejectionReason($message));
        $this->assertSame(0, $this->fetchCount);
    }

    public function testBundledTrustChainIsScalewaysMnqCa(): void
    {
        $pem = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/scaleway/sns-trust-chain.pem');
        $certificate = openssl_x509_parse($pem);

        $this->assertIsArray($certificate);
        $this->assertSame('Scaleway MnQ Root', $certificate['subject']['CN'] ?? null);
    }

    private function verifier(string $topicArn = SnsTestSigner::TOPIC_ARN): SnsMessageVerifier
    {
        return new SnsMessageVerifier(
            $topicArn,
            $this->workDir . '/ca.pem',
            function (string $url): ?string {
                $this->fetchCount++;

                return $url === SnsTestSigner::CERT_URL ? $this->servedPem : null;
            },
            $this->workDir . '/cache'
        );
    }

    private function signer(): SnsTestSigner
    {
        return self::$signer ?? throw new \LogicException('Signer fehlt.');
    }
}
