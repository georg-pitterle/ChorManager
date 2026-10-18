<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Logging\RequestContext;
use App\Models\MailQueue;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\MailDeliveryService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use App\Services\RememberLoginRestoreService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\TwigFilter;

/**
 * Letzte Anmeldung und Versand der Einladung werden am Mitglied festgehalten.
 *
 * Vorher ließ sich beides nur aus Logs oder aus `invitation_tokens` erraten:
 * Der Token verschwindet beim Einlösen, aber auch beim Aufruf eines
 * abgelaufenen Links, und ob die Mail je beim Mailserver ankam, stand nur in
 * der Warteschlange, die keinem Mitglied fest zugeordnet ist.
 */
final class AccountActivityTrackingFeatureTest extends TestCase
{
    private static bool $failInvitationSentUpdate = false;
    private static bool $sabotageRegistered = false;

    private User $user;
    private string $password = 'Correct-Horse-5';
    private string $rateLimiterStoreDir;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->user = User::create([
            'first_name' => 'Aktivitäts',
            'last_name' => 'Prüferin',
            'email' => 'activity.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash($this->password),
            'is_active' => 1,
        ]);

        $this->rateLimiterStoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'chormanager_activity_test_' . bin2hex(random_bytes(4));

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION = [];
        $_COOKIE = [];

        // Der Worker zieht die ganze fällige Warteschlange; fremde Zeilen
        // verfälschten sonst, welche Mail hier verschickt wird. Läuft innerhalb
        // der Transaktion und wird zurückgerollt.
        MailQueue::query()->delete();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        $_COOKIE = [];

        if (is_dir($this->rateLimiterStoreDir)) {
            foreach (glob($this->rateLimiterStoreDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->rateLimiterStoreDir);
        }

        parent::tearDown();
    }

    public function testSuccessfulPasswordLoginRecordsTheLoginTime(): void
    {
        $before = Carbon::now()->subSecond();

        $response = $this->authController()->processLogin($this->loginRequest($this->password), new SlimResponse());

        $this->assertSame((int) $this->user->id, $_SESSION['user_id'] ?? null, 'Die Anmeldung selbst muss gelingen.');
        $this->assertSame(302, $response->getStatusCode());
        $lastLogin = $this->user->fresh()->last_login_at;
        $this->assertNotNull($lastLogin, 'Eine erfolgreiche Anmeldung muss last_login_at setzen.');
        $this->assertTrue(Carbon::parse($lastLogin)->greaterThanOrEqualTo($before));
    }

    public function testFailedPasswordLoginLeavesTheLoginTimeEmpty(): void
    {
        $this->authController()->processLogin($this->loginRequest('falsches-Passwort'), new SlimResponse());

        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertNull($this->user->fresh()->last_login_at);
    }

    public function testRememberMeRestoreRecordsTheLoginTime(): void
    {
        // Wer "angemeldet bleiben" gewählt hat, tippt sein Passwort monatelang
        // nicht ein. Zählte nur der Passwortweg, sähe ein täglich aktives
        // Mitglied aus, als käme es nie.
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/dashboard');
        $_COOKIE[RememberLoginService::COOKIE_NAME] = (new RememberLoginService())
            ->issueForUser((int) $this->user->id, $request);

        $restored = $this->restoreService()->restoreFromCookie($request);

        $this->assertTrue($restored);
        $this->assertNotNull($this->user->fresh()->last_login_at);
    }

    public function testInvitationAcceptedByTheMailServerRecordsTheSendTime(): void
    {
        $this->enqueue(MailQueue::MAIL_TYPE_INVITATION);

        (new MailDeliveryService($this->mailerReturning(['success' => true, 'skipped' => false])))
            ->processDueEntries(10);

        $this->assertNotNull(
            $this->user->fresh()->invitation_sent_at,
            'Hat der Mailserver die Einladung angenommen, muss invitation_sent_at gesetzt sein.'
        );
    }

    public function testAFailingSendTimeUpdateDoesNotTurnTheSentInvitationIntoAFailure(): void
    {
        // Die Mail ist zu diesem Zeitpunkt schon beim Mailserver. Risse ein
        // Datenbankfehler beim Festhalten den Eintrag in die Fehlerbehandlung,
        // stünde eine zugestellte Mail als gescheitert da und ginge beim
        // nächsten Versuch ein zweites Mal hinaus.
        $entry = $this->enqueue(MailQueue::MAIL_TYPE_INVITATION);
        MailQueue::query()->whereKey($entry->id)->update(['max_attempts' => 3]);
        self::$failInvitationSentUpdate = true;
        self::registerInvitationSentSabotage();

        try {
            $stats = (new MailDeliveryService($this->mailerReturning(['success' => true, 'skipped' => false])))
                ->processDueEntries(10);
        } finally {
            self::$failInvitationSentUpdate = false;
        }

        $stored = MailQueue::findOrFail($entry->id);
        $this->assertSame(MailQueue::STATUS_SENT, $stored->status);
        $this->assertSame(1, (int) $stored->attempts);
        $this->assertSame(1, $stats['sent']);
        $this->assertNull($this->user->fresh()->invitation_sent_at);
    }

    public function testFailedInvitationLeavesTheSendTimeEmpty(): void
    {
        $this->enqueue(MailQueue::MAIL_TYPE_INVITATION);

        (new MailDeliveryService($this->mailerReturning(['success' => false])))->processDueEntries(10);

        $this->assertNull($this->user->fresh()->invitation_sent_at);
    }

    public function testInvitationSkippedBecauseSendingIsDisabledLeavesTheSendTimeEmpty(): void
    {
        // Mit abgeschaltetem Versand verlässt die Mail das Haus nie - als
        // "gesendet" zu gelten wäre eine Falschaussage.
        $this->enqueue(MailQueue::MAIL_TYPE_INVITATION);

        (new MailDeliveryService($this->mailerReturning([
            'success' => true,
            'skipped' => true,
            'provider_name' => 'disabled',
        ])))->processDueEntries(10);

        $this->assertNull($this->user->fresh()->invitation_sent_at);
    }

    public function testOtherMailTypesForTheMemberLeaveTheInvitationTimeEmpty(): void
    {
        $this->enqueue(MailQueue::MAIL_TYPE_PASSWORD_RESET);

        (new MailDeliveryService($this->mailerReturning(['success' => true, 'skipped' => false])))
            ->processDueEntries(10);

        $this->assertNull($this->user->fresh()->invitation_sent_at);
    }

    public function testEditFormShowsBothTimesCollapsedForMemberManagers(): void
    {
        $this->user->last_login_at = '2026-10-12 19:30:00';
        $this->user->invitation_sent_at = '2026-10-01 08:15:00';
        $this->user->save();

        $html = $this->renderEditForm(canInvite: true);

        // Eingeklappt: Die Angaben braucht man selten und sollen das Formular
        // nicht verlängern.
        $this->assertMatchesRegularExpression('/<details[^>]*>\s*<summary[^>]*>[^<]*Konto/u', $html);
        $this->assertStringNotContainsString('<details open', $html);
        $this->assertStringContainsString('12.10.2026, 19:30', $html);
        $this->assertStringContainsString('01.10.2026, 08:15', $html);
    }

    public function testEditFormSaysWhenNothingWasRecorded(): void
    {
        $html = $this->renderEditForm(canInvite: true);

        $this->assertStringContainsString('Letzte Anmeldung', $html);
        $this->assertStringContainsString('Einladung gesendet', $html);
        $this->assertSame(2, substr_count($html, 'nicht erfasst'));
    }

    public function testEditFormHidesTheTimesWithoutMemberManagement(): void
    {
        $this->user->last_login_at = '2026-10-12 19:30:00';
        $this->user->save();

        $html = $this->renderEditForm(canInvite: false);

        $this->assertStringNotContainsString('Letzte Anmeldung', $html);
        $this->assertStringNotContainsString('12.10.2026', $html);
    }

    /**
     * Lässt jede Abfrage scheitern, die `invitation_sent_at` schreibt, solange
     * der Schalter steht. Ein Abmelden kennt die Verbindung nicht; der Haken
     * bleibt deshalb einmal je Prozess registriert und ist ohne Schalter wirkungslos.
     */
    private static function registerInvitationSentSabotage(): void
    {
        if (self::$sabotageRegistered) {
            return;
        }

        Capsule::connection()->beforeExecuting(static function (string $query): void {
            if (self::$failInvitationSentUpdate && str_contains($query, 'invitation_sent_at')) {
                throw new \RuntimeException('Simulierter Datenbankfehler.');
            }
        });
        self::$sabotageRegistered = true;
    }

    public function testMemberListLoadsBothTimesForFilterAndStatus(): void
    {
        // Die Liste lädt nur ausgewählte Spalten (User::LIST_COLUMNS). Fehlte
        // invitation_sent_at darin, stünde jede Zeile auf "nicht gesendet"; ohne
        // last_login_at stünde jede neue Einladung auf "offen".
        $this->user->invitation_sent_at = '2026-10-01 08:15:00';
        $this->user->last_login_at = '2026-10-02 09:00:00';
        $this->user->save();

        $listed = (new UserQuery(new NameFormatterService()))->getActiveUsers()
            ->firstWhere('id', $this->user->id);

        $this->assertNotNull($listed);
        $this->assertNotNull($listed->invitation_sent_at);
        $this->assertNotNull($listed->last_login_at);
        $this->assertArrayNotHasKey('password', $listed->getAttributes());
    }

    public function testMemberListMarksEachRowAndUnlocksTheFilterOnlyForMemberManagers(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/users/manage.twig');

        $this->assertStringContainsString(
            '{% set _invitation_tokens = (user.invitation_sent_at ? "sent" : "not_sent") ~ (_pending ? "|pending" : "") %}',
            $twig
        );
        $this->assertStringContainsString('data-invitation="|{{ _invitation_tokens }}|"', $twig);
        $this->assertStringContainsString(
            'data-invitation-filter="{{ session.can_manage_users and not show_archived ? "1" : "0" }}"',
            $twig
        );
    }

    private function enqueue(string $mailType): MailQueue
    {
        return MailQueue::create([
            'mail_type' => $mailType,
            'recipient_email' => (string) $this->user->email,
            'subject' => 'Betreff',
            'body_html' => '<p>Inhalt</p>',
            'payload_json' => ['user_id' => (int) $this->user->id],
            'status' => MailQueue::STATUS_QUEUED,
            'attempts' => 0,
            'max_attempts' => 1,
            'is_retryable' => false,
            'next_attempt_at' => Carbon::now()->subMinute(),
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function mailerReturning(array $result): Mailer
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('isUsingSmtp')->willReturn(true);
        $mailer->method('sendHtmlMailDetailed')->willReturn($result + ['provider_message_id' => null]);
        $mailer->method('getLastError')->willReturn('550 5.1.1 Unbekannter Empfänger');

        return $mailer;
    }

    private function renderEditForm(bool $canInvite): string
    {
        $user = $this->user->fresh();
        $user->project_ids = [];
        $user->voice_group_ids = [];
        $user->voice_group_pivots = [];

        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $twig->getEnvironment()->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));

        return $twig->fetch('partials/user_edit_form.twig', [
            'user' => $user,
            'roles' => [],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [],
            'can_edit_users' => true,
            'can_edit_profile' => true,
            'can_edit_email' => true,
            'can_invite' => $canInvite,
            'can_manage_project_members' => false,
            'edit_state' => [],
        ]);
    }

    private function loginRequest(string $password): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '198.51.100.21'])
            ->withParsedBody([
                'email' => (string) $this->user->email,
                'password' => $password,
            ]);
    }

    private function authController(): AuthController
    {
        return new AuthController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new RememberLoginService(),
            new SessionAuthService(new NameFormatterService(), new RequestContext()),
            new RateLimiterService($this->rateLimiterStoreDir),
            new PasswordPolicyService(),
            new NullLogger()
        );
    }

    private function restoreService(): RememberLoginRestoreService
    {
        return new RememberLoginRestoreService(
            new RememberLoginService(),
            new UserQuery(new NameFormatterService()),
            new SessionAuthService(new NameFormatterService(), new RequestContext())
        );
    }
}
