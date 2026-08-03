<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Models\User;
use App\Services\NameFormatterService;
use App\Services\SessionAuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameDisplayPhpCoverageFeatureTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function sources(): array
    {
        return [
            ['src/Services/SessionAuthService.php'],
            ['src/Controllers/NewsletterController.php'],
            ['src/Controllers/RegistrationController.php'],
            ['src/Controllers/SponsoringDashboardController.php'],
            ['src/Controllers/EventController.php'],
        ];
    }

    #[DataProvider('sources')]
    public function testNoInlineNameConcatenation(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/first_name\s*\.\s*[\'"] [\'"]\s*\.\s*\$?\w+(->|\[)/',
            $source
        );
        $this->assertStringContainsString('formatPerson', $source);
    }

    public function testSessionDisplayNameFollowsConfiguredFormat(): void
    {
        $user = new User();
        $user->forceFill([
            'id' => 3,
            'first_name' => 'Anna',
            'last_name' => 'Müller',
        ]);
        $user->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([]));
        $user->setRelation('voiceGroups', new \Illuminate\Database\Eloquent\Collection([]));

        $_SESSION = [];
        $service = new SessionAuthService(
            new NameFormatterService(NameFormatterService::FORMAT_LAST_FIRST),
            new RequestContext()
        );
        $service->setAuthenticatedUser($user);

        $this->assertSame('Müller, Anna', $_SESSION['user_name']);
    }
}
