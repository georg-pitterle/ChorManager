<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProfileController;
use App\Models\User;
use App\Models\UserNotificationSetting;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\NotificationService;
use App\Services\PasswordPolicyService;
use App\Util\NotificationType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Der Profil-Reiter "Benachrichtigungen".
 *
 * Zwei Dinge müssen stimmen: Ein Browser sendet leere Kästchen gar nicht mit -
 * ein fehlender Schlüssel heißt also "abgewählt", nicht "unverändert". Und
 * ausgewertet werden darf nur, was das Formular auch anbieten durfte.
 */
final class ProfileNotificationSettingsFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->user = User::create([
            'first_name' => 'Nora',
            'last_name' => 'Nachricht',
            'email' => 'profil.notify.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAIL_CREDENTIAL_KEY'] = $key;
        $_SERVER['MAIL_CREDENTIAL_KEY'] = $key;
        putenv('MAIL_CREDENTIAL_KEY=' . $key);

        $_SESSION = ['user_id' => (int) $this->user->id];
    }

    protected function tearDown(): void
    {
        UserNotificationSetting::where('user_id', $this->user->id)->delete();
        $this->user->delete();
        unset($_ENV['MAIL_CREDENTIAL_KEY'], $_SERVER['MAIL_CREDENTIAL_KEY']);
        putenv('MAIL_CREDENTIAL_KEY');
        $_SESSION = [];

        parent::tearDown();
    }

    public function testAnUncheckedBoxTurnsTheTypeOff(): void
    {
        $type = NotificationType::PROJECT_MEMBER_ADDED;

        // Alles ausser diesem Anlass bleibt angehakt.
        $this->submit($this->allCheckedExcept($type));

        $this->assertFalse($this->service()->wantsNotification((int) $this->user->id, $type));
        $this->assertSame('Deine Benachrichtigungen wurden gespeichert.', $_SESSION['success'] ?? null);
    }

    public function testCheckingItAgainRemovesTheStoredDeviation(): void
    {
        $type = NotificationType::PROJECT_MEMBER_ADDED;

        $this->submit($this->allCheckedExcept($type));
        $this->assertSame(1, UserNotificationSetting::where('user_id', $this->user->id)->count());

        $this->submit($this->allCheckedExcept(null));

        $this->assertTrue($this->service()->wantsNotification((int) $this->user->id, $type));
        $this->assertSame(
            0,
            UserNotificationSetting::where('user_id', $this->user->id)->count(),
            'Deckt sich die Entscheidung mit der Vorgabe, gehört keine Zeile in die Tabelle.'
        );
    }

    /**
     * Ein Anlass, dessen Modul aus ist, steht nicht im Formular - und darf
     * deshalb auch über einen zusammengebauten Aufruf nicht verstellt werden.
     */
    public function testATypeOfADisabledModuleCannotBeChangedThroughTheForm(): void
    {
        $controller = $this->controller(['tasks' => false, 'sponsoring' => false]);

        $response = $controller->updateNotificationSettings(
            $this->makeRequest('POST', '/profile/notifications', [
                'notifications' => [],
            ]),
            $this->makeResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            0,
            UserNotificationSetting::where('user_id', $this->user->id)
                ->where('notification_type', NotificationType::TASK_ASSIGNED)
                ->count(),
            'Ein Anlass ohne Modul darf über dieses Formular nicht abgeschaltet werden.'
        );
    }

    public function testTheFormOnlyOffersTypesOfActiveModules(): void
    {
        $withoutTasks = $this->service(['tasks' => false, 'sponsoring' => true]);

        $this->assertNotContains(NotificationType::TASK_ASSIGNED, $withoutTasks->availableTypes());
        $this->assertContains(NotificationType::EVENT_CREATED, $withoutTasks->availableTypes());
        $this->assertContains(NotificationType::SPONSORING_FOLLOW_UP_DUE, $withoutTasks->availableTypes());
    }

    /**
     * @return array<string, string>
     */
    private function allCheckedExcept(?string $excluded): array
    {
        $checked = [];
        foreach ($this->service()->availableTypes() as $type) {
            if ($type !== $excluded) {
                $checked[$type] = '1';
            }
        }

        return $checked;
    }

    /**
     * @param array<string, string> $notifications
     */
    private function submit(array $notifications): void
    {
        unset($_SESSION['error'], $_SESSION['success']);

        $this->controller()->updateNotificationSettings(
            $this->makeRequest('POST', '/profile/notifications', ['notifications' => $notifications]),
            $this->makeResponse()
        );
    }

    /**
     * @param array<string, bool> $modules
     */
    private function service(array $modules = ['tasks' => true, 'sponsoring' => true]): NotificationService
    {
        return new NotificationService(
            new MailQueueService(),
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new NullLogger(),
            $modules
        );
    }

    /**
     * @param array<string, bool> $modules
     */
    private function controller(array $modules = ['tasks' => true, 'sponsoring' => true]): ProfileController
    {
        return new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            new NullLogger(),
            // `final` - laesst sich nicht doubeln, also der echte Dienst mit
            // einem Wegwerf-Schluessel. Dieser Test verschluesselt ohnehin nichts.
            new MailCredentialCryptoService(),
            null,
            $this->service($modules)
        );
    }
}
