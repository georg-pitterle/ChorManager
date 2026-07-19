<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Services\MailQueueAdminService;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Real behavioral coverage for whether a plain member (no admin/voice-rep
 * rights) can actually reach the registration self-service and its
 * evaluation via the nav menu, once FEATURE_REGISTRATION is on. Both the
 * "Termine" and "Auswertungen" dropdowns were previously gated entirely
 * behind admin-only session flags, which hid the member-facing
 * registration links even though their routes were reachable directly.
 */
class NavigationVisibilityFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        $_SESSION = [
            'user_id' => 1,
            'can_manage_users' => false,
            'can_manage_attendance' => false,
            'can_manage_project_members' => false,
            'role_level' => 0,
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testPlainMemberSeesRegistrationNavLinksWhenFeatureEnabled(): void
    {
        $body = $this->renderDashboard(['registration' => true]);

        $this->assertStringContainsString('</i> Termine</a>', $body);
        $this->assertStringContainsString('href="/registrations"', $body);
        $this->assertStringContainsString('</i> Auswertungen</a>', $body);
        $this->assertStringContainsString('href="/evaluations/registrations"', $body);

        // Admin-only sub-items stay individually gated, unaffected by the fix.
        $this->assertStringNotContainsString('href="/attendance"', $body);
        $this->assertStringNotContainsString('</i> Anwesenheitsquoten</a>', $body);
    }

    public function testDropdownsStayHiddenForPlainMemberWhenFeatureDisabled(): void
    {
        $body = $this->renderDashboard(['registration' => false]);

        $this->assertStringNotContainsString('</i> Termine</a>', $body);
        $this->assertStringNotContainsString('href="/registrations"', $body);
        $this->assertStringNotContainsString('</i> Auswertungen</a>', $body);
        $this->assertStringNotContainsString('href="/evaluations/registrations"', $body);
    }

    public function testPrivilegedMemberStillSeesAdminSubItemsRegardlessOfFeatureFlag(): void
    {
        $_SESSION['can_manage_attendance'] = true;
        $_SESSION['role_level'] = 50;

        $body = $this->renderDashboard(['registration' => false]);

        $this->assertStringContainsString('</i> Termine</a>', $body);
        $this->assertStringContainsString('href="/attendance"', $body);
        $this->assertStringContainsString('</i> Auswertungen</a>', $body);
        $this->assertStringContainsString('</i> Anwesenheitsquoten</a>', $body);
    }

    /**
     * @param array<string, bool> $modules
     */
    private function renderDashboard(array $modules): string
    {
        $settings = ['modules' => $modules];
        $controller = new DashboardController($this->createTwig($settings), new MailQueueAdminService(), $settings);

        $request = $this->makeRequest('GET', '/dashboard');
        $response = $controller->index($request, $this->makeResponse());

        return (string) $response->getBody();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createTwig(array $settings): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addGlobal('settings', $settings);
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/dashboard');
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('csrf_token', 'test-token');
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static function (string $path): string {
                return $path;
            }
        ));
        $environment->addFunction(new TwigFunction(
            'nav_active',
            static function (
                string $path,
                ?string $activeNav = null,
                array $pathPrefixes = [],
                array $navKeys = [],
                array $excludePrefixes = []
            ): bool {
                foreach ($excludePrefixes as $excludePrefix) {
                    if ($excludePrefix !== '' && str_starts_with($path, $excludePrefix)) {
                        return false;
                    }
                }

                if ($activeNav !== null && $activeNav !== '' && in_array($activeNav, $navKeys, true)) {
                    return true;
                }

                foreach ($pathPrefixes as $prefix) {
                    if ($prefix === '/' && $path === '/') {
                        return true;
                    }

                    if ($prefix === '/') {
                        continue;
                    }

                    if ($prefix !== '' && str_starts_with($path, $prefix)) {
                        return true;
                    }
                }

                return false;
            }
        ));

        return $twig;
    }
}
