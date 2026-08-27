<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailCredentialCryptoService;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein eingerichtetes Postfach bleibt in der Topbar sichtbar, auch ohne neue Mails.
 *
 * Vorher verschwand das komplette Mail-Element bei 0 ungelesenen Nachrichten, weil
 * die Bedingung im Partial auf "> 0" prüfte. Damit war der Schnellzugriff aufs
 * Postfach genau dann weg, wenn alles gelesen war - der Benutzer musste den Umweg
 * über das Profil nehmen und konnte nicht unterscheiden, ob der Abruf kaputt war
 * oder nur nichts Neues vorlag.
 *
 * Die rote Zähler-Pille erscheint weiterhin nur bei mindestens einer ungelesenen
 * Nachricht: Rot signalisiert Handlungsbedarf und wäre bei 0 irreführend.
 */
final class MailBadgeAlwaysVisibleFeatureTest extends TestCase
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

    /**
     * Twig muss VOR dem Setzen der Session gebaut werden: die Twig-Factory ruft
     * session_start(), was in einem frischen CLI-Prozess ein zuvor von Hand
     * befülltes $_SESSION-Array wieder leert - der Benutzer wäre dann anonym und
     * das Badge grundsätzlich leer, unabhängig vom getesteten Verhalten.
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

        $view = $container->get(Twig::class);
        $this->assertInstanceOf(Twig::class, $view);

        return $view;
    }

    private function renderUserMenuFor(int $unseen): string
    {
        $view = $this->buildTwig();

        $this->user = User::create([
            'first_name' => 'Badge',
            'last_name' => 'Visible',
            'email' => 'badge.visible.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_encryption' => 'none',
            'imap_username' => 'badge.visible@example.test',
            'imap_password_enc' => (new MailCredentialCryptoService())->encrypt('irrelevant'),
            'imap_enabled' => true,
            'mail_badge_enabled' => true,
            'mail_last_unseen_count' => $unseen,
            'mail_last_uid_seen' => '42',
            'mail_last_checked_at' => Carbon::now()->subMinutes(10),
            'external_webmail_url' => null,
        ]);

        $_SESSION['user_id'] = (int) $this->user->id;

        return $view->getEnvironment()->render('partials/navigation/user_menu.twig');
    }

    public function testMailElementStaysVisibleWithoutUnreadMessages(): void
    {
        $html = $this->renderUserMenuFor(0);

        $this->assertStringContainsString('mail-badge-trigger', $html);
        $this->assertStringContainsString('bi-envelope-fill', $html);
    }

    /**
     * Die Pille steckt bei 0 zwar im Markup, ist aber unsichtbar (`d-none`).
     *
     * Sie bleibt im DOM, damit die Fokus-Abfrage des Frontends den Zähler nur ein-
     * und ausblenden muss, statt das Markup samt Klassenliste in JavaScript
     * nachzubauen - so gibt es die Gestaltung der Pille weiterhin nur an einer Stelle.
     */
    public function testCountPillIsHiddenWithoutUnreadMessages(): void
    {
        $html = $this->renderUserMenuFor(0);

        $this->assertStringContainsString('mail-badge-count', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*mail-badge-count[^"]*\bd-none\b/', $html);
    }

    public function testCountPillAppearsWithUnreadMessages(): void
    {
        $html = $this->renderUserMenuFor(3);

        $this->assertStringContainsString('mail-badge-trigger', $html);
        $this->assertStringContainsString('mail-badge-count', $html);
        $this->assertStringContainsString('>3</span>', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*mail-badge-count[^"]*\bd-none\b/',
            $html
        );
    }

    public function testNoMailElementWithoutAConfiguredMailbox(): void
    {
        $html = $this->renderUserMenuFor(0);
        $this->assertStringContainsString('mail-badge-trigger', $html);

        UserMailAccount::query()->where('user_id', $this->user->id)->update(['imap_enabled' => false]);

        $htmlWithoutMailbox = $this->buildTwig()
            ->getEnvironment()
            ->render('partials/navigation/user_menu.twig');

        $this->assertStringNotContainsString('mail-badge-trigger', $htmlWithoutMailbox);
    }
}
