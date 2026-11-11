<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\StorageController;
use App\Services\Storage\StorageUsageNode;
use App\Services\Storage\StorageUsageProvider;
use App\Services\Storage\StorageUsageService;
use App\Services\Storage\WriteAccessCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StoragePageFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private string $cache = '';

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 1, 'can_manage_storage' => true];
        $this->cache = sys_get_temp_dir() . '/storage-page-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cache);
        $_SESSION = [];
    }

    private function fixed(StorageUsageNode $node): StorageUsageProvider
    {
        return new class ($node) implements StorageUsageProvider {
            public function __construct(private readonly StorageUsageNode $node)
            {
            }

            public function key(): string
            {
                return $this->node->key;
            }

            public function label(): string
            {
                return $this->node->label;
            }

            public function usage(): StorageUsageNode
            {
                return $this->node;
            }
        };
    }

    private function failing(): StorageUsageProvider
    {
        return new class implements StorageUsageProvider {
            public function key(): string
            {
                return 'backups';
            }

            public function label(): string
            {
                return 'Backups';
            }

            public function usage(): StorageUsageNode
            {
                throw new \RuntimeException('nicht lesbar');
            }
        };
    }

    public function testPageShowsAreasNestedRowsTeamFoldersAndFailedArea(): void
    {
        $files = StorageUsageNode::sum('files', 'Dateiablage', [
            new StorageUsageNode('files.current', 'Aktuelle Versionen', 3 * 1048576, 12),
            new StorageUsageNode('files.versions.old', 'Ältere Versionen', 1048576, 4),
            new StorageUsageNode('files.team_folders', 'Teamordner', 4 * 1048576, 1, [
                new StorageUsageNode('files.team_folders.5', 'Vorstand', 4 * 1048576, quotaBytes: 10 * 1048576),
            ], breakdown: true),
        ]);
        $database = StorageUsageNode::sum('database', 'Datenbank', [
            StorageUsageNode::sum('database.attachments', 'Anhänge', [
                new StorageUsageNode('database.attachments.finance', 'Finanzen', 2048, 3),
            ], 3),
        ]);
        $service = new StorageUsageService(
            [$this->fixed($files), $this->fixed($database), $this->failing()],
            $this->cache,
            new NullLogger()
        );

        $response = (new StorageController($this->createAppTwig('/storage'), $service))
            ->index($this->makeRequest('GET', '/storage'), $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Speicherplatz', $body);
        $this->assertStringContainsString('Dateiablage', $body);
        $this->assertStringContainsString('Ältere Versionen', $body);
        $this->assertStringContainsString('4,0 MB', $body, 'Summe der Dateiablage ohne Aufschlüsselung.');
        $this->assertStringContainsString('Finanzen', $body);
        $this->assertStringContainsString('2 KB', $body);
        $this->assertStringContainsString('Vorstand', $body);
        $this->assertStringContainsString('10,0 MB', $body, 'Kontingent des Teamordners.');
        $this->assertStringContainsString('nicht ermittelbar', $body);
        $this->assertStringContainsString('--usage-share:', $body);
        $this->assertStringContainsString('data-storage-key="files.versions.old"', $body);
        $this->assertStringContainsString('data-storage-key="database.attachments.finance"', $body);
    }

    /**
     * Eine Ablage ohne Schreibzugriff steht ganz oben auf der Seite.
     *
     * Die Anmeldebegrenzung zählt die Versuche in Dateien; lässt sich dort nicht
     * schreiben, lässt sie jeden Versuch durch (`RateLimiterService`). Bemerkt
     * wurde das bisher nur über eine Warnung im Protokoll - also erst in der
     * Log-Analyse. Jetzt fällt es beim Blick in die Verwaltung auf.
     *
     * Geprüft wird mit einer auswechselbaren Schreibprobe statt mit echten
     * Dateirechten: Der Testlauf läuft je nach Umgebung als root, und für root
     * bejaht `is_writable()` auch ein Verzeichnis ohne Schreibrecht.
     */
    public function testPageLeadsWithAnAreaThatCannotBeWritten(): void
    {
        $service = new StorageUsageService(
            [$this->fixed(StorageUsageNode::sum('database', 'Datenbank', []))],
            $this->cache,
            new NullLogger(),
            null,
            new WriteAccessCheck(
                [[
                    'label' => 'Anmeldebegrenzung',
                    'path' => '/var/www/html/var/rate-limits',
                    'consequence' => 'Anmeldeversuche werden nicht mehr begrenzt.',
                ]],
                static fn (string $path): bool => false
            )
        );

        $body = (string) (new StorageController($this->createAppTwig('/storage'), $service))
            ->index($this->makeRequest('GET', '/storage'), $this->makeResponse())
            ->getBody();

        $this->assertStringContainsString('Anmeldebegrenzung: kein Schreibzugriff', $body);
        $this->assertStringContainsString('Anmeldeversuche werden nicht mehr begrenzt.', $body);
        $this->assertStringContainsString('/var/www/html/var/rate-limits', $body);
        // Vor den Zahlen, nicht dahinter.
        $this->assertLessThan(
            strpos($body, 'storage-summary'),
            strpos($body, 'kein Schreibzugriff'),
            'Die Warnung gehört über die Speicherzahlen.'
        );
    }

    public function testPageStaysFreeOfWarningsWhileEveryAreaCanBeWritten(): void
    {
        $service = new StorageUsageService(
            [$this->fixed(StorageUsageNode::sum('database', 'Datenbank', []))],
            $this->cache,
            new NullLogger(),
            null,
            new WriteAccessCheck(
                [['label' => 'Anmeldebegrenzung', 'path' => sys_get_temp_dir(), 'consequence' => 'egal']],
                static fn (string $path): bool => true
            )
        );

        $body = (string) (new StorageController($this->createAppTwig('/storage'), $service))
            ->index($this->makeRequest('GET', '/storage'), $this->makeResponse())
            ->getBody();

        $this->assertStringNotContainsString('kein Schreibzugriff', $body);
        $this->assertStringNotContainsString('alert-danger', $body);
    }

    public function testRouteIsGatedByTheStorageRight(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertMatchesRegularExpression(
            "~get\\('/storage', \\[StorageController::class, 'index'\\]\\)\\s*"
                . "->add\\(new RoleMiddleware\\(gates: \\['requiresStorageManagement'\\]\\)\\)~",
            $routes
        );
    }
}
