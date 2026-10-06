<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\FileFolderShare as Share;
use App\Models\StoredFile;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileShareService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Der Zwischenspeicher des Zugriffsdienstes und seine Grenze.
 *
 * FileAccessService baute den gesamten Ordnerbaum und wertete alle Freigaben bei jedem
 * Aufruf neu aus. Gemessen an einem Bestand mit drei Teamordnern, sechs Unterordnern und
 * achtzehn Dateien kostete allein der Zugriffsdienst 33 Abfragen für die Seite /files und
 * 14 für ein einziges fileLevelFor().
 *
 * Der Zwischenspeicher lebt nur innerhalb einer Anfrage. Er ist deshalb kein Cache im
 * üblichen Sinn und braucht keine Ablaufzeit - aber er braucht ein ausdrückliches
 * Verwerfen, sobald sich Ordner oder Freigaben ändern. Genau das halten die Tests hier
 * fest: einmal, dass gespart wird, und viermal, dass trotzdem niemand einen veralteten
 * Stand zu sehen bekommt.
 */
final class FileAccessCacheFeatureTest extends TestCase
{
    use FileFixtures;

    private FileAccessService $access;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->access = new FileAccessService();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    public function testRepeatedReadsDoNotQueryAgain(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $root = $this->createFolder('Team');
        $this->share($root, 'role', (int) $role->id, Share::LEVEL_MANAGE);
        $actor = $this->actor($member);

        $first = $this->countQueries(fn (): array => $this->access->folderLevels($actor));
        $second = $this->countQueries(fn (): array => $this->access->folderLevels($actor));

        $this->assertGreaterThan(0, $first, 'Der erste Aufruf muss die Daten holen.');
        $this->assertSame(0, $second, 'Der zweite Aufruf darf dieselben Daten nicht erneut holen.');
    }

    public function testReadsForDifferentPeopleStaySeparate(): void
    {
        $owner = $this->createMember('Besitzer');
        $stranger = $this->createMember('Fremder');
        $role = $this->createRoleFor($owner);
        $root = $this->createFolder('Nur Vorstand');
        $this->share($root, 'role', (int) $role->id, Share::LEVEL_MANAGE);

        // Erst der Berechtigte, dann der Fremde auf derselben Instanz: Ein
        // Zwischenspeicher ohne Personenbezug gäbe dem Fremden die fremde Stufe.
        $this->assertSame(Share::LEVEL_MANAGE, $this->access->levelFor($this->actor($owner), $root));
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($this->actor($stranger), $root));
    }

    public function testFolderAdminAndMemberDoNotShareTheirLevels(): void
    {
        $member = $this->createMember();
        $root = $this->createFolder('Ohne Freigabe');

        $admin = new FileActor((int) $member->id, true);
        $plain = new FileActor((int) $member->id, false);

        $this->assertSame(Share::LEVEL_MANAGE, $this->access->levelFor($admin, $root));
        $this->assertSame(Share::LEVEL_NONE, $this->access->levelFor($plain, $root));
    }

    public function testNewFolderIsVisibleRightAfterItWasCreated(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $root = $this->createFolder('Team');
        $this->share($root, 'role', (int) $role->id, Share::LEVEL_MANAGE);
        $actor = $this->actor($member);

        // Der Baum ist jetzt im Zwischenspeicher.
        $this->assertSame(Share::LEVEL_MANAGE, $this->access->levelFor($actor, $root));

        $created = $this->folderService()->create($actor, $root, 'Frisch angelegt');

        $this->assertSame(
            Share::LEVEL_MANAGE,
            $this->access->levelFor($actor, $created),
            'Ein eben angelegter Unterordner muss seine geerbte Stufe sofort tragen.'
        );
    }

    public function testMovedFolderCarriesItsNewPathRightAway(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $root = $this->createFolder('Team');
        $this->share($root, 'role', (int) $role->id, Share::LEVEL_MANAGE);
        $target = $this->createFolder('Ziel', $root);
        $moving = $this->createFolder('Wandert', $root);
        $actor = $this->actor($member);

        $this->assertSame([(int) $root->id, (int) $moving->id], $this->access->ancestry($moving));

        $this->folderService()->move($actor, $moving, $target);

        $this->assertSame(
            [(int) $root->id, (int) $target->id, (int) $moving->id],
            $this->access->ancestry($moving),
            'Nach dem Verschieben muss der Pfad den neuen Elternordner zeigen.'
        );
    }

    public function testTrashedFolderIsOutOfReachRightAway(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $root = $this->createFolder('Team');
        $this->share($root, 'role', (int) $role->id, Share::LEVEL_MANAGE);
        $sub = $this->createFolder('Verschwindet', $root);
        $actor = $this->actor($member);

        $this->assertSame(Share::LEVEL_MANAGE, $this->access->levelFor($actor, $sub));

        $this->folderService()->trash($actor, $sub);

        $this->assertSame(
            Share::LEVEL_NONE,
            $this->access->levelFor($actor, $sub),
            'Ein Ordner im Papierkorb ist für alle unerreichbar, auch sofort danach.'
        );
        $this->assertTrue($this->access->isInTrash($sub));
    }

    public function testChangedFolderSharesTakeEffectRightAway(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $root = $this->createFolder('Team');
        $this->share($root, 'role', (int) $role->id, Share::LEVEL_MANAGE);
        $actor = $this->actor($member);
        $admin = new FileActor((int) $member->id, true);

        $this->assertSame(Share::LEVEL_MANAGE, $this->access->levelFor($actor, $root));

        // Die Verwaltung setzt die Stufe herunter. Gesetzt wird als Datei-Admin, damit die
        // Selbstaussperr-Prüfung in setShares() nicht dazwischenkommt.
        $this->folderService()->setShares($admin, $root, [[
            'level' => Share::LEVEL_READ,
            'conditions' => ['role' => [(int) $role->id]],
        ]]);

        $this->assertSame(
            Share::LEVEL_READ,
            $this->access->levelFor($actor, $root),
            'Die neue Stufe muss sofort gelten, nicht erst beim nächsten Seitenaufruf.'
        );
    }

    public function testChangedFileSharesTakeEffectRightAway(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $root = $this->createFolder('Fremder Ordner');
        $file = StoredFile::create([
            'folder_id' => $root->id,
            'name' => 'protokoll.pdf',
            'size' => 12,
            'mime_type' => 'application/pdf',
        ]);
        $actor = $this->actor($member);
        $admin = new FileActor((int) $member->id, true);

        // Ohne Freigabe sieht das Mitglied die Datei nicht - dieser Stand liegt danach
        // im Zwischenspeicher.
        $this->assertSame(Share::LEVEL_NONE, $this->access->fileLevelFor($actor, $file));

        $this->shareService()->setShares($admin, $file, [[
            'level' => Share::LEVEL_READ,
            'conditions' => ['role' => [(int) $role->id]],
        ]]);

        $this->assertSame(
            Share::LEVEL_READ,
            $this->access->fileLevelFor($actor, $file),
            'Eine eben gesetzte Dateifreigabe muss sofort wirken.'
        );
    }

    private function folderService(): FileFolderService
    {
        return new FileFolderService(
            $this->access,
            new FileQuotaService($this->access, 0),
            new NullLogger()
        );
    }

    private function shareService(): FileShareService
    {
        return new FileShareService($this->access, $this->folderService(), new NullLogger());
    }

    /**
     * Wie viele Abfragen ein Aufruf kostet. Gezählt wird über das Abfrageprotokoll der
     * Verbindung, nicht über eine eigene Zählung im Dienst - sonst prüfte der Test seine
     * eigene Buchhaltung statt der tatsächlichen Last.
     *
     * @param \Closure(): mixed $call
     */
    private function countQueries(\Closure $call): int
    {
        $connection = Bootstrap::getCapsule()?->connection();
        self::assertNotNull($connection);

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $call();
        $count = count($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        return $count;
    }
}
