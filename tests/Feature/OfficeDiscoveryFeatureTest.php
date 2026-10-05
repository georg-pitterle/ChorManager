<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolderShare as Share;
use App\Services\Office\OfficeAction;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Welche Dateien Collabora öffnen kann, steht in seiner Discovery. Getestet wird
 * gegen eine feste Fixture; ein echter Server ist nicht nötig.
 */
class OfficeDiscoveryFeatureTest extends TestCase
{
    use OfficeFixtures;

    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->previousEnv = [];
        $this->tearDownOfficeFixtures();
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->previousEnv)) {
            $this->previousEnv[$key] = getenv($key);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);

        return $builder->build()->get('settings');
    }

    public function testParsesEditAndViewActionsAndRebasesOntoServerUrl(): void
    {
        $discovery = $this->discovery();

        $odt = $discovery->actionFor('PROTOKOLL.ODT');
        $this->assertNotNull($odt, 'Endungen in Großbuchstaben zählen wie kleine.');
        $this->assertTrue($odt->canEdit);
        $this->assertSame('https://office.example.test/browser/abc123/cool.html?', $odt->urlSrc);

        $pdf = $discovery->actionFor('Partitur.pdf');
        $this->assertNotNull($pdf);
        $this->assertFalse($pdf->canEdit);

        $this->assertNull($discovery->actionFor('Einsingen.mp3'));
        $this->assertNull($discovery->actionFor('ohne-endung'));
        $this->assertTrue($discovery->isAvailable());
    }

    public function testEditWinsOverViewForTheSameExtension(): void
    {
        $this->assertTrue($this->discovery()->actionFor('Kassa.xlsx')?->canEdit);
    }

    public function testPlaceholdersInUrlSrcAreRemoved(): void
    {
        $this->assertSame(
            'https://office.example.test/browser/abc123/cool.html?',
            $this->discovery()->actionFor('Liste.ods')?->urlSrc
        );
    }

    public function testFetchesFromInternalUrl(): void
    {
        $requested = [];
        $this->discovery(function (string $url) use (&$requested): ?string {
            $requested[] = $url;

            return $this->discoveryXml();
        })->isAvailable();

        $this->assertSame(['http://collabora:9980/hosting/discovery'], $requested);
    }

    public function testUnreachableServerMeansNothingIsAvailableAndIsLogged(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $discovery = $this->discovery(static fn (string $url): ?string => null, $logger);

        $this->assertFalse($discovery->isAvailable());
        $this->assertNull($discovery->actionFor('Protokoll.odt'));
        $this->assertTrue($handler->hasWarningThatPasses(
            static fn ($record): bool => ($record->context['event'] ?? '') === 'office.discovery_failed'
        ));
    }

    public function testBrokenXmlCountsAsFailure(): void
    {
        $this->assertFalse($this->discovery(static fn (string $url): ?string => '<wopi-discovery><net-zone')->isAvailable());
    }

    public function testSuccessfulResultIsCachedAcrossInstances(): void
    {
        $calls = 0;
        $fetch = function (string $url) use (&$calls): ?string {
            $calls++;

            return $this->discoveryXml();
        };

        $this->discovery($fetch)->isAvailable();
        $this->discovery($fetch)->isAvailable();

        $this->assertSame(1, $calls);
    }

    public function testFailureIsRetriedAfterOneMinute(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        $calls = 0;
        $fetch = function (string $url) use (&$calls): ?string {
            $calls++;

            return null;
        };

        $this->discovery($fetch)->isAvailable();
        $this->discovery($fetch)->isAvailable();
        $this->assertSame(1, $calls, 'Innerhalb einer Minute kein neuer Versuch.');

        Carbon::setTestNow(Carbon::now()->addSeconds(OfficeDiscovery::FAILURE_CACHE_SECONDS + 1));
        $this->discovery($fetch)->isAvailable();
        $this->assertSame(2, $calls);
    }

    /**
     * Läuft der Stunden-Cache ab und ist Collabora gerade nicht erreichbar, gilt der
     * letzte erfolgreiche Stand weiter. Sonst schlüge jedes Speichern einer laufenden
     * Sitzung fehl - im schlimmsten Fall das Speichern beim Schließen.
     */
    public function testStaleResultStaysUsableWhileServerIsDown(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        $up = true;
        $calls = 0;
        $fetch = function (string $url) use (&$up, &$calls): ?string {
            $calls++;

            return $up ? $this->discoveryXml() : null;
        };
        $this->discovery($fetch)->isAvailable();

        $up = false;
        Carbon::setTestNow(Carbon::now()->addSeconds(OfficeDiscovery::CACHE_SECONDS + 1));
        $discovery = $this->discovery($fetch);

        $this->assertTrue($discovery->isAvailable());
        $this->assertTrue($discovery->actionFor('Protokoll.odt')?->canEdit);
        $this->assertSame(2, $calls);

        $this->discovery($fetch)->isAvailable();
        $this->assertSame(2, $calls, 'Nach dem Fehlschlag erst nach einer Minute wieder fragen.');

        Carbon::setTestNow(Carbon::now()->addSeconds(OfficeDiscovery::FAILURE_CACHE_SECONDS + 1));
        $this->discovery($fetch)->isAvailable();
        $this->assertSame(3, $calls);
    }

    public function testModeDependsOnLevelAndAction(): void
    {
        $edit = new OfficeAction('https://office.example.test/x?', true);
        $view = new OfficeAction('https://office.example.test/x?', false);

        $this->assertSame('edit', $edit->modeFor(Share::LEVEL_EDIT));
        $this->assertSame('edit', $edit->modeFor(Share::LEVEL_MANAGE));
        $this->assertSame('view', $edit->modeFor(Share::LEVEL_UPLOAD));
        $this->assertSame('view', $edit->modeFor(Share::LEVEL_READ));
        $this->assertSame('view', $view->modeFor(Share::LEVEL_MANAGE));
        $this->assertNull($edit->modeFor(Share::LEVEL_NONE));
    }

    public function testSettingsFallBackToServerAndAppUrl(): void
    {
        $settings = OfficeSettings::fromArray(
            ['server_url' => 'https://office.example.test:9980/', 'internal_url' => '', 'wopi_base_url' => ''],
            'https://chor.example.test/'
        );

        $this->assertSame('https://office.example.test:9980', $settings->serverUrl);
        $this->assertSame('https://office.example.test:9980', $settings->internalUrl);
        $this->assertSame('https://chor.example.test', $settings->wopiBaseUrl);
        $this->assertSame('https://office.example.test:9980', $settings->serverOrigin());
        $this->assertSame('https://chor.example.test', $settings->appOrigin());
    }

    public function testModuleNeedsFilesAndServerUrl(): void
    {
        $this->setEnv('FEATURE_OFFICE', 'true');
        $this->setEnv('FEATURE_FILES', 'true');
        $this->setEnv('OFFICE_SERVER_URL', 'https://office.example.test');
        $this->assertTrue($this->settings()['modules']['office']);

        $this->setEnv('FEATURE_FILES', 'false');
        $this->assertFalse($this->settings()['modules']['office'], 'Ohne Dateiablage nichts zu bearbeiten.');

        $this->setEnv('FEATURE_FILES', 'true');
        $this->setEnv('OFFICE_SERVER_URL', '');
        $this->assertFalse($this->settings()['modules']['office'], 'Ohne Server-Adresse kein Editor.');
    }
}
