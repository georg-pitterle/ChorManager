<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProfileController;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Kalendereinstellung im Profil.
 *
 * Beide Felder sind Auswahlfelder mit fester Werteliste - genau deshalb muss der
 * Server sie prüfen: Ein abweichender Wert käme sonst am ENUM der Spalte an und
 * endete in einer QueryException statt in einer Meldung.
 */
final class ProfileCalendarSettingsFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private ProfileController $controller;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->controller = new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            new NullLogger(),
            new MailCredentialCryptoService()
        );

        $this->user = User::create([
            'first_name' => 'Kalender',
            'last_name' => 'Einstellung',
            'email' => 'kalender.profil.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $_SESSION = ['user_id' => (int) $this->user->id];
    }

    protected function tearDown(): void
    {
        $this->user->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    public function testTheDefaultIsTheCombinedFeedWithAllDayEntries(): void
    {
        // Die Vorgabe steht am ENUM der Spalte, nicht im Modell - gelesen wird
        // sie deshalb erst nach dem Nachladen aus der Datenbank.
        $this->user->refresh();

        $this->assertSame(User::CALENDAR_TASK_FEED_COMBINED, (string) $this->user->calendar_task_feed);
        $this->assertSame(User::CALENDAR_TASK_FORMAT_EVENT, (string) $this->user->calendar_task_format);
    }

    public function testAValidChoiceIsStored(): void
    {
        $response = $this->submit(User::CALENDAR_TASK_FEED_SEPARATE, User::CALENDAR_TASK_FORMAT_TODO);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($_SESSION['error'] ?? null);

        $this->user->refresh();
        $this->assertSame(User::CALENDAR_TASK_FEED_SEPARATE, (string) $this->user->calendar_task_feed);
        $this->assertSame(User::CALENDAR_TASK_FORMAT_TODO, (string) $this->user->calendar_task_format);
    }

    public function testAnUnknownFeedIsRejectedAndNothingIsStored(): void
    {
        $this->submit('alles', User::CALENDAR_TASK_FORMAT_EVENT);

        $this->assertSame(
            'Ungültige Auswahl für die Aufgaben im Kalender.',
            $_SESSION['error'] ?? null
        );

        $this->user->refresh();
        $this->assertSame(User::CALENDAR_TASK_FEED_COMBINED, (string) $this->user->calendar_task_feed);
    }

    /**
     * Die Darstellung wird vor dem Speichern geprüft, nicht danach - sonst
     * stünde die eine Hälfte der Eingabe schon in der Datenbank.
     */
    public function testAnUnknownFormatLeavesTheFeedUntouched(): void
    {
        $this->submit(User::CALENDAR_TASK_FEED_NONE, 'sprachnachricht');

        $this->assertSame(
            'Ungültige Auswahl für die Darstellung der Aufgaben.',
            $_SESSION['error'] ?? null
        );

        $this->user->refresh();
        $this->assertSame(User::CALENDAR_TASK_FEED_COMBINED, (string) $this->user->calendar_task_feed);
        $this->assertSame(User::CALENDAR_TASK_FORMAT_EVENT, (string) $this->user->calendar_task_format);
    }

    private function submit(string $feed, string $format): \Psr\Http\Message\ResponseInterface
    {
        unset($_SESSION['error'], $_SESSION['success']);

        return $this->controller->updateCalendarSettings(
            $this->makeRequest('POST', '/profile/calendar', [
                'calendar_task_feed' => $feed,
                'calendar_task_format' => $format,
            ]),
            $this->makeResponse()
        );
    }
}
