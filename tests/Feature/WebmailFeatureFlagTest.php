<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserMailAccount;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class WebmailFeatureFlagTest extends TestCase
{
    public function testUserMailAccountHasExternalWebmailUrlFillable(): void
    {
        $this->assertContains('external_webmail_url', (new UserMailAccount())->getFillable());
    }

    public function testMigrationForExternalWebmailUrlExists(): void
    {
        $migration = dirname(__DIR__, 2)
            . '/db/migrations/20260704200000_add_external_webmail_url_to_user_mail_accounts.php';
        $this->assertFileExists($migration);

        $content = file_get_contents($migration);
        $this->assertIsString($content);
        $this->assertStringContainsString("'external_webmail_url'", $content);
        $this->assertStringContainsString("'null' => true", $content);
    }

    public function testSettingsExposeWebmailFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'webmail'\\s*=>\\s*EnvHelper::read\\('FEATURE_WEBMAIL', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testWebmailRouteIsRegisteredOnlyInsideFeatureGate(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        $gatePos = strpos($content, "if (\$settings['modules']['webmail'] ?? false) {");
        $routePos = strpos($content, "'/profile/webmail/start'");

        $this->assertNotFalse($gatePos, 'Webmail feature gate missing in Routes.php.');
        $this->assertNotFalse($routePos, 'Webmail route missing in Routes.php.');
        $this->assertGreaterThan($gatePos, $routePos, 'Webmail route must be inside the feature gate.');
    }

    public function testWebmailRouteRegistrationRespectsFeatureFlag(): void
    {
        $this->assertFalse($this->webmailStartRouteIsRegistered(false));
        $this->assertTrue($this->webmailStartRouteIsRegistered(true));
    }

    /**
     * Builds a minimal real Slim App (no DB/session/middleware) and registers the actual
     * src/Routes.php route definitions against it, then inspects the real RouteCollector
     * to confirm the feature-gated route is only present when the flag is enabled.
     *
     * Note: AppFactory::setContainer() mutates Slim's process-global static factory state.
     * No other test in this suite currently calls AppFactory::create(); if a future test
     * does, make sure it also sets/resets the container to avoid cross-test leakage.
     */
    private function webmailStartRouteIsRegistered(bool $webmailEnabled): bool
    {
        $settings = ['modules' => ['webmail' => $webmailEnabled]];

        $container = new class ($settings) implements \Psr\Container\ContainerInterface {
            public function __construct(private array $settings)
            {
            }

            public function get(string $id): mixed
            {
                return $id === 'settings' ? $this->settings : null;
            }

            public function has(string $id): bool
            {
                return $id === 'settings';
            }
        };

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $routes = require dirname(__DIR__, 2) . '/src/Routes.php';
        $routes($app);

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if ($route->getPattern() === '/profile/webmail/start' && in_array('POST', $route->getMethods(), true)) {
                return true;
            }
        }

        return false;
    }

    public function testProfileTemplateGatesExternalUrlFieldAndSsoButton(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/profile/index.twig');
        $this->assertIsString($template);

        // URL-Feld nur bei abgeschaltetem Flag.
        $this->assertStringContainsString('{% if not settings.modules.webmail %}', $template);
        $this->assertStringContainsString('name="external_webmail_url"', $template);

        // SSO-Button nur bei aktivem Flag.
        $this->assertStringContainsString(
            '{% if settings.modules.webmail and webmail_available %}',
            $template
        );

        // Externer Link-Button bei Flag aus + gesetzter URL.
        $this->assertStringContainsString('{% set _external_url =', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);
    }

    /**
     * The badge (including the external webmail URL) is exposed as a Twig function,
     * not as a global: globals are evaluated while Twig is built, which can happen
     * before the request is authenticated.
     */
    public function testDependenciesExposeTheMailBadgeAsALazyFunction(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("'mail_badge',", $content);
        $this->assertStringNotContainsString("addGlobal('mail_external_webmail_url'", $content);
        $this->assertStringNotContainsString("addGlobal('mail_badge_unseen_count'", $content);
    }

    public function testUserMenuBadgeCoversAllThreeWebmailCases(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/user_menu.twig');
        $this->assertIsString($template);

        // Fall 1: Flag an -> SSO-Formular, oeffnet in neuem Tab.
        $this->assertStringContainsString('{% if settings.modules.webmail %}', $template);
        $this->assertStringContainsString(
            '<form action="/profile/webmail/start" method="post" class="m-0 me-3" target="_blank">',
            $template
        );

        // Fall 2: Flag aus + externe URL -> Link.
        $this->assertStringContainsString('{% set _mail_badge = mail_badge() %}', $template);
        $this->assertStringContainsString('{% elseif _external_webmail_url %}', $template);
        $this->assertStringContainsString('href="{{ _external_webmail_url }}"', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);

        // Fall 3: Flag aus, keine URL -> reiner Indikator ohne Form/Link. Der Titel
        // nennt bewusst das Postfach und nicht "Ungelesene Nachrichten": das Element
        // ist auch bei 0 ungelesenen Nachrichten sichtbar.
        $this->assertStringContainsString('title="Postfach"', $template);
        $this->assertStringNotContainsString('title="Ungelesene Nachrichten"', $template);
    }

    public function testEnvExamplesDocumentFeatureWebmail(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_WEBMAIL=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_WEBMAIL=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_WEBMAIL: ${FEATURE_WEBMAIL:-false}', $compose);
    }

    public function testProdComposeGivesAppEgressForImapConnections(): void
    {
        // The app container runs the IMAP connection test and the mail-badge
        // polling, both of which must reach the user's external mail server.
        // With the app attached only to the internal:true network it has no
        // egress, so every connection test fails with the generic
        // "Host ist nicht erreichbar." The app therefore needs a second,
        // non-internal bridge network for outbound traffic, while the db stays
        // isolated on internal only.
        $compose = file_get_contents(dirname(__DIR__, 2) . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);

        // The app service must exist (uniquely identified by its FastCGI alias)
        // and reference the egress network. A network reference indented with
        // six spaces sits under a service's "networks:" block; only the app
        // service references egress, so this asserts the app is on it.
        $this->assertStringContainsString('- chormanager-fpm', $compose);
        $this->assertStringContainsString("\n      egress:\n", $compose);

        // The egress network must be a plain bridge WITHOUT internal: true,
        // otherwise it would provide no outbound connectivity.
        $this->assertStringContainsString(
            "  egress:\n"
            . "    driver: bridge\n",
            $compose
        );
        $this->assertDoesNotMatchRegularExpression(
            '/  egress:\n    driver: bridge\n    internal: true/',
            $compose
        );
    }

    public function testProdReadmeWebmailProxyStripsPrefixViaRewrite(): void
    {
        // SWAG proxies to a variable upstream (needed for its resolver). With a
        // variable upstream, proxy_pass does NOT strip the /webmail/ prefix via a
        // trailing slash the way a literal upstream does — it forwards the request
        // unchanged or drops the query, so Tachyon's /webmail/?/AppData/... AJAX
        // calls get its HTML shell back ("Invalid Content-Type text/html"). The
        // documented config must therefore strip the prefix with an explicit
        // rewrite and must not rely on the trailing-slash form.
        $readme = file_get_contents(dirname(__DIR__, 2) . '/dist/README.md');
        $this->assertIsString($readme);

        $this->assertStringContainsString('rewrite ^/webmail/(.*) /$1 break;', $readme);
        $this->assertStringNotContainsString('proxy_pass http://$upstream_sm:8888/;', $readme);
        $this->assertStringNotContainsString('proxy_pass http://$upstream_sm:8888/tachyon/;', $readme);
    }

    /**
     * Tachyon liefert seine statischen Assets unter dem absoluten Prefix
     * /tachyon/ aus (nginx-root im Image ist /tachyon). Die dokumentierte
     * SWAG-Config muss genau diesen Pfad durchreichen, sonst lädt das Webmail
     * in Prod ohne CSS/JS.
     */
    public function testProdReadmeDocumentsTheTachyonAssetLocation(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/dist/README.md');
        $this->assertIsString($readme);

        $this->assertStringContainsString('location /tachyon/ {', $readme);
        $this->assertStringNotContainsString('location /snappymail/ {', $readme);

        // Both /webmail/ and /tachyon/ location blocks must use the new upstream.
        // A regression where only one block reverts to the old chormanager-snappymail-prod
        // would cause 404s for webmail assets. Assert exactly 2 occurrences of the
        // new upstream line to catch this.
        $this->assertSame(
            2,
            substr_count($readme, 'set $upstream_sm chormanager-webmail-prod;'),
            'Upstream must be set in both /webmail/ and /tachyon/ location blocks'
        );

        // Extract all nginx config blocks to verify the old upstream is not active.
        // The migration documentation may mention the old name in prose, but no nginx
        // code block itself must use it. A failing match (e.g. a renamed fence such as
        // ```conf) must fail the test loudly instead of silently skipping the check.
        $matchCount = preg_match_all('/```nginx\s*(.*?)\s*```/s', $readme, $matches);
        $this->assertGreaterThan(0, $matchCount, 'No ```nginx code blocks found in dist/README.md.');

        // $matches[1] contains all captured nginx blocks
        $allNginxBlocks = implode("\n", $matches[1]);
        $this->assertStringNotContainsString(
            'chormanager-snappymail-prod',
            $allNginxBlocks,
            'Old upstream name must not appear in any nginx code block'
        );
    }

    public function testProfileTemplateHasDeleteMailboxForm(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/profile/index.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString('{% if has_saved_account %}', $template);
        $this->assertStringContainsString('action="/profile/mailbox/delete"', $template);
        $this->assertStringContainsString('data-confirm="Mailbox-Zugang wirklich entfernen?', $template);
    }
}
