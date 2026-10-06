<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Notifications\InAppMessage;
use App\Services\Notifications\InAppNotificationStore;
use App\Util\NotificationType;
use App\Util\PasswordHasher;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Glocke in der Kopfzeile: immer da für angemeldete Personen, die rote
 * Zahl nur bei ungelesenen Einträgen.
 *
 * Die Pille steckt wie beim Mail-Badge immer im Markup und wird bei 0 nur
 * ausgeblendet, damit notification-bell.js sie bloß umschreiben muss.
 */
final class NotificationBellRenderFeatureTest extends TestCase
{
    private ?User $user = null;

    /** @var array<string,mixed> */
    private array $originalSession = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Die Einträge hängen per Fremdschlüssel an der Person und gehen mit.
        $this->user?->delete();
        $this->user = null;
        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    public function testTheBellShowsTheUnreadCount(): void
    {
        $html = $this->renderUserMenuWithUnread(3);

        $this->assertStringContainsString('data-notification-bell', $html);
        $this->assertStringContainsString('bi-bell-fill', $html);
        $this->assertMatchesRegularExpression('/data-notification-bell-count>\s*3\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*notification-bell-count[^"]*\bd-none\b/', $html);
    }

    public function testTheCountIsHiddenWithoutUnreadEntries(): void
    {
        $html = $this->renderUserMenuWithUnread(0);

        $this->assertStringContainsString('data-notification-bell', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*notification-bell-count[^"]*\bd-none\b/', $html);
    }

    public function testALargeCountIsCapped(): void
    {
        $html = $this->renderUserMenuWithUnread(150);

        $this->assertMatchesRegularExpression('/data-notification-bell-count>\s*99\+\s*</', $html);
    }

    public function testNobodySignedInSeesNoBell(): void
    {
        $html = $this->buildTwig()->getEnvironment()->render('partials/navigation/user_menu.twig');

        $this->assertStringNotContainsString('data-notification-bell', $html);
    }

    /**
     * Twig muss vor dem Setzen der Sitzung entstehen: Die Fabrik ruft
     * session_start(), was ein zuvor befülltes $_SESSION wieder leert.
     */
    private function buildTwig(): Twig
    {
        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        $container = $containerBuilder->build();
        $container->get(Capsule::class);

        return $container->get(Twig::class);
    }

    private function renderUserMenuWithUnread(int $unread): string
    {
        $view = $this->buildTwig();

        $this->user = User::create([
            'first_name' => 'Glocke',
            'last_name' => 'Sichtbar',
            'email' => 'bell.render.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);

        $store = new InAppNotificationStore();
        for ($i = 0; $i < $unread; $i++) {
            $store->createMany(
                NotificationType::TASK_COMMENT,
                new InAppMessage('Eintrag ' . $i, null, '/tasks/1'),
                [(int) $this->user->id],
                null
            );
        }

        $_SESSION['user_id'] = (int) $this->user->id;

        return $view->getEnvironment()->render('partials/navigation/user_menu.twig');
    }
}
