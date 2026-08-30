<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\MailQueue;
use App\Models\User;
use App\Models\UserNotificationSetting;
use App\Services\MailQueueService;
use App\Services\NotificationService;
use App\Util\NotificationType;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Wer eine Benachrichtigung bekommt - und vor allem, wer nicht.
 *
 * Drei Instanzen dürfen widersprechen: das Modul, die Verwaltung und die Person
 * selbst. Läuft eine dieser Prüfungen ins Leere, schreibt die Anwendung
 * jemandem, der das abbestellt hat - und das merkt man erst an der Beschwerde.
 */
final class NotificationServiceFeatureTest extends TestCase
{
    private const TEMPLATE = 'emails/notification_project_member_added.twig';

    private User $anna;
    private User $bernd;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEveryWillingRecipientGetsOneMail(): void
    {
        $sent = $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->bernd],
            'Testbetreff',
            self::TEMPLATE,
            $this->context()
        );

        $this->assertSame(2, $sent);
        $this->assertSame(
            [$this->anna->email, $this->bernd->email],
            $this->queuedRecipients()
        );
    }

    /**
     * Die Mail landet als `notification` in der Warteschlange, der Anlass steht
     * in der Nutzlast - so bleibt der ENUM-Wert stabil, wenn Anlässe dazukommen.
     */
    public function testTheQueueEntryCarriesTheTypeInItsPayload(): void
    {
        $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna],
            'Testbetreff',
            self::TEMPLATE,
            $this->context()
        );

        $entry = MailQueue::where('recipient_email', $this->anna->email)->firstOrFail();

        $this->assertSame('notification', (string) $entry->mail_type);
        $this->assertSame(NotificationType::PROJECT_MEMBER_ADDED, $entry->payload_json['notification_type']);
        $this->assertSame((int) $this->anna->id, (int) $entry->payload_json['user_id']);
        $this->assertSame('project', $entry->payload_json['entity_type']);
    }

    public function testTheTriggeringPersonIsNotNotified(): void
    {
        $sent = $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->bernd],
            'Testbetreff',
            self::TEMPLATE,
            $this->context(),
            (int) $this->anna->id
        );

        $this->assertSame(1, $sent);
        $this->assertSame([$this->bernd->email], $this->queuedRecipients());
    }

    public function testAnOptedOutRecipientIsSkipped(): void
    {
        UserNotificationSetting::create([
            'user_id' => $this->anna->id,
            'notification_type' => NotificationType::PROJECT_MEMBER_ADDED,
            'enabled' => false,
        ]);

        $sent = $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->bernd],
            'Testbetreff',
            self::TEMPLATE,
            $this->context()
        );

        $this->assertSame(1, $sent);
        $this->assertSame([$this->bernd->email], $this->queuedRecipients());
    }

    /**
     * Die Abbestellung gilt genau einem Anlass. Wer keine Termin-Mails will,
     * verliert damit nicht auch die Zuweisung seiner Aufgaben.
     */
    public function testOptingOutOfOneTypeLeavesTheOthersAlone(): void
    {
        UserNotificationSetting::create([
            'user_id' => $this->anna->id,
            'notification_type' => NotificationType::EVENT_CREATED,
            'enabled' => false,
        ]);

        $sent = $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna],
            'Testbetreff',
            self::TEMPLATE,
            $this->context()
        );

        $this->assertSame(1, $sent);
    }

    public function testAGloballyDisabledTypeReachesNobody(): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => NotificationType::settingKey(NotificationType::PROJECT_MEMBER_ADDED)],
            ['setting_value' => '0', 'binary_content' => '', 'mime_type' => 'text/plain']
        );

        $sent = $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->bernd],
            'Testbetreff',
            self::TEMPLATE,
            $this->context()
        );

        $this->assertSame(0, $sent);
        $this->assertSame([], $this->queuedRecipients());
    }

    /**
     * Ein Anlass, dessen Modul aus ist, kann nicht eintreten - und wenn ihn doch
     * jemand auslöst, darf keine Mail entstehen.
     */
    public function testATypeOfADisabledModuleReachesNobody(): void
    {
        $service = $this->service(['tasks' => false]);

        $sent = $service->notify(
            NotificationType::TASK_ASSIGNED,
            [$this->anna],
            'Testbetreff',
            'emails/notification_task_assigned.twig',
            $this->context()
        );

        $this->assertSame(0, $sent);
        $this->assertFalse($service->isAvailable(NotificationType::TASK_ASSIGNED));
    }

    public function testInactiveMembersAreSkipped(): void
    {
        $this->anna->is_active = 0;
        $this->anna->save();

        $sent = $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->bernd],
            'Testbetreff',
            self::TEMPLATE,
            $this->context()
        );

        $this->assertSame(1, $sent);
        $this->assertSame([$this->bernd->email], $this->queuedRecipients());
    }

    /**
     * Dieselbe Person kann über zwei Wege in der Empfängerliste stehen - etwa
     * als Zugewiesene und als Erstellerin einer Aufgabe. Zwei Mails für ein
     * Ereignis wären der sichtbare Fehler.
     */
    public function testARecipientListedTwiceGetsOneMail(): void
    {
        $sent = $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->anna],
            'Testbetreff',
            self::TEMPLATE,
            $this->context()
        );

        $this->assertSame(1, $sent);
    }

    public function testAnUnusableAddressIsSkippedInsteadOfBreakingTheRound(): void
    {
        $this->anna->email = 'keine-adresse';
        $this->anna->save();

        $sent = $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->bernd],
            'Testbetreff',
            self::TEMPLATE,
            $this->context()
        );

        $this->assertSame(1, $sent, 'Die übrigen Empfänger müssen trotzdem bedient werden.');
        $this->assertSame([$this->bernd->email], $this->queuedRecipients());
    }

    public function testSettingsFallBackToTheTypeDefault(): void
    {
        $settings = $this->service()->settingsFor((int) $this->anna->id);

        foreach (NotificationType::all() as $type) {
            $this->assertSame(
                NotificationType::defaultEnabled($type),
                $settings[$type],
                'Ohne Eintrag muss die Vorgabe des Anlasses gelten: ' . $type
            );
        }
    }

    /**
     * Deckt sich die Entscheidung mit der Vorgabe, verschwindet die Zeile
     * wieder - sonst wüchse die Tabelle mit jedem Speichern um neun Zeilen je
     * Person, die alle nichts aussagen.
     */
    public function testAgreeingWithTheDefaultStoresNoRow(): void
    {
        $service = $this->service();
        $type = NotificationType::PROJECT_MEMBER_ADDED;

        $service->storeSettings((int) $this->anna->id, [$type => false]);
        $this->assertSame(1, $this->settingRowCount());

        $service->storeSettings((int) $this->anna->id, [$type => NotificationType::defaultEnabled($type)]);
        $this->assertSame(0, $this->settingRowCount());
    }

    public function testUnknownTypesAreIgnoredWhenStoring(): void
    {
        $this->service()->storeSettings((int) $this->anna->id, ['gibt_es_nicht' => false]);

        $this->assertSame(0, $this->settingRowCount());
    }

    /**
     * @param array<string, bool> $modules
     */
    private function service(array $modules = ['tasks' => true, 'sponsoring' => true]): NotificationService
    {
        return new NotificationService(
            new MailQueueService(),
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new NullLogger(),
            $modules
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'project' => (object) [
                'id' => 42,
                'name' => 'Testprojekt',
                'description' => 'Beschreibung',
                'start_date' => null,
                'end_date' => null,
            ],
            'link' => 'https://chor.example/projects/42/members',
            'profile_url' => 'https://chor.example/profile',
        ];
    }

    /**
     * @return list<string>
     */
    private function queuedRecipients(): array
    {
        return MailQueue::whereIn('recipient_email', [$this->anna->email, $this->bernd->email])
            ->orderBy('id')
            ->pluck('recipient_email')
            ->all();
    }

    private function settingRowCount(): int
    {
        return UserNotificationSetting::where('user_id', $this->anna->id)->count();
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'notify.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
