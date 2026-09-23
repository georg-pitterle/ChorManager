<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationDispatchLog;
use App\Models\User;
use App\Services\MailQueueService;
use App\Services\NotificationReminderService;
use App\Services\NotificationService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * `notification_dispatch_log` merkt sich je Erinnerung, Objekt und Empfänger,
 * dass eine Mail raus ist. Die Tabelle wurde nie aufgeräumt und wuchs mit jedem
 * Lauf weiter.
 *
 * Aufgeräumt wird seit 20260923160000 nach zwölf Monaten. Die Grenze ist
 * unkritisch, weil die Sperre gegen Doppelmails am eindeutigen Index hängt und
 * nicht am Alter: Ein Eintrag, der älter ist als jedes einstellbare
 * Fälligkeitsfenster, kann keine zweite Mail mehr verhindern.
 *
 * Geprüft wird beides - dass Altes wirklich verschwindet und dass Junges
 * wirklich bleibt. Nur das zweite schützt davor, dass der Aufräumlauf die
 * Sperre aushebelt und Mitglieder eine Erinnerung doppelt bekommen.
 */
final class NotificationDispatchLogPruneTest extends TestCase
{
    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        // Die Seed-Daten bringen eigene Merkzettel mit; jede Zählung wäre sonst
        // eine andere. Das Löschen läuft in der Transaktion und ist nach dem
        // Test wieder zurückgenommen.
        NotificationDispatchLog::query()->delete();
    }

    protected function tearDown(): void
    {
        Capsule::connection()->rollBack();
        parent::tearDown();
    }

    public function testEntriesOlderThanTwelveMonthsAreRemoved(): void
    {
        $this->writeEntry('alt', Carbon::now()->subMonths(13));

        $removed = $this->service()->pruneExpiredDispatchLog();

        $this->assertSame(1, $removed);
        $this->assertSame(0, NotificationDispatchLog::query()->count());
    }

    public function testRecentEntriesSurviveSoTheDuplicateGuardKeepsWorking(): void
    {
        $this->writeEntry('frisch', Carbon::now()->subDays(3));
        $this->writeEntry('grenznah', Carbon::now()->subMonths(11));

        $removed = $this->service()->pruneExpiredDispatchLog();

        $this->assertSame(0, $removed);
        $this->assertSame(2, NotificationDispatchLog::query()->count());
    }

    public function testPruneKeepsTheYoungAndDropsTheOldInOneRun(): void
    {
        $this->writeEntry('alt', Carbon::now()->subYears(2));
        $this->writeEntry('frisch', Carbon::now()->subMonth());

        $this->assertSame(1, $this->service()->pruneExpiredDispatchLog());

        $this->assertSame(
            ['frisch'],
            NotificationDispatchLog::query()->pluck('dispatch_key')->all()
        );
    }

    /**
     * Der Index auf created_at ist die Voraussetzung dafür, dass das Aufräumen
     * ein Bereichszugriff bleibt und nicht die ganze Tabelle liest.
     */
    public function testCreatedAtIsIndexed(): void
    {
        $rows = Capsule::connection()->select(
            "SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notification_dispatch_log'
               AND COLUMN_NAME = 'created_at'
               AND SEQ_IN_INDEX = 1"
        );

        $this->assertNotEmpty($rows, 'Ohne Index auf created_at liest der Aufräumlauf die ganze Tabelle.');
    }

    private function service(): NotificationReminderService
    {
        $notificationService = new NotificationService(
            new MailQueueService(),
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new NullLogger(),
            ['tasks' => true, 'sponsoring' => true]
        );

        return new NotificationReminderService($notificationService, new NullLogger());
    }

    private function writeEntry(string $dispatchKey, Carbon $createdAt): void
    {
        Capsule::connection()->insert(
            'INSERT INTO notification_dispatch_log
                (notification_type, entity_type, entity_id, user_id, dispatch_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                'task_due_soon',
                'task',
                1,
                $this->anyUserId(),
                $dispatchKey,
                $createdAt->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Die Testdatenbank ist migriert, aber nicht befüllt - ein Mitglied muss
     * der Test selbst anlegen. Der Fremdschlüssel auf user_id verlangt eines.
     */
    private function anyUserId(): int
    {
        return $this->userId ??= (int) User::create([
            'first_name' => 'Merkzettel',
            'last_name' => 'Probe',
            'email' => 'dispatch.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ])->id;
    }
}
