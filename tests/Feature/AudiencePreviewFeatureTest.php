<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AudiencePreviewController;
use App\Models\AudienceFilter;
use App\Models\FileFolderShare as Share;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Trefferzahl einer Zielgruppen-Zeile: nur die Zahl, nur für Personen, die
 * irgendwo Zielgruppen festlegen, ohne Spuren in der Datenbank.
 */
class AudiencePreviewFeatureTest extends TestCase
{
    use FileFixtures;
    use TestHttpHelpers;

    private AudiencePreviewController $controller;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $container = $builder->build();
        $container->set(Capsule::class, Bootstrap::getCapsule());
        $this->controller = $container->get(AudiencePreviewController::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function preview(array $body): array
    {
        $response = $this->controller->preview(
            $this->makeRequest('POST', '/audience-preview', $body, [], ['Accept' => 'application/json']),
            $this->makeResponse()
        );

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    public function testManagerGetsOnlyACount(): void
    {
        $manager = $this->createMember('Verwalter');
        $folder = $this->createFolder('Wurzel');
        $this->share($folder, 'user', (int) $manager->id, Share::LEVEL_MANAGE);
        $sopran = $this->createMember('Sopran');
        $group = $this->createVoiceGroupFor($sopran);
        $_SESSION = ['user_id' => (int) $manager->id];

        [$status, $payload] = $this->preview(['conditions' => ['voice_group' => [(string) $group->id]]]);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'count' => 1], $payload);
    }

    public function testReaderIsRejected(): void
    {
        $reader = $this->createMember();
        $folder = $this->createFolder('Wurzel');
        $this->share($folder, 'user', (int) $reader->id, Share::LEVEL_READ);
        $_SESSION = ['user_id' => (int) $reader->id];

        [$status, $payload] = $this->preview(['all' => '1']);

        $this->assertSame(403, $status);
        $this->assertArrayNotHasKey('count', $payload);
    }

    public function testEmptyRowIsRejectedWithMessage(): void
    {
        $_SESSION = ['user_id' => (int) $this->createMember()->id, 'can_manage_files' => true];

        [$status, $payload] = $this->preview(['conditions' => []]);

        $this->assertSame(422, $status);
        $this->assertFalse($payload['ok']);
    }

    public function testEventManagerWithoutFileRightsGetsCount(): void
    {
        $_SESSION = ['user_id' => (int) $this->createMember()->id, 'can_manage_events' => true];

        [$status, $payload] = $this->preview(['all' => '1']);

        $this->assertSame(200, $status);
        $this->assertArrayHasKey('count', $payload);
    }

    public function testNewsletterManagerWithoutFileRightsGetsCount(): void
    {
        $_SESSION = ['user_id' => (int) $this->createMember()->id, 'can_manage_newsletters' => true];

        [$status] = $this->preview(['all' => '1']);

        $this->assertSame(200, $status);
    }

    public function testMemberWithoutAnyOfTheRightsIsRejected(): void
    {
        $_SESSION = ['user_id' => (int) $this->createMember()->id];

        [$status, $payload] = $this->preview(['all' => '1']);

        $this->assertSame(403, $status);
        $this->assertArrayNotHasKey('count', $payload);
    }

    public function testPreviewLeavesNoFilterBehind(): void
    {
        $_SESSION = ['user_id' => (int) $this->createMember()->id, 'can_manage_files' => true];
        $before = AudienceFilter::query()->count();

        $this->preview(['all' => '1']);

        $this->assertSame($before, AudienceFilter::query()->count());
    }
}
