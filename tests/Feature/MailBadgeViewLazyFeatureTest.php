<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailBadgeViewService;
use App\Services\MailCredentialCryptoService;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * The mail badge must be resolved while rendering, not while Twig is built.
 *
 * Twig can be constructed before the request is authenticated (global middleware
 * runs before the route-level AuthMiddleware), so the former
 * `mail_badge_unseen_count` global was computed against an empty session and the
 * badge silently vanished on the first request after a deploy - the same root
 * cause that dropped the navbar.
 */
final class MailBadgeViewLazyFeatureTest extends TestCase
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
        if ($this->user !== null) {
            UserMailAccount::query()->where('user_id', $this->user->id)->delete();
            $this->user->delete();
            $this->user = null;
        }

        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    private function createUserWithBadge(int $unseen, ?string $externalUrl): User
    {
        $this->user = User::create([
            'first_name' => 'Badge',
            'last_name' => 'View',
            'email' => 'badge.view.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'badge.view@example.test',
            'imap_password_enc' => (new MailCredentialCryptoService())->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => $unseen,
            'mail_last_uid_seen' => '42',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
            'external_webmail_url' => $externalUrl,
        ]);

        return $this->user;
    }

    private function buildTwig(): Twig
    {
        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        $container = $containerBuilder->build();
        $container->get(Capsule::class);

        $view = $container->get(Twig::class);
        $this->assertInstanceOf(Twig::class, $view);

        return $view;
    }

    public function testBadgeIsResolvedForALoginRestoredAfterTwigWasBuilt(): void
    {
        $view = $this->buildTwig();

        $user = $this->createUserWithBadge(3, 'https://webmail.example.test/');
        $_SESSION['user_id'] = (int) $user->id;

        $template = $view->getEnvironment()->createTemplate(
            '{{ mail_badge().unseen_count }}|{{ mail_badge().external_webmail_url }}'
        );

        $this->assertSame('3|https://webmail.example.test/', $template->render([]));
    }

    public function testBadgeStaysNullWithoutASessionUser(): void
    {
        $view = $this->buildTwig();

        $template = $view->getEnvironment()->createTemplate(
            '{% if mail_badge().unseen_count is null %}none{% else %}some{% endif %}'
        );

        $this->assertSame('none', $template->render([]));
    }

    public function testServiceIgnoresAccountsWithTheBadgeDisabled(): void
    {
        $user = $this->createUserWithBadge(5, null);
        UserMailAccount::query()->where('user_id', $user->id)->update(['mail_badge_enabled' => false]);
        $_SESSION['user_id'] = (int) $user->id;

        $badge = (new MailBadgeViewService(new NullLogger()))->forCurrentUser();

        $this->assertNull($badge['unseen_count']);
        $this->assertNull($badge['external_webmail_url']);
    }

    public function testServiceReResolvesWhenTheSessionUserChanges(): void
    {
        $service = new MailBadgeViewService(new NullLogger());

        $this->assertNull($service->forCurrentUser()['unseen_count']);

        $user = $this->createUserWithBadge(7, null);
        $_SESSION['user_id'] = (int) $user->id;

        $this->assertSame(7, $service->forCurrentUser()['unseen_count']);
    }
}
