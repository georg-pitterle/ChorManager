<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProfileController;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Queries\UserQuery;
use App\Services\HtmlSanitizer;
use App\Services\MailCredentialCryptoService;
use App\Services\NameFormatterService;
use App\Services\Oidc\OidcAdminService;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Services\PasswordPolicyService;
use App\Util\PasswordHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Antworten auf die Rückfragen aus dem Review-Lauf 29.
 *
 * 1) Fehlt einem Mitglied die externe Kennung, bildet ChorManager sie aus der
 *    ID: `cm-<id>`. Genau diese Form ließ sich bei einem *anderen* Mitglied von
 *    Hand eintragen - die Dublettenprüfung vergleicht nur gegen gespeicherte
 *    Kennungen, nicht gegen die abgeleiteten. Beide Mitglieder wiesen sich danach
 *    mit demselben `sub` aus und landeten auf demselben Nextcloud-Konto.
 *
 * 2) Bei der Postfach-Anbindung war "keine Verschlüsselung" auswählbar. Das
 *    IMAP-Passwort des Mitglieds ging dann im Klartext über das Netz -
 *    MailBadgeService verbindet sich damit wirklich.
 *
 * 3) Der Zwischenspeicher des HTML-Filters lag in sys_get_temp_dir(). Für die
 *    Rate-Limit-Zähler ist derselbe Ort bewusst verlassen worden: Das
 *    Temp-Verzeichnis gehört niemandem und wird von Systemdiensten geleert.
 */
final class ReviewAnswersRun29FeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $user;
    private ProfileController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->user = User::create([
            'first_name' => 'Rita',
            'last_name' => 'Rückfrage',
            'email' => 'run29.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);

        $this->controller = new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            new NullLogger(),
            new MailCredentialCryptoService()
        );

        $_SESSION = [];
        $_SESSION['user_id'] = $this->user->id;
    }

    protected function tearDown(): void
    {
        UserMailAccount::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    // 1) Die abgeleitete Kennungsform ist für ChorManager reserviert
    // ---------------------------------------------------------------

    public function testTheDerivedSubjectFormCannotBeAssignedByHand(): void
    {
        $service = new OidcAdminService(new NullLogger());

        // Genau die Form, die ein anderes Mitglied ohne eigene Kennung trägt.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reserviert/');

        $service->setExternalUid((string) $this->user->email, 'cm-4711');
    }

    public function testTheGuardCoversTheFormNoMatterHowItIsWritten(): void
    {
        $service = new OidcAdminService(new NullLogger());

        $this->expectException(RuntimeException::class);
        $service->setExternalUid((string) $this->user->email, 'CM-4711');
    }

    public function testAnOrdinaryExternalUidStillGoesThrough(): void
    {
        $service = new OidcAdminService(new NullLogger());

        $saved = $service->setExternalUid((string) $this->user->email, 'georg.pitterle');

        $this->assertSame('georg.pitterle', (string) $saved->external_uid);
        $this->assertSame('georg.pitterle', OidcClaimsBuilder::subjectFor($saved));
    }

    /**
     * Nur die abgeleitete Form ist gesperrt, nicht jede Kennung mit diesen zwei
     * Buchstaben: "cm-georg" kann mit keinem `cm-<id>` zusammenfallen.
     */
    public function testAUidThatMerelyStartsWithCmIsStillAllowed(): void
    {
        $service = new OidcAdminService(new NullLogger());

        $saved = $service->setExternalUid((string) $this->user->email, 'cm-georg');

        $this->assertSame('cm-georg', (string) $saved->external_uid);
    }

    // 2) Postfach-Anbindung nur noch verschlüsselt
    // --------------------------------------------

    public function testMailboxSubmissionWithoutEncryptionIsRejected(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '143',
            'imap_encryption' => 'none',
            'imap_username' => 'run29@example.org',
            'imap_password' => 'S3cr3t-Imap-Pass',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotNull($_SESSION['error'] ?? null);
        $this->assertNull(
            UserMailAccount::where('user_id', $this->user->id)->first(),
            'Ein Zugang ohne Verschlüsselung darf nicht entstehen.'
        );
    }

    /**
     * @return list<array{0:string}>
     */
    public static function encryptedTransports(): array
    {
        return [['ssl'], ['tls']];
    }

    #[DataProvider('encryptedTransports')]
    public function testMailboxSubmissionWithEncryptionStillWorks(string $encryption): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => $encryption,
            'imap_username' => 'run29@example.org',
            'imap_password' => 'S3cr3t-Imap-Pass',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame($encryption, (string) $account->imap_encryption);
    }

    // 3) Zwischenspeicher des HTML-Filters im Projekt
    // -----------------------------------------------

    public function testTheHtmlFilterCachesInsideTheProjectAndNotInTheTempDirectory(): void
    {
        $cacheDir = HtmlSanitizer::cacheDir();

        $this->assertNotNull($cacheDir, 'Im Testlauf muss var/ beschreibbar sein.');
        $this->assertStringStartsWith(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var',
            $cacheDir,
            'Der Zwischenspeicher gehört neben die Rate-Limit-Zähler, nicht ins Temp-Verzeichnis.'
        );
        $this->assertStringNotContainsString(sys_get_temp_dir(), $cacheDir);
    }

    public function testTheSanitizerKeepsWorkingAndFillsThatCache(): void
    {
        $sanitizer = new HtmlSanitizer();

        $this->assertSame('<p>Hallo</p>', $sanitizer->sanitizeTaskHtml('<p>Hallo</p><script>alert(1)</script>'));

        $cacheDir = HtmlSanitizer::cacheDir();
        $this->assertNotNull($cacheDir);
        $this->assertDirectoryExists($cacheDir);
    }
}
