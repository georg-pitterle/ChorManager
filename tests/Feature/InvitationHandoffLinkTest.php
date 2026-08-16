<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\InvitationHandoffLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvitationHandoffLinkTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([InvitationHandoffLink::ENV_URL, InvitationHandoffLink::ENV_LABEL] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                continue;
            }
            $_ENV[$key] = $value;
        }
        parent::tearDown();
    }

    public function testHandoffLinkIsDisabledWhenUrlIsNotConfigured(): void
    {
        $this->assertNull(InvitationHandoffLink::resolve());
        $this->assertFalse(InvitationHandoffLink::isMisconfigured());
    }

    public function testHandoffLinkUsesDefaultLabelWhenOnlyUrlIsConfigured(): void
    {
        $_ENV[InvitationHandoffLink::ENV_URL] = 'https://wiki.example.org/onboarding';

        $handoff = InvitationHandoffLink::resolve();

        $this->assertIsArray($handoff);
        $this->assertSame('https://wiki.example.org/onboarding', $handoff['url']);
        $this->assertSame(InvitationHandoffLink::DEFAULT_LABEL, $handoff['label']);
        $this->assertFalse(InvitationHandoffLink::isMisconfigured());
    }

    public function testHandoffLinkUsesConfiguredLabel(): void
    {
        $_ENV[InvitationHandoffLink::ENV_URL] = 'http://intranet.example.org/uebergabe.pdf';
        $_ENV[InvitationHandoffLink::ENV_LABEL] = 'Übergabedokument des Vorstands';

        $handoff = InvitationHandoffLink::resolve();

        $this->assertIsArray($handoff);
        $this->assertSame('Übergabedokument des Vorstands', $handoff['label']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'file scheme' => ['file:///etc/passwd'],
            'relative path' => ['/help/onboarding'],
            'missing scheme' => ['wiki.example.org/onboarding'],
        ];
    }

    #[DataProvider('unsafeUrlProvider')]
    public function testHandoffLinkRejectsUnsafeUrls(string $url): void
    {
        $_ENV[InvitationHandoffLink::ENV_URL] = $url;

        $this->assertNull(InvitationHandoffLink::resolve());
        $this->assertTrue(InvitationHandoffLink::isMisconfigured());
    }

    public function testInvitationTemplateRendersHandoffLinkConditionally(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../templates/emails/invitation.twig');
        $this->assertIsString($content);
        $this->assertStringContainsString('handoff_url', $content);
        $this->assertStringContainsString('handoff_label', $content);
        $this->assertStringContainsString('{% if handoff_url is defined and handoff_url is not empty %}', $content);
    }

    public function testInvitationMailPassesHandoffLinkToTemplate(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/UserController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('InvitationHandoffLink::resolve()', $controller);
        $this->assertStringContainsString("'handoff_url'", $controller);
        $this->assertStringContainsString("'handoff_label'", $controller);
    }

    public function testEnvExampleDocumentsHandoffVariables(): void
    {
        foreach (['/../.env.example', '/../dist/.env.example'] as $relativePath) {
            $content = file_get_contents(dirname(__DIR__) . $relativePath);
            $this->assertIsString($content);
            $this->assertStringContainsString(InvitationHandoffLink::ENV_URL, $content, $relativePath);
            $this->assertStringContainsString(InvitationHandoffLink::ENV_LABEL, $content, $relativePath);
        }
    }
}
