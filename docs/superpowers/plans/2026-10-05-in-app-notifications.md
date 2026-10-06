# Benachrichtigungen in der App (Glocke) – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jeder Benachrichtigungs-Anlass legt neben der Mail einen Eintrag in einer Glocke in der Kopfzeile an; Mail und Glocke sind pro Anlass getrennt abbestellbar.

**Architecture:** Die Glocke ist ein zweiter Kanal im bestehenden `NotificationService`. `notify()` bekommt ein Pflicht-Wertobjekt `InAppMessage`; der Dienst prüft Modul, Verwaltung und Person je Kanal und schreibt Glocken-Einträge über `InAppNotificationStore` in `user_notifications`. Ein `UserNotificationController` liefert Zähler, Liste und Seite; ein kleines JS-Modul hält die Glocke aktuell.

**Tech Stack:** PHP 8 / Slim 4 / Eloquent (Capsule) / Phinx / Twig / Bootstrap 5 + Bootstrap Icons / Vanilla-JS / PHPUnit, alles in DDEV.

**Spec:** `docs/superpowers/specs/2026-10-05-in-app-notifications-design.md`

## Global Constraints

- Bezeichner englisch, Inhalte (UI-Texte, Kommentare, Testbeschreibungen, Commit-Nachrichten) deutsch mit echten Umlauten.
- Schemaänderungen nur per Phinx-Migration; jede Kette endet mit `create()`/`save()`/`update()`.
- PSR-12, Zeilenlänge 130 (`ddev composer phpcs`); Twig: doppelte Anführungszeichen, `ddev composer twigcs`.
- Kein Inline-JS, kein Inline-CSS in Templates; eigene Dateien unter `public/js/`, Regeln in `public/css/style.css`.
- Logging über `LoggerInterface` mit stabilem `event`-Schlüssel, Ausnahmen unter `exception`.
- Neue Dateien mit LF; nach dem Schreiben auf Windows normalisieren.
- `link` eines Glocken-Eintrags: relativer Pfad, beginnt mit genau einem `/`.
- Aufbewahrung: gelesen 30 Tage, ungelesen 180 Tage.
- Dropdown: letzte 10; Seite: 25 pro Seite; Zähler `99+` ab 100.
- Zähler-Aktualisierung: bei `focus`/`visibilitychange` (min. 5 s Abstand) und alle 2 Minuten bei sichtbarem Tab.
- Tests: `ddev php vendor/bin/phpunit --filter "<Muster>"` beim Arbeiten, Ausgabe gekürzt; volle Suite `ddev composer test:parallel` einmal am Schluss.

## Review Focus

1. **Mail-Abmeldung aus der Zeit vor der Migration** – wer vorher Mails abbestellt hatte, muss weiter keine Mail, aber Glocken-Einträge bekommen (Task 1, Migrationstest; Task 4, Kanaltest).
2. **Kommentar mit HTML oder sehr lang** – Glockentext ohne Tags, gekürzt auf 140 Zeichen mit „…“, kein Zeilenumbruch (Task 2).
3. **Fremde Notification-ID im Pfad** – `/notifications/{id}/open` einer anderen Person ergibt 404 und ändert nichts (Task 8).
4. **Glocke bei Konto ohne E-Mail-Adresse** – kein Mail-Eintrag, aber Glocken-Eintrag (Task 4).
5. **Termin für über 100 Mitglieder** – ein einziger INSERT, keine N Einzelabfragen, Fehler beim Schreiben der Glocke stoppt die Mails nicht (Task 3/4).

---

## Dateiübersicht

| Datei | Verantwortung |
|---|---|
| `db/migrations/20261005090000_create_user_notifications.php` | neue Tabelle |
| `db/migrations/20261005090100_add_channel_to_user_notification_settings.php` | Spalte `channel`, neuer Primärschlüssel |
| `src/Util/NotificationChannel.php` | Kanal-Konstanten `mail`, `in_app` |
| `src/Models/UserNotification.php` | Eloquent-Modell |
| `src/Models/UserNotificationSetting.php` | Schlüssel um `channel` erweitert |
| `src/Services/Notifications/InAppMessage.php` | Wertobjekt Glockentext, prüft Link, kürzt Text |
| `src/Services/Notifications/InAppNotificationStore.php` | Lesen/Schreiben/Gelesen/Aufräumen von `user_notifications` |
| `src/Services/NotificationService.php` | Kanal-Logik, neue `notify()`-Signatur, Einstellungen je Kanal |
| `src/Controllers/TaskController.php`, `EventController.php`, `ProjectController.php`, `src/Services/NotificationReminderService.php` | Glockentexte an den sieben Aufrufstellen; Gelesen beim Öffnen; Aufräumen |
| `src/Controllers/SponsorController.php` | Gelesen beim Öffnen |
| `src/Controllers/ProfileController.php`, `templates/profile/index.twig` | zwei Schalter pro Anlass |
| `src/Controllers/UserNotificationController.php` | Seite, Zähler-JSON, Liste-JSON, Öffnen, Alle gelesen |
| `src/Routes.php`, `src/Dependencies.php` | Routen, Twig-Funktion `notification_badge()` |
| `templates/partials/navigation/notification_bell.twig`, `templates/partials/navigation/user_menu.twig` | Glocke in der Kopfzeile |
| `templates/notifications/index.twig` | Seite `/notifications` |
| `public/js/notification-bell.js`, `templates/layout.twig`, `public/css/style.css` | Dropdown und Aktualisierung |
| `src/Services/DevSeedService.php` | Seed-Daten |

---

### Task 1: Schema, Kanal-Konstanten und Modelle

**Files:**
- Create: `db/migrations/20261005090000_create_user_notifications.php`
- Create: `db/migrations/20261005090100_add_channel_to_user_notification_settings.php`
- Create: `src/Util/NotificationChannel.php`
- Create: `src/Models/UserNotification.php`
- Modify: `src/Models/UserNotificationSetting.php` (`KEY_COLUMNS`, `$fillable`)
- Test: `tests/Unit/Models/UserNotificationSettingTest.php` (erweitern), `tests/Unit/Models/ModelSchemaConsistencyTest.php` (läuft automatisch über alle Modelle, prüfen ob die Modell-Liste dort per Glob oder Hand gepflegt ist – falls per Hand: `UserNotification::class` ergänzen)

**Interfaces:**
- Produces: `App\Util\NotificationChannel::MAIL = 'mail'`, `::IN_APP = 'in_app'`, `::all(): list<string>`; Modell `App\Models\UserNotification` (Tabelle `user_notifications`, `$timestamps = false`, casts `read_at`/`created_at` → datetime, `user_id`/`actor_user_id`/`entity_id` → integer); `UserNotificationSetting` mit Spalte `channel`.

- [ ] **Step 1: Failing test – Einstellungen sind je Kanal getrennt**

In `tests/Unit/Models/UserNotificationSettingTest.php` ergänzen (Aufbau der bestehenden Tests übernehmen – Transaktion/Benutzeranlage wie dort):

```php
    /**
     * Mail und Glocke sind zwei Zeilen desselben Anlasses. Ein Update auf die
     * eine darf die andere nicht mitnehmen.
     */
    public function testSavingOneChannelLeavesTheOtherAlone(): void
    {
        $mail = UserNotificationSetting::create([
            'user_id' => $this->user->id,
            'notification_type' => NotificationType::TASK_COMMENT,
            'channel' => NotificationChannel::MAIL,
            'enabled' => false,
        ]);
        UserNotificationSetting::create([
            'user_id' => $this->user->id,
            'notification_type' => NotificationType::TASK_COMMENT,
            'channel' => NotificationChannel::IN_APP,
            'enabled' => false,
        ]);

        $mail->enabled = true;
        $mail->save();

        $this->assertFalse((bool) UserNotificationSetting::query()
            ->where('user_id', $this->user->id)
            ->where('notification_type', NotificationType::TASK_COMMENT)
            ->where('channel', NotificationChannel::IN_APP)
            ->value('enabled'));
    }
```

(Falls die Testklasse `$this->user` anders nennt, an den vorhandenen Namen angleichen.)

- [ ] **Step 2: Test laufen lassen – erwartet FAIL** (Klasse `NotificationChannel` fehlt)

Run: `ddev php vendor/bin/phpunit --filter UserNotificationSettingTest 2>&1 | tail -15`

- [ ] **Step 3: `NotificationChannel`**

```php
<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Die Wege, auf denen eine Benachrichtigung ankommt.
 *
 * Jeder Anlass aus `NotificationType` geht über beide Kanäle; abbestellen
 * lässt sich jeder Kanal für sich.
 */
final class NotificationChannel
{
    public const MAIL = 'mail';
    public const IN_APP = 'in_app';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::MAIL, self::IN_APP];
    }
}
```

- [ ] **Step 4: Migration `create_user_notifications`**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Einträge der Glocke in der Kopfzeile.
 *
 * `link` ist ein relativer Pfad und `entity_type`/`entity_id` nennen das Objekt,
 * auf das er zeigt - darüber gelten alle Einträge zu einer Aufgabe als gelesen,
 * sobald jemand die Aufgabe öffnet, egal auf welchem Weg.
 */
final class CreateUserNotifications extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_notifications')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('notification_type', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('actor_user_id', 'integer', ['null' => true])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('body', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('link', 'string', ['limit' => 512, 'null' => false])
            ->addColumn('entity_type', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('entity_id', 'integer', ['null' => true])
            ->addColumn('read_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'read_at', 'created_at'], ['name' => 'idx_user_notifications_inbox'])
            ->addIndex(['user_id', 'entity_type', 'entity_id'], ['name' => 'idx_user_notifications_entity'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('user_notifications')->drop()->save();
    }
}
```

Hinweis: `users.id` prüfen (`signed`/`unsigned`) – die Fremdschlüsselspalten müssen denselben Typ haben wie in `20260830140000_create_user_notification_settings.php` (dort `integer`, also passt `integer`).

- [ ] **Step 5: Migration `add_channel_to_user_notification_settings`**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Einstellungen gelten künftig je Kanal (Mail, Glocke).
 *
 * Alle bestehenden Zeilen sind Entscheidungen über die Mail - eine Glocke gab
 * es noch nicht. Sie bekommen deshalb `mail`; wer Mails abbestellt hatte, sieht
 * die Glocke trotzdem, wie für alle anderen vorgegeben.
 */
final class AddChannelToUserNotificationSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_notification_settings')
            ->addColumn('channel', 'string', ['limit' => 16, 'null' => false, 'default' => 'mail',
                'after' => 'notification_type'])
            ->update();

        $this->execute(
            'ALTER TABLE user_notification_settings DROP PRIMARY KEY, '
            . 'ADD PRIMARY KEY (user_id, notification_type, channel)'
        );
    }

    public function down(): void
    {
        // Glocken-Zeilen haben im alten Schlüssel keinen Platz - sie würden mit
        // den Mail-Zeilen desselben Anlasses kollidieren.
        $this->execute("DELETE FROM user_notification_settings WHERE channel <> 'mail'");
        $this->execute(
            'ALTER TABLE user_notification_settings DROP PRIMARY KEY, '
            . 'ADD PRIMARY KEY (user_id, notification_type)'
        );
        $this->table('user_notification_settings')
            ->removeColumn('channel')
            ->update();
    }
}
```

Achtung: Hält MySQL den Fremdschlüssel `user_id` über den Primärschlüssel-Index, schlägt `DROP PRIMARY KEY` fehl („needed in a foreign key constraint“). Dann vorher `ADD INDEX idx_uns_user (user_id)` per `$this->table(...)->addIndex(['user_id'], ['name' => 'idx_user_notification_settings_user'])->update();` einfügen und im `down()` nach der Schlüssel-Wiederherstellung wieder entfernen.

- [ ] **Step 6: Modelle**

`src/Models/UserNotificationSetting.php`: `KEY_COLUMNS` → `['user_id', 'notification_type', 'channel']`, `'channel'` in `$fillable`, Klassen-Doku um „je Kanal“ ergänzen.

`src/Models/UserNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Eintrag in der Glocke einer Person.
 */
class UserNotification extends Model
{
    protected $table = 'user_notifications';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'notification_type',
        'actor_user_id',
        'title',
        'body',
        'link',
        'entity_type',
        'entity_id',
        'read_at',
        'created_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'actor_user_id' => 'integer',
        'entity_id' => 'integer',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
```

- [ ] **Step 7: Migration ausführen, Test laufen lassen – erwartet PASS**

Run: `ddev exec ./vendor/bin/phinx migrate` (Ausgabe berichten), dann
`ddev php vendor/bin/phpunit --filter "UserNotificationSettingTest|ModelSchemaConsistencyTest|MigrationChainCompletionTest" 2>&1 | tail -15`

- [ ] **Step 8: Rollback-Probe der Migration**

Run: `ddev exec ./vendor/bin/phinx rollback -t 20261004130000` und danach wieder `ddev exec ./vendor/bin/phinx migrate`. Beide müssen ohne Fehler durchlaufen.

- [ ] **Step 9: Commit**

```bash
git add db/migrations/20261005090000_create_user_notifications.php db/migrations/20261005090100_add_channel_to_user_notification_settings.php src/Util/NotificationChannel.php src/Models/UserNotification.php src/Models/UserNotificationSetting.php tests/Unit/Models/
git commit -m "feat(notifications): Tabelle für Glocken-Einträge und Einstellungen je Kanal"
```

---

### Task 2: Wertobjekt `InAppMessage`

**Files:**
- Create: `src/Services/Notifications/InAppMessage.php`
- Test: `tests/Unit/Services/Notifications/InAppMessageTest.php`

**Interfaces:**
- Produces: `new InAppMessage(string $title, ?string $body, string $link, ?string $entityType = null, ?int $entityId = null)`; öffentliche readonly-Eigenschaften `title`, `body` (bereinigt: ohne Tags, Leerraum zusammengefasst, max. 140 Zeichen inkl. „…“, leer → `null`), `link`, `entityType`, `entityId`. Wirft `\InvalidArgumentException` bei ungültigem Link oder leerem Titel. Konstante `InAppMessage::BODY_MAX_LENGTH = 140`. Titel wird auf 255 Zeichen gekürzt.

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notifications;

use App\Services\Notifications\InAppMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Glockentext kommt aus Kommentaren und Bemerkungen - also aus Eingaben.
 * Er muss als eine kurze Zeile reinen Textes ankommen, und der Link darf nie
 * aus der Anwendung hinausführen.
 */
final class InAppMessageTest extends TestCase
{
    public function testAPlainMessageKeepsItsValues(): void
    {
        $message = new InAppMessage('Neuer Kommentar: Saal', 'Ist reserviert.', '/tasks/7', 'task', 7);

        $this->assertSame('Neuer Kommentar: Saal', $message->title);
        $this->assertSame('Ist reserviert.', $message->body);
        $this->assertSame('/tasks/7', $message->link);
        $this->assertSame('task', $message->entityType);
        $this->assertSame(7, $message->entityId);
    }

    public function testHtmlAndLineBreaksBecomeOneLineOfText(): void
    {
        $message = new InAppMessage('T', "<p>Erste&nbsp;Zeile</p>\n<p><strong>zweite</strong></p>", '/x');

        $this->assertSame('Erste Zeile zweite', $message->body);
    }

    public function testALongBodyIsCutWithAnEllipsis(): void
    {
        $message = new InAppMessage('T', str_repeat('ä', 200), '/x');

        $this->assertSame(InAppMessage::BODY_MAX_LENGTH, mb_strlen((string) $message->body));
        $this->assertStringEndsWith('…', (string) $message->body);
    }

    public function testAnEmptyBodyBecomesNull(): void
    {
        $this->assertNull((new InAppMessage('T', '  <br> ', '/x'))->body);
    }

    public function testAnEmptyTitleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InAppMessage('  ', null, '/x');
    }

    #[DataProvider('foreignLinks')]
    public function testALinkOutOfTheApplicationIsRejected(string $link): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InAppMessage('T', null, $link);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignLinks(): array
    {
        return [
            'absolut' => ['https://evil.example/'],
            'protokollrelativ' => ['//evil.example/'],
            'Backslash' => ['/\\evil.example/'],
            'relativ ohne Schrägstrich' => ['tasks/7'],
            'leer' => [''],
            'javascript' => ['javascript:alert(1)'],
        ];
    }
}
```

- [ ] **Step 2: Run – erwartet FAIL** (Klasse fehlt)

Run: `ddev php vendor/bin/phpunit --filter InAppMessageTest 2>&1 | tail -15`

- [ ] **Step 3: Implementierung**

```php
<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Was ein Anlass in der Glocke zeigt: Titel, eine Zeile Text und wohin der
 * Eintrag führt.
 *
 * Der Text stammt oft aus Eingaben - Kommentare, Bemerkungen -, die HTML aus
 * dem Editor tragen. Hier wird er einmal zu einer kurzen Zeile reinen Textes,
 * damit weder der Controller noch das Frontend daran denken müssen.
 */
final class InAppMessage
{
    public const BODY_MAX_LENGTH = 140;

    private const TITLE_MAX_LENGTH = 255;

    public readonly string $title;

    public readonly ?string $body;

    public function __construct(
        string $title,
        ?string $body,
        public readonly string $link,
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null
    ) {
        $title = self::plainText($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Ein Glocken-Eintrag braucht einen Titel.');
        }

        // Genau ein führender Schrägstrich: `//host` und `/\host` behandeln
        // Browser als Adresse eines fremden Servers.
        if (preg_match('#^/(?![/\\\\])#', $link) !== 1) {
            throw new \InvalidArgumentException('Ein Glocken-Eintrag verlinkt nur innerhalb der Anwendung: ' . $link);
        }

        $this->title = self::truncate($title, self::TITLE_MAX_LENGTH);

        $body = self::plainText((string) $body);
        $this->body = $body === '' ? null : self::truncate($body, self::BODY_MAX_LENGTH);
    }

    private static function plainText(string $value): string
    {
        // Blockgrenzen werden zu Leerzeichen, sonst klebten zwei Absätze aneinander.
        $withSpaces = preg_replace('/<(br|\/p|\/div|\/li)[^>]*>/i', ' ', $value) ?? $value;
        $text = html_entity_decode(strip_tags($withSpaces), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength - 1)) . '…';
    }
}
```

Hinweis zum Test `testALongBodyIsCutWithAnEllipsis`: Bei `ä`-Wiederholung entfernt `rtrim` nichts; Länge ist genau 140.

- [ ] **Step 4: Run – erwartet PASS**

Run: `ddev php vendor/bin/phpunit --filter InAppMessageTest 2>&1 | tail -15`

- [ ] **Step 5: Commit**

```bash
git add src/Services/Notifications/InAppMessage.php tests/Unit/Services/Notifications/InAppMessageTest.php
git commit -m "feat(notifications): Glockentext als eigenes Wertobjekt mit Link-Prüfung"
```

---

### Task 3: `InAppNotificationStore`

**Files:**
- Create: `src/Services/Notifications/InAppNotificationStore.php`
- Test: `tests/Feature/InAppNotificationStoreFeatureTest.php`

**Interfaces:**
- Consumes: `InAppMessage` (Task 2), `UserNotification` (Task 1).
- Produces (alle `public`):
  - `createMany(string $type, InAppMessage $message, list<int> $userIds, ?int $actorUserId): int` – ein INSERT, gibt Anzahl zurück.
  - `unreadCount(int $userId): int`
  - `recent(int $userId, int $limit = 10): \Illuminate\Support\Collection<int, UserNotification>` (neueste zuerst, mit `actor`)
  - `paginate(int $userId, int $page, bool $onlyUnread, int $perPage = 25): array{items: Collection<int, UserNotification>, total: int, page: int, pages: int}`
  - `findForUser(int $userId, int $id): ?UserNotification`
  - `markRead(UserNotification $notification): void`
  - `markAllRead(int $userId): int`
  - `markEntityRead(int $userId, string $entityType, int $entityId): int`
  - `prune(?\Carbon\CarbonInterface $now = null): int` – gelesen > 30 Tage, ungelesen > 180 Tage.
  - Konstanten `READ_RETENTION_DAYS = 30`, `UNREAD_RETENTION_DAYS = 180`.

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\InAppMessage;
use App\Services\Notifications\InAppNotificationStore;
use App\Util\NotificationType;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Glocke einer Person zeigt nur ihre eigenen Einträge, und „gelesen“
 * trifft nie die Einträge anderer.
 */
final class InAppNotificationStoreFeatureTest extends TestCase
{
    private User $anna;
    private User $bernd;
    private InAppNotificationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
        $this->store = new InAppNotificationStore();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testCreateManyWritesOneEntryPerRecipient(): void
    {
        $created = $this->store->createMany(
            NotificationType::TASK_COMMENT,
            new InAppMessage('Neuer Kommentar: Saal', 'Ist reserviert.', '/tasks/7', 'task', 7),
            [(int) $this->anna->id, (int) $this->bernd->id],
            null
        );

        $this->assertSame(2, $created);
        $entry = UserNotification::where('user_id', $this->anna->id)->firstOrFail();
        $this->assertSame('Neuer Kommentar: Saal', $entry->title);
        $this->assertSame('/tasks/7', $entry->link);
        $this->assertSame('task', $entry->entity_type);
        $this->assertSame(7, $entry->entity_id);
        $this->assertNull($entry->read_at);
        $this->assertNotNull($entry->created_at);
    }

    public function testCreateManyWithoutRecipientsWritesNothing(): void
    {
        $this->assertSame(0, $this->store->createMany(
            NotificationType::TASK_COMMENT,
            new InAppMessage('T', null, '/x'),
            [],
            null
        ));
    }

    public function testUnreadCountOnlyCountsOwnUnreadEntries(): void
    {
        $this->entry($this->anna);
        $this->entry($this->anna, readAt: Carbon::now());
        $this->entry($this->bernd);

        $this->assertSame(1, $this->store->unreadCount((int) $this->anna->id));
    }

    public function testRecentReturnsTheNewestOwnEntriesFirst(): void
    {
        $this->entry($this->anna, title: 'alt', createdAt: Carbon::now()->subHour());
        $this->entry($this->anna, title: 'neu', createdAt: Carbon::now());
        $this->entry($this->bernd, title: 'fremd');

        $titles = $this->store->recent((int) $this->anna->id, 10)->pluck('title')->all();

        $this->assertSame(['neu', 'alt'], $titles);
    }

    public function testPaginateCanFilterUnreadAndCountsPages(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->entry($this->anna, createdAt: Carbon::now()->subMinutes($i));
        }
        $this->entry($this->anna, readAt: Carbon::now());

        $all = $this->store->paginate((int) $this->anna->id, 2, false);
        $unread = $this->store->paginate((int) $this->anna->id, 1, true);

        $this->assertSame(31, $all['total']);
        $this->assertSame(2, $all['pages']);
        $this->assertCount(6, $all['items']);
        $this->assertSame(30, $unread['total']);
    }

    public function testFindForUserIgnoresForeignEntries(): void
    {
        $foreign = $this->entry($this->bernd);

        $this->assertNull($this->store->findForUser((int) $this->anna->id, (int) $foreign->id));
    }

    public function testMarkAllReadLeavesOtherPeopleAlone(): void
    {
        $this->entry($this->anna);
        $foreign = $this->entry($this->bernd);

        $this->assertSame(1, $this->store->markAllRead((int) $this->anna->id));
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function testMarkEntityReadOnlyTouchesThatObjectAndThatPerson(): void
    {
        $match = $this->entry($this->anna, entityType: 'task', entityId: 7);
        $otherTask = $this->entry($this->anna, entityType: 'task', entityId: 8);
        $otherPerson = $this->entry($this->bernd, entityType: 'task', entityId: 7);

        $this->assertSame(1, $this->store->markEntityRead((int) $this->anna->id, 'task', 7));
        $this->assertNotNull($match->fresh()->read_at);
        $this->assertNull($otherTask->fresh()->read_at);
        $this->assertNull($otherPerson->fresh()->read_at);
    }

    public function testPruneKeepsEntriesInsideTheirRetention(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $readOld = $this->entry($this->anna, readAt: Carbon::now()->subDays(31), createdAt: Carbon::now()->subDays(31));
        $readFresh = $this->entry($this->anna, readAt: Carbon::now()->subDays(29), createdAt: Carbon::now()->subDays(40));
        $unreadOld = $this->entry($this->anna, createdAt: Carbon::now()->subDays(181));
        $unreadFresh = $this->entry($this->anna, createdAt: Carbon::now()->subDays(179));

        $this->assertSame(2, $this->store->prune());
        $this->assertNull($readOld->fresh());
        $this->assertNotNull($readFresh->fresh());
        $this->assertNull($unreadOld->fresh());
        $this->assertNotNull($unreadFresh->fresh());
    }

    private function entry(
        User $user,
        string $title = 'Eintrag',
        ?Carbon $readAt = null,
        ?Carbon $createdAt = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): UserNotification {
        return UserNotification::create([
            'user_id' => $user->id,
            'notification_type' => NotificationType::TASK_COMMENT,
            'title' => $title,
            'link' => '/tasks/1',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'read_at' => $readAt,
            'created_at' => $createdAt ?? Carbon::now(),
        ]);
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'bell.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }
}
```

Hinweis zur Prune-Grenze bei gelesenen Einträgen: gemessen wird an `read_at` (wie lange schon gelesen), nicht an `created_at` – deshalb bleibt `readFresh` trotz `created_at` vor 40 Tagen.

- [ ] **Step 2: Run – erwartet FAIL**

Run: `ddev php vendor/bin/phpunit --filter InAppNotificationStoreFeatureTest 2>&1 | tail -15`

- [ ] **Step 3: Implementierung**

```php
<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\UserNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Alles, was die Glocke in der Datenbank tut.
 *
 * Jede Abfrage beginnt bei der `user_id` - einen Eintrag ohne diese Schranke zu
 * lesen oder zu ändern, ist hier gar nicht vorgesehen.
 */
class InAppNotificationStore
{
    public const READ_RETENTION_DAYS = 30;
    public const UNREAD_RETENTION_DAYS = 180;

    /**
     * Ein INSERT für alle Empfänger: Ein Termin erreicht schnell hundert Mitglieder.
     *
     * @param list<int> $userIds
     */
    public function createMany(string $type, InAppMessage $message, array $userIds, ?int $actorUserId): int
    {
        if ($userIds === []) {
            return 0;
        }

        $now = Carbon::now();
        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = [
                'user_id' => $userId,
                'notification_type' => $type,
                'actor_user_id' => $actorUserId,
                'title' => $message->title,
                'body' => $message->body,
                'link' => $message->link,
                'entity_type' => $message->entityType,
                'entity_id' => $message->entityId,
                'read_at' => null,
                'created_at' => $now,
            ];
        }

        UserNotification::query()->insert($rows);

        return count($rows);
    }

    public function unreadCount(int $userId): int
    {
        return $this->forUser($userId)->whereNull('read_at')->count();
    }

    /**
     * @return Collection<int, UserNotification>
     */
    public function recent(int $userId, int $limit = 10): Collection
    {
        return $this->forUser($userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{items: Collection<int, UserNotification>, total: int, page: int, pages: int}
     */
    public function paginate(int $userId, int $page, bool $onlyUnread, int $perPage = 25): array
    {
        $query = $this->forUser($userId);
        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        $items = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function findForUser(int $userId, int $id): ?UserNotification
    {
        return $this->forUser($userId)->where('id', $id)->first();
    }

    public function markRead(UserNotification $notification): void
    {
        if ($notification->read_at !== null) {
            return;
        }

        $notification->read_at = Carbon::now();
        $notification->save();
    }

    public function markAllRead(int $userId): int
    {
        return $this->forUser($userId)->whereNull('read_at')->update(['read_at' => Carbon::now()]);
    }

    public function markEntityRead(int $userId, string $entityType, int $entityId): int
    {
        return $this->forUser($userId)
            ->whereNull('read_at')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->update(['read_at' => Carbon::now()]);
    }

    /**
     * Gelesene Einträge zählen ab dem Lesen, ungelesene ab dem Anlegen.
     */
    public function prune(?CarbonInterface $now = null): int
    {
        $now = $now ?? Carbon::now();

        $read = UserNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $now->copy()->subDays(self::READ_RETENTION_DAYS))
            ->delete();

        $unread = UserNotification::query()
            ->whereNull('read_at')
            ->where('created_at', '<', $now->copy()->subDays(self::UNREAD_RETENTION_DAYS))
            ->delete();

        return (int) $read + (int) $unread;
    }

    /**
     * @return Builder<UserNotification>
     */
    private function forUser(int $userId): Builder
    {
        return UserNotification::query()->where('user_id', $userId);
    }
}
```

- [ ] **Step 4: Run – erwartet PASS**

Run: `ddev php vendor/bin/phpunit --filter InAppNotificationStoreFeatureTest 2>&1 | tail -15`

- [ ] **Step 5: Commit**

```bash
git add src/Services/Notifications/InAppNotificationStore.php tests/Feature/InAppNotificationStoreFeatureTest.php
git commit -m "feat(notifications): Speicher für Glocken-Einträge mit Gelesen-Logik und Aufräumen"
```

---

### Task 4: Kanal-Logik im `NotificationService`

**Files:**
- Modify: `src/Services/NotificationService.php`
- Modify: `tests/Feature/NotificationServiceFeatureTest.php` (bestehende `notify()`-Aufrufe auf neue Signatur, neue Tests)
- Modify: alle weiteren Tests mit `->notify(` (`grep -rln "notify(" tests`) auf neue Signatur

**Interfaces:**
- Consumes: `InAppMessage`, `InAppNotificationStore`, `NotificationChannel`.
- Produces:
  - Konstruktor: `__construct(MailQueueService $mailQueueService, Twig $view, LoggerInterface $logger, array $modules = [], ?InAppNotificationStore $inAppStore = null)` – fehlt der Store, wird `new InAppNotificationStore()` verwendet.
  - `notify(string $type, iterable $recipients, string $subject, string $template, InAppMessage $inApp, array $context = [], ?int $actorUserId = null): int` – Rückgabe = Anzahl eingereihter **Mails**.
  - `wantsNotification(int $userId, string $type, string $channel = NotificationChannel::MAIL): bool`
  - `settingsFor(int $userId): array<string, array{mail: bool, in_app: bool}>`
  - `storeSettings(int $userId, array<string, array{mail: bool, in_app: bool}> $decisions): void`
  - `eligibleRecipients()` unverändert (Mail-Kanal).

- [ ] **Step 1: Bestehende Tests auf neue Signatur umstellen**

In `NotificationServiceFeatureTest` eine Hilfsmethode ergänzen und jeden `notify(`-Aufruf so ändern, dass nach `self::TEMPLATE` das Argument `$this->inApp()` steht:

```php
    private function inApp(): InAppMessage
    {
        return new InAppMessage('Neues Projekt: Testprojekt', 'Du bist jetzt dabei', '/projects/42/members', 'project', 42);
    }
```

Ebenso in allen anderen Testdateien, die `notify(` direkt aufrufen. `UserNotificationSetting::create`/`updateOrCreate` in Tests bekommen `'channel' => NotificationChannel::MAIL`, wo sie eine Mail-Abmeldung meinen.

- [ ] **Step 2: Neue failing tests in `NotificationServiceFeatureTest`**

```php
    public function testOneCallProducesAMailAndABellEntry(): void
    {
        $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna],
            'Testbetreff',
            self::TEMPLATE,
            $this->inApp(),
            $this->context()
        );

        $this->assertSame([$this->anna->email], $this->queuedRecipients());
        $this->assertSame(['Neues Projekt: Testprojekt'], $this->bellTitles($this->anna));
    }

    public function testOptingOutOfTheBellKeepsTheMail(): void
    {
        $this->optOut($this->anna, NotificationChannel::IN_APP);

        $this->notifyAnnaAndBernd();

        $this->assertSame([$this->anna->email, $this->bernd->email], $this->queuedRecipients());
        $this->assertSame([], $this->bellTitles($this->anna));
        $this->assertCount(1, $this->bellTitles($this->bernd));
    }

    public function testOptingOutOfTheMailKeepsTheBell(): void
    {
        $this->optOut($this->anna, NotificationChannel::MAIL);

        $this->notifyAnnaAndBernd();

        $this->assertSame([$this->bernd->email], $this->queuedRecipients());
        $this->assertCount(1, $this->bellTitles($this->anna));
    }

    public function testAGloballyDisabledTypeRingsNoBell(): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => NotificationType::settingKey(NotificationType::PROJECT_MEMBER_ADDED)],
            ['setting_value' => '0']
        );

        $this->notifyAnnaAndBernd();

        $this->assertSame([], $this->bellTitles($this->anna));
    }

    public function testAMemberWithoutAnEmailAddressStillGetsTheBell(): void
    {
        $this->anna->email = '';
        $this->anna->save();

        $this->notifyAnnaAndBernd();

        $this->assertSame([$this->bernd->email], $this->queuedRecipients());
        $this->assertCount(1, $this->bellTitles($this->anna));
    }

    public function testTheTriggeringPersonAndInactiveMembersGetNoBell(): void
    {
        $this->bernd->is_active = 0;
        $this->bernd->save();

        $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->bernd],
            'Testbetreff',
            self::TEMPLATE,
            $this->inApp(),
            $this->context(),
            (int) $this->anna->id
        );

        $this->assertSame([], $this->bellTitles($this->anna));
        $this->assertSame([], $this->bellTitles($this->bernd));
    }

    public function testAPersonListedTwiceGetsOneBellEntry(): void
    {
        $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->anna],
            'Testbetreff',
            self::TEMPLATE,
            $this->inApp(),
            $this->context()
        );

        $this->assertCount(1, $this->bellTitles($this->anna));
    }

    /**
     * Scheitert das Schreiben der Glocke, gehen die Mails trotzdem raus - und
     * der Fehler steht im Log.
     */
    public function testAFailingBellDoesNotStopTheMails(): void
    {
        [$logger, $handler] = $this->logger();
        $store = $this->createStub(InAppNotificationStore::class);
        $store->method('createMany')->willThrowException(new \RuntimeException('kaputt'));

        $sent = (new NotificationService(
            new MailQueueService(),
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            $logger,
            ['tasks' => true, 'sponsoring' => true],
            $store
        ))->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna],
            'Testbetreff',
            self::TEMPLATE,
            $this->inApp(),
            $this->context()
        );

        $this->assertSame(1, $sent);
        $this->assertNotNull($this->recordFor($handler, 'notification.in_app_failed'));
    }

    public function testSettingsAreStoredPerChannel(): void
    {
        $service = $this->service();
        $service->storeSettings((int) $this->anna->id, [
            NotificationType::TASK_COMMENT => ['mail' => false, 'in_app' => true],
        ]);

        $this->assertFalse($service->wantsNotification((int) $this->anna->id, NotificationType::TASK_COMMENT));
        $this->assertTrue($service->wantsNotification(
            (int) $this->anna->id,
            NotificationType::TASK_COMMENT,
            NotificationChannel::IN_APP
        ));
        $this->assertSame(
            ['mail' => false, 'in_app' => true],
            $service->settingsFor((int) $this->anna->id)[NotificationType::TASK_COMMENT]
        );
        $this->assertSame(1, $this->settingRowCount(), 'Nur die Abweichung wird gespeichert.');
    }
```

Hilfsmethoden ergänzen (die Klasse bekommt `use TestHttpHelpers;` für `logger()`/`recordFor()`):

```php
    private function notifyAnnaAndBernd(): void
    {
        $this->service()->notify(
            NotificationType::PROJECT_MEMBER_ADDED,
            [$this->anna, $this->bernd],
            'Testbetreff',
            self::TEMPLATE,
            $this->inApp(),
            $this->context()
        );
    }

    private function optOut(User $user, string $channel): void
    {
        UserNotificationSetting::create([
            'user_id' => $user->id,
            'notification_type' => NotificationType::PROJECT_MEMBER_ADDED,
            'channel' => $channel,
            'enabled' => false,
        ]);
    }

    /**
     * @return list<string>
     */
    private function bellTitles(User $user): array
    {
        return UserNotification::where('user_id', $user->id)->orderBy('id')->pluck('title')->all();
    }
```

- [ ] **Step 3: Run – erwartet FAIL** (Signatur passt nicht / Glocke fehlt)

Run: `ddev php vendor/bin/phpunit --filter NotificationServiceFeatureTest 2>&1 | tail -20`

- [ ] **Step 4: Implementierung in `NotificationService`**

Konstruktor:

```php
    private readonly InAppNotificationStore $inAppStore;

    /**
     * @param array<string, bool> $modules Flags aus `settings.modules`
     */
    public function __construct(
        private readonly MailQueueService $mailQueueService,
        private readonly Twig $view,
        private readonly LoggerInterface $logger,
        private readonly array $modules = [],
        ?InAppNotificationStore $inAppStore = null
    ) {
        $this->inAppStore = $inAppStore ?? new InAppNotificationStore();
    }
```

`notify()` – neue Signatur und Ablauf:

```php
    /**
     * Benachrichtigt alle Empfänger, die es wollen - per Mail und in der Glocke,
     * jeder Kanal für sich geprüft. Gibt zurück, wie viele **Mails** eingereiht
     * wurden.
     *
     * `$actorUserId` ist die Person, die den Anlass ausgelöst hat. Sie bekommt
     * nichts: Wer sich selbst eine Aufgabe zuweist oder den eigenen Kommentar
     * schreibt, weiß es bereits.
     *
     * @param iterable<User> $recipients
     * @param array<string, mixed> $context Zusätzliche Variablen für die Mail-Vorlage
     */
    public function notify(
        string $type,
        iterable $recipients,
        string $subject,
        string $template,
        InAppMessage $inApp,
        array $context = [],
        ?int $actorUserId = null
    ): int {
        if (!$this->isAvailable($type)) {
            return 0;
        }

        $candidates = $this->candidates($recipients, $actorUserId);
        if ($candidates === []) {
            return 0;
        }

        $inAppCount = $this->ringBell($type, $inApp, $candidates, $actorUserId);
        $enqueued = $this->enqueueMails($type, $candidates, $subject, $template, $context);

        $this->logger->info('Notifications enqueued.', [
            'event' => 'notification.enqueued',
            'notification_type' => $type,
            'recipient_count' => $enqueued,
            'in_app_count' => $inAppCount,
        ]);

        return $enqueued;
    }
```

Den bisherigen Schleifenrumpf aus `notify()` (Branding, `fetch`, `enqueueNotificationMail`, try/catch je Empfänger) unverändert in `private function enqueueMails(string $type, array $candidates, string $subject, string $template, array $context): int` verschieben; am Anfang:

```php
        $eligible = $this->withoutOptedOut($type, NotificationChannel::MAIL, array_filter(
            $candidates,
            static function (User $user): bool {
                $email = trim((string) $user->email);

                return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            }
        ));
        if ($eligible === []) {
            return 0;
        }
        $branding = MailBranding::resolve();
```

Glocke:

```php
    /**
     * @param array<int, User> $candidates
     */
    private function ringBell(string $type, InAppMessage $inApp, array $candidates, ?int $actorUserId): int
    {
        $eligible = $this->withoutOptedOut($type, NotificationChannel::IN_APP, $candidates);
        if ($eligible === []) {
            return 0;
        }

        try {
            return $this->inAppStore->createMany(
                $type,
                $inApp,
                array_map(static fn (User $user): int => (int) $user->id, $eligible),
                $actorUserId
            );
        } catch (\Throwable $e) {
            // Die Glocke ist der leisere Kanal: Scheitert sie, sollen die Mails
            // trotzdem hinausgehen.
            $this->logger->error('Writing in-app notifications failed.', [
                'event' => 'notification.in_app_failed',
                'notification_type' => $type,
                'exception' => $e,
            ]);

            return 0;
        }
    }
```

`filterRecipients()` ersetzen durch zwei Schritte:

```php
    /**
     * Wer überhaupt in Frage kommt - für beide Kanäle gleich: nicht die
     * auslösende Person, nur aktive Konten, jede Person einmal.
     *
     * @param iterable<User> $recipients
     * @return array<int, User> nach `user_id`
     */
    private function candidates(iterable $recipients, ?int $actorUserId): array
    {
        $candidates = [];
        foreach ($recipients as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $userId = (int) $user->id;
            if ($userId <= 0 || ($actorUserId !== null && $userId === $actorUserId) || !$user->is_active) {
                continue;
            }

            // Dieselbe Person kann über zwei Wege in der Liste stehen - etwa als
            // Zugewiesene *und* als Erstellerin einer Aufgabe.
            $candidates[$userId] = $user;
        }

        return $candidates;
    }

    /**
     * @param array<int, User> $users
     * @return list<User>
     */
    private function withoutOptedOut(string $type, string $channel, array $users): array
    {
        if ($users === []) {
            return [];
        }

        $optedOut = $this->optedOutUserIds($type, $channel, array_keys($users));

        return array_values(array_filter(
            $users,
            static fn (User $user): bool => !in_array((int) $user->id, $optedOut, true)
        ));
    }
```

`optedOutUserIds(string $type, string $channel, array $userIds)` bekommt `->where('channel', $channel)`.

`eligibleRecipients()`:

```php
        $candidates = $this->candidates($recipients, $actorUserId);
        $withEmail = array_filter($candidates, static fn (User $user): bool =>
            filter_var(trim((string) $user->email), FILTER_VALIDATE_EMAIL) !== false);

        return new Collection($this->withoutOptedOut($type, NotificationChannel::MAIL, $withEmail));
```

Einstellungen:

```php
    public function wantsNotification(int $userId, string $type, string $channel = NotificationChannel::MAIL): bool
    {
        $setting = UserNotificationSetting::query()
            ->where('user_id', $userId)
            ->where('notification_type', $type)
            ->where('channel', $channel)
            ->value('enabled');

        if ($setting === null) {
            return NotificationType::defaultEnabled($type);
        }

        return (bool) $setting;
    }

    /**
     * Die Entscheidungen einer Person über alle Anlässe und beide Kanäle, für
     * das Profilformular.
     *
     * @return array<string, array{mail: bool, in_app: bool}>
     */
    public function settingsFor(int $userId): array
    {
        $stored = [];
        foreach (UserNotificationSetting::query()->where('user_id', $userId)->get() as $row) {
            $stored[(string) $row->notification_type][(string) $row->channel] = (bool) $row->enabled;
        }

        $settings = [];
        foreach (NotificationType::all() as $type) {
            $default = NotificationType::defaultEnabled($type);
            $settings[$type] = [
                NotificationChannel::MAIL => $stored[$type][NotificationChannel::MAIL] ?? $default,
                NotificationChannel::IN_APP => $stored[$type][NotificationChannel::IN_APP] ?? $default,
            ];
        }

        return $settings;
    }

    /**
     * Übernimmt die Entscheidungen aus dem Profilformular, je Anlass und Kanal.
     *
     * Gespeichert wird nur, was von der Vorgabe abweicht; deckt sich die
     * Entscheidung mit ihr, verschwindet die Zeile wieder.
     *
     * @param array<string, array<string, bool>> $decisions
     */
    public function storeSettings(int $userId, array $decisions): void
    {
        foreach ($decisions as $type => $channels) {
            if (!NotificationType::exists($type)) {
                continue;
            }

            foreach (NotificationChannel::all() as $channel) {
                if (!array_key_exists($channel, $channels)) {
                    continue;
                }

                $enabled = (bool) $channels[$channel];
                $key = ['user_id' => $userId, 'notification_type' => $type, 'channel' => $channel];

                if ($enabled === NotificationType::defaultEnabled($type)) {
                    UserNotificationSetting::query()->where($key)->delete();
                    continue;
                }

                UserNotificationSetting::updateOrCreate($key, ['enabled' => $enabled]);
            }
        }
    }
```

Klassen-Doku um einen Absatz ergänzen: Die Glocke ist der zweite Kanal; die Kette gilt je Kanal, die E-Mail-Adresse nur für die Mail.

Andere Aufrufer von `wantsNotification`/`settingsFor` prüfen: `grep -rn "settingsFor\|wantsNotification" src templates` – `ProfileController` wird in Task 7 angepasst.

- [ ] **Step 5: Run – erwartet PASS** (Service-Tests; Trigger-Tests sind bis Task 5/6 rot, weil die Controller die alte Signatur nutzen – das ist erwartet)

Run: `ddev php vendor/bin/phpunit --filter "NotificationServiceFeatureTest|UserNotificationSettingTest" 2>&1 | tail -20`

- [ ] **Step 6: Commit**

```bash
git add src/Services/NotificationService.php tests/
git commit -m "feat(notifications): Glocke als zweiter Kanal im NotificationService"
```

(Die Aufrufstellen folgen in Task 5 und 6; bis dahin ist der Branch-Stand nicht lauffähig – Task 5 und 6 direkt anschließen.)

---

### Task 5: Glockentexte für Aufgaben, Projekte und Erinnerungen; Aufräumen

**Files:**
- Modify: `src/Controllers/TaskController.php` (`notifyAssignment` um Zeile 212, `notifyComment` um Zeile 251)
- Modify: `src/Controllers/ProjectController.php` (um Zeile 379)
- Modify: `src/Services/NotificationReminderService.php` (Zeilen 132, 188; Aufräumen um Zeile 59/72)
- Test: `tests/Feature/NotificationTriggersFeatureTest.php`, `tests/Feature/NotificationReminderFeatureTest.php`, Projekt-Trigger-Test (`grep -rln "PROJECT_MEMBER_ADDED\|addMember" tests/Feature`)

**Interfaces:**
- Consumes: `notify(..., InAppMessage $inApp, array $context, ?int $actor)` (Task 4), `InAppNotificationStore::prune()` (Task 3).
- Produces: `NotificationReminderService::pruneExpiredInAppNotifications(): int`.

- [ ] **Step 1: Failing tests**

In `NotificationTriggersFeatureTest` (Klasse hat `$this->anna` als Handelnde, `$this->bernd`, `$this->clara`):

```php
    public function testCommentingRingsTheBellOfAssigneesAndCreator(): void
    {
        $task = $this->makeTask('Programmheft', [$this->bernd->id], (int) $this->clara->id);

        $this->controller()->addComment(
            $this->makeRequest('POST', '/tasks/' . $task->id . '/comments', [
                'content' => '<p>Der Saal ist <strong>reserviert</strong>.</p>',
            ]),
            $this->makeResponse(),
            ['id' => (string) $task->id]
        );

        $entries = UserNotification::orderBy('user_id')->get();
        $this->assertEqualsCanonicalizing(
            [(int) $this->bernd->id, (int) $this->clara->id],
            $entries->pluck('user_id')->all()
        );
        $first = $entries->first();
        $this->assertSame('Neuer Kommentar: Programmheft', $first->title);
        $this->assertStringEndsWith('Der Saal ist reserviert.', (string) $first->body);
        $this->assertSame('/tasks/' . $task->id, $first->link);
        $this->assertSame('task', $first->entity_type);
        $this->assertSame((int) $task->id, $first->entity_id);
        $this->assertSame((int) $this->anna->id, $first->actor_user_id);
    }

    public function testAssigningRingsTheBellOfTheNewAssignee(): void
    {
        // Aufbau wie testCreatingATaskNotifiesTheAssigneesButNotTheCreator
        // (dieselbe Anfrage an den Controller); danach:
        $entry = UserNotification::where('user_id', $this->bernd->id)->firstOrFail();
        $this->assertStringStartsWith('Neue Aufgabe: ', $entry->title);
        $this->assertSame('task', $entry->entity_type);
        $this->assertSame(0, UserNotification::where('user_id', $this->anna->id)->count());
    }
```

(Für `testAssigningRingsTheBellOfTheNewAssignee` den Request-Aufbau 1:1 aus `testCreatingATaskNotifiesTheAssigneesButNotTheCreator` kopieren.)

In `NotificationReminderFeatureTest`:

```php
    public function testAReminderRingsTheBellOnceAcrossRuns(): void
    {
        $task = $this->makeTask('Noten kopieren', '+1 day', [$this->anna->id]);

        $this->service()->run();
        $this->service()->run();

        $entries = UserNotification::where('user_id', $this->anna->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('Bald fällig: Noten kopieren', $entries->first()->title);
        $this->assertSame('/tasks/' . $task->id, $entries->first()->link);
    }

    public function testTheReminderRunPrunesOldBellEntries(): void
    {
        UserNotification::create([
            'user_id' => $this->anna->id,
            'notification_type' => NotificationType::TASK_COMMENT,
            'title' => 'Uralt',
            'link' => '/tasks/1',
            'read_at' => Carbon::now()->subDays(60),
            'created_at' => Carbon::now()->subDays(61),
        ]);

        $this->service()->run();

        $this->assertSame(0, UserNotification::where('title', 'Uralt')->count());
    }
```

(Namen der Hilfsmethoden und der öffentlichen Einstiegsmethode des Reminder-Service vor dem Schreiben in der Testklasse nachsehen – falls die Methode nicht `run()` heißt, den vorhandenen Namen nehmen. `Carbon` und `UserNotification` importieren.)

Im Projekt-Trigger-Test analog: Eintrag mit Titel `Neues Projekt: {Name}`, Link `/projects/{id}/members`, `entity_type` `project`.

- [ ] **Step 2: Run – erwartet FAIL**

Run: `ddev php vendor/bin/phpunit --filter "NotificationTriggersFeatureTest|NotificationReminderFeatureTest" 2>&1 | tail -20`

- [ ] **Step 3: Implementierung TaskController**

`notifyAssignment` (Zeile ~212): nach dem Template-Argument

```php
            new InAppMessage(
                'Neue Aufgabe: ' . $task->name,
                $actorName === null ? 'Du wurdest eingetragen' : $actorName . ' hat dich eingetragen',
                '/tasks/' . $task->id,
                'task',
                (int) $task->id
            ),
```

mit `$actorName = $this->actorName($actorId);` vor dem Aufruf (und im Kontext `'actor_name' => $actorName` wiederverwenden).

`notifyComment` (Zeile ~251):

```php
            new InAppMessage(
                'Neuer Kommentar: ' . $task->name,
                $actorName === null ? $comment : $actorName . ': ' . $comment,
                '/tasks/' . $task->id,
                'task',
                (int) $task->id
            ),
```

`use App\Services\Notifications\InAppMessage;` ergänzen.

- [ ] **Step 4: Implementierung ProjectController** (Zeile ~379)

```php
            new InAppMessage(
                'Neues Projekt: ' . $project->name,
                'Du bist jetzt dabei',
                '/projects/' . $project->id . '/members',
                'project',
                (int) $project->id
            ),
```

- [ ] **Step 5: Implementierung NotificationReminderService**

Due soon (Zeile ~132):

```php
                    new InAppMessage(
                        'Bald fällig: ' . $task->name,
                        'Fällig am ' . Carbon::parse((string) $task->end_date)->format('d.m.Y'),
                        '/tasks/' . $task->id,
                        'task',
                        (int) $task->id
                    ),
```

Follow-up (Zeile ~188):

```php
                new InAppMessage(
                    'Wiedervorlage: ' . ($contact->sponsor->name ?? 'Sponsor'),
                    'Fällig am ' . Carbon::parse($followUpDate)->format('d.m.Y'),
                    '/sponsoring/sponsors/' . $contact->sponsor_id,
                    'sponsor',
                    (int) $contact->sponsor_id
                ),
```

Aufräumen: Konstruktor bekommt `?InAppNotificationStore $inAppStore = null` als letzten Parameter (Default `new InAppNotificationStore()`; Klasse ist autowired – optionaler Parameter wird von PHP-DI mit `null` belegt, deshalb der Fallback). Neben `pruneExpiredDispatchLog()` (Zeile ~59) `$this->pruneExpiredInAppNotifications();` aufrufen:

```php
    /**
     * Räumt alte Glocken-Einträge ab - im selben Takt wie das Versandprotokoll,
     * damit kein eigener Cronjob nötig ist.
     */
    public function pruneExpiredInAppNotifications(): int
    {
        try {
            $deleted = $this->inAppStore->prune();
        } catch (\Throwable $e) {
            $this->logger->error('In-app notification prune failed.', [
                'event' => 'notification.in_app_prune_failed',
                'exception' => $e,
            ]);

            return 0;
        }

        if ($deleted > 0) {
            $this->logger->debug('In-app notifications pruned.', [
                'event' => 'notification.in_app_pruned',
                'deleted_count' => $deleted,
            ]);
        }

        return $deleted;
    }
```

(Den Logger-Eigenschaftsnamen an die vorhandene Klasse angleichen.)

- [ ] **Step 6: Run – erwartet PASS**

Run: `ddev php vendor/bin/phpunit --filter "NotificationTriggersFeatureTest|NotificationReminderFeatureTest|Project.*Notif|NotificationReminderMiddleware" 2>&1 | tail -20`

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/TaskController.php src/Controllers/ProjectController.php src/Services/NotificationReminderService.php tests/Feature/
git commit -m "feat(notifications): Glockentexte für Aufgaben, Projekte und Erinnerungen"
```

---

### Task 6: Glockentexte für Termine

**Files:**
- Modify: `src/Controllers/EventController.php` (`notifyResolvedAudience` ~Zeile 233, `notifyAudience` ~Zeile 275 und die Aufrufer Zeilen ~757, ~1025, ~1091, ~1388, ~1435, ~1512)
- Test: `tests/Feature/NotificationEventTriggersFeatureTest.php`

**Interfaces:**
- Consumes: `notify()` (Task 4).
- Produces: `notifyAudience(Request $request, string $type, array $events, string $subject, string $template, string $inAppBody, array $extraContext = [])`; `notifyResolvedAudience(Request $request, string $type, Collection $recipients, array $events, string $subject, string $template, string $inAppBody, array $extraContext = [])`; private `describeWhen(list<Event> $events): string`.

- [ ] **Step 1: Failing tests** (Hilfsmethoden der Klasse: `createEventViaController()`, `updateEvent()`, `body()`, `clearQueue()`)

```php
    public function testANewEventRingsTheBellWithDateAndLink(): void
    {
        $event = $this->createEventViaController();

        $entry = UserNotification::where('notification_type', NotificationType::EVENT_CREATED)->firstOrFail();
        $this->assertSame('Neuer Termin: ' . $event->title, $entry->title);
        $this->assertSame($event->starts_at->format('d.m.Y, H:i') . ' Uhr', $entry->body);
        $this->assertSame('/events/' . $event->id, $entry->link);
        $this->assertSame('event', $entry->entity_type);
    }

    public function testAMovedEventRingsTheBellWithTheNewTime(): void
    {
        $event = $this->createEventViaController();
        UserNotification::query()->delete();

        // Gleiche Überschreibung wie in testAMovedEventNotifiesItsAudience:
        $this->updateEvent($event, [/* dieselben Felder wie dort */]);

        $entry = UserNotification::where('notification_type', NotificationType::EVENT_CHANGED)->firstOrFail();
        $this->assertStringStartsWith('Termin geändert: ', $entry->title);
        $this->assertStringContainsString('Beginn: ', (string) $entry->body);
    }

    public function testACancelledEventLinksToTheOverview(): void
    {
        // Aufbau wie testADeletedEventNotifiesTheAudienceItStillHad, danach:
        $entry = UserNotification::where('notification_type', NotificationType::EVENT_CANCELLED)->firstOrFail();
        $this->assertStringStartsWith('Abgesagt: ', $entry->title);
        $this->assertSame('/events', $entry->link);
        $this->assertNull($entry->entity_type);
    }

    public function testAPublicNoteRingsTheBellWithTheNote(): void
    {
        // Aufbau wie testAPublicNoteReachesTheAudience, danach:
        $entry = UserNotification::where('notification_type', NotificationType::EVENT_NOTE)->firstOrFail();
        $this->assertStringStartsWith('Bemerkung zu: ', $entry->title);
        $this->assertStringContainsString('<Notiztext aus dem Aufbau>', (string) $entry->body);
    }

    public function testAPrivateNoteRingsNoBell(): void
    {
        // Aufbau wie testAPrivateNoteIsNotSentAround, danach:
        $this->assertSame(0, UserNotification::where('notification_type', NotificationType::EVENT_NOTE)->count());
    }
```

Die Aufbau-Kommentare beim Schreiben durch den kopierten Code der genannten bestehenden Tests ersetzen (der Plan-Leser hat die Datei vor sich; die Platzhalter `<…>` dürfen nicht im Test stehen bleiben). Außerdem in `testAWholeSeriesProducesExactlyOneMailPerRecipient` ergänzen: genau ein Glocken-Eintrag pro Empfänger, `body` beginnt mit der Anzahl, z. B. `assertMatchesRegularExpression('/^\d+ Termine ab \d{2}\.\d{2}\.\d{4}$/', $body)`.

- [ ] **Step 2: Run – erwartet FAIL**

Run: `ddev php vendor/bin/phpunit --filter NotificationEventTriggersFeatureTest 2>&1 | tail -20`

- [ ] **Step 3: Implementierung**

Hilfsmethode:

```php
    /**
     * Wann - für die Zeile in der Glocke. Eine Serie nennt Anzahl und Beginn,
     * sonst stünde dort nur der erste von vierzig Terminen.
     *
     * @param list<Event> $events
     */
    private function describeWhen(array $events): string
    {
        $first = $events[0] ?? null;
        if ($first === null || $first->starts_at === null) {
            return '';
        }

        if (count($events) > 1) {
            return count($events) . ' Termine ab ' . $first->starts_at->format('d.m.Y');
        }

        return $first->starts_at->format('d.m.Y, H:i') . ' Uhr';
    }
```

`notifyAudience(...)` bekommt nach `$template` den Parameter `string $inAppBody` und übergibt an `notify()`:

```php
            new InAppMessage($subject, $inAppBody, '/events/' . $first->id, 'event', (int) $first->id),
```

`notifyResolvedAudience(...)` ebenso, aber mit

```php
            // Der Termin ist gelöscht - der Eintrag führt in die Übersicht.
            new InAppMessage($subject, $inAppBody, '/events'),
```

Aufrufer:
- Bemerkung (~757): `$inAppBody = ($actorName !== null ? $actorName . ': ' : '') . $content` (Actor-Name einmal ermitteln und für Kontext wiederverwenden).
- Einzeltermin (~1025): `$this->describeWhen([$event->fresh()])` – das `fresh()`-Objekt einmal in einer Variablen halten.
- Serie (~1091): `$this->describeWhen($createdEvents)`.
- Geändert (~1388): `implode(' · ', array_map(static fn (array $c): string => $c['label'] . ': ' . $c['after'], $changes))`.
- Abgesagt einzeln (~1435): `$this->describeWhen([$cancelled])`; Serie (~1512): `$this->describeWhen($cancelled)`.

Die Betreffzeilen bleiben die Titel („Neuer Termin: …“, „Neue Termine: …“, „Termin geändert: …“, „Abgesagt: …“, „Bemerkung zu: …“). Die Spec-Tabelle nennt teils andere Wörter; sie erlaubt ausdrücklich die Angleichung an die Mail-Betreffzeilen.

- [ ] **Step 4: Run – erwartet PASS**

Run: `ddev php vendor/bin/phpunit --filter "NotificationEventTriggersFeatureTest|Notification" 2>&1 | tail -20`

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/EventController.php tests/Feature/NotificationEventTriggersFeatureTest.php
git commit -m "feat(notifications): Glockentexte für Termine"
```

---

### Task 7: Profil – zwei Schalter pro Anlass

**Files:**
- Modify: `src/Controllers/ProfileController.php` (`updateNotificationSettings` ~Zeile 296; `index` übergibt `notification_settings`)
- Modify: `templates/profile/index.twig` (~Zeilen 306–350)
- Modify: `public/css/style.css` (nur falls die Raster-Klassen von Bootstrap nicht reichen)
- Test: `tests/Feature/ProfileNotificationSettingsFeatureTest.php`

**Interfaces:**
- Consumes: `settingsFor()` / `storeSettings()` mit Kanälen (Task 4).
- Produces: Formularfelder `notifications[{type}][mail]`, `notifications[{type}][in_app]`.

- [ ] **Step 1: Tests anpassen und ergänzen**

`allCheckedExcept()` liefert künftig `array<string, array<string, string>>`:

```php
    /**
     * @return array<string, array<string, string>>
     */
    private function allCheckedExcept(?string $excludedType, ?string $excludedChannel = null): array
    {
        $checked = [];
        foreach ($this->service()->availableTypes() as $type) {
            foreach (NotificationChannel::all() as $channel) {
                if ($type === $excludedType && ($excludedChannel === null || $excludedChannel === $channel)) {
                    continue;
                }
                $checked[$type][$channel] = '1';
            }
        }

        return $checked;
    }
```

`submit()` nimmt `array<string, array<string, string>>`. Bestehende Tests anpassen: `testAnUncheckedBoxTurnsTheTypeOff` nutzt `allCheckedExcept($type, NotificationChannel::MAIL)`; `testCheckingItAgainRemovesTheStoredDeviation` ebenso.

Neu:

```php
    public function testTheBellCanBeTurnedOffWhileTheMailStays(): void
    {
        $type = NotificationType::TASK_COMMENT;

        $this->submit($this->allCheckedExcept($type, NotificationChannel::IN_APP));

        $service = $this->service();
        $userId = (int) $this->user->id;
        $this->assertTrue($service->wantsNotification($userId, $type, NotificationChannel::MAIL));
        $this->assertFalse($service->wantsNotification($userId, $type, NotificationChannel::IN_APP));
        $this->assertSame(1, UserNotificationSetting::where('user_id', $this->user->id)->count());
    }

    public function testAScalarValueForATypeTurnsBothChannelsOff(): void
    {
        // Ein zusammengebauter Aufruf mit dem alten Format darf nicht zu einem
        // Fehler führen; ein nicht als Liste gesendeter Anlass gilt als abgewählt.
        $notifications = $this->allCheckedExcept(NotificationType::TASK_COMMENT);
        $notifications[NotificationType::TASK_COMMENT] = '1';

        $this->submit($notifications);

        $this->assertFalse($this->service()->wantsNotification(
            (int) $this->user->id,
            NotificationType::TASK_COMMENT,
            NotificationChannel::IN_APP
        ));
    }
```

- [ ] **Step 2: Run – erwartet FAIL**

Run: `ddev php vendor/bin/phpunit --filter ProfileNotificationSettingsFeatureTest 2>&1 | tail -20`

- [ ] **Step 3: Controller**

```php
        $decisions = [];
        foreach ($this->notificationService->availableTypes() as $type) {
            $channels = is_array($submitted[$type] ?? null) ? $submitted[$type] : [];
            foreach (NotificationChannel::all() as $channel) {
                $decisions[$type][$channel] = !empty($channels[$channel]);
            }
        }
```

`index()`: `notification_settings` kommt bereits aus `settingsFor()` – Form ist jetzt `array{mail, in_app}`; nichts weiter nötig außer Prüfen der Template-Nutzung.

- [ ] **Step 4: Template** (Abschnitt ab `<form action="/profile/notifications" ...>` ersetzen)

```twig
                        <p class="text-muted">
                            Hier legst du fest, worüber dich der Chor-Manager informiert – per E-Mail, in der
                            Glocke oben rechts oder beides. Alles ist zu Beginn eingeschaltet.
                        </p>

                        <form action="/profile/notifications" method="post">
                            {% for group, types in notification_groups %}
                                <div class="row align-items-end mt-4 mb-2">
                                    <h3 class="col h6 text-uppercase text-muted mb-0">
                                        {{ notification_group_labels[group]|default(group) }}
                                    </h3>
                                    <div class="col-auto d-none d-md-flex notification-channel-heads text-muted small">
                                        <span>E-Mail</span>
                                        <span>Glocke</span>
                                    </div>
                                </div>
                                {% for type in types %}
                                    {% set _setting = notification_settings[type.type]|default({}) %}
                                    <div class="row align-items-center py-2 border-top">
                                        <div class="col-12 col-md">
                                            <div class="fw-medium">{{ type.label }}</div>
                                            <div class="form-text mt-0">{{ type.description }}</div>
                                        </div>
                                        <div class="col-12 col-md-auto d-flex notification-channel-switches mt-2 mt-md-0">
                                            {% for _channel, _label in {"mail": "E-Mail", "in_app": "Glocke"} %}
                                                {% set _id = "notification-" ~ type.type ~ "-" ~ _channel %}
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input"
                                                           type="checkbox"
                                                           role="switch"
                                                           id="{{ _id }}"
                                                           name="notifications[{{ type.type }}][{{ _channel }}]"
                                                           value="1"
                                                           {{ _setting[_channel]|default(false) ? "checked" : "" }}>
                                                    <label class="form-check-label d-md-none" for="{{ _id }}">
                                                        {{ _label }}
                                                    </label>
                                                    <label class="visually-hidden d-none d-md-inline" for="{{ _id }}">
                                                        {{ _label }}: {{ type.label }}
                                                    </label>
                                                </div>
                                            {% endfor %}
                                        </div>
                                    </div>
                                {% endfor %}
                            {% endfor %}
```

(Speichern-Knopf und `</form>` bleiben.) In `public/css/style.css`:

```css
/* Profil: Schalter je Kanal stehen unter den Spaltenköpfen "E-Mail" und "Glocke". */
.notification-channel-heads,
.notification-channel-switches {
    gap: 1.5rem;
}

.notification-channel-heads span,
.notification-channel-switches .form-check {
    min-width: 4rem;
    text-align: center;
}
```

- [ ] **Step 5: Run – erwartet PASS; twigcs**

Run: `ddev php vendor/bin/phpunit --filter "ProfileNotificationSettingsFeatureTest|Profile" 2>&1 | tail -20` und `ddev composer twigcs 2>&1 | tail -15`

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/ProfileController.php templates/profile/index.twig public/css/style.css tests/Feature/ProfileNotificationSettingsFeatureTest.php
git commit -m "feat(notifications): Mail und Glocke im Profil getrennt abbestellen"
```

---

### Task 8: `UserNotificationController`, Routen, Twig-Funktion, Gelesen beim Öffnen

**Files:**
- Create: `src/Controllers/UserNotificationController.php`
- Create: `src/Services/Notifications/NotificationBadgeViewService.php`
- Create: `templates/notifications/index.twig`
- Modify: `src/Routes.php` (Profil-Block ~Zeile 204), `src/Dependencies.php` (Twig-Fabrik ~Zeile 795; Autowire-Einträge)
- Modify: `src/Controllers/TaskController.php::detail`, `EventController::detail`, `ProjectController::showMembers`, `SponsorController::detail`
- Test: `tests/Feature/UserNotificationControllerFeatureTest.php`, `tests/Feature/NotificationMarkReadOnOpenFeatureTest.php`

**Interfaces:**
- Consumes: `InAppNotificationStore` (Task 3).
- Produces:
  - `UserNotificationController::__construct(Twig $view, InAppNotificationStore $store)`; Aktionen `index`, `badge`, `recent`, `open`, `readAll`.
  - JSON `badge`: `{"unread_count": int}`; JSON `recent`: `{"unread_count": int, "items": list<{id:int, title:string, body:?string, group:string, url:string, unread:bool, created_at:string (ISO 8601), relative_time:string}>}` – `url` ist `/notifications/{id}/open`, `group` ist die Gruppe aus `NotificationType` (`tasks`/`events`/`projects`/`sponsoring`).
  - `NotificationBadgeViewService::forCurrentUser(): ?int` (null ohne Anmeldung), Twig-Funktion `notification_badge()`.
  - Statische Hilfe für relative Zeit: `UserNotificationController::relativeTime(CarbonInterface $at, CarbonInterface $now): string` (öffentlich statisch, damit testbar und im Template über eine vorbereitete Liste nutzbar).

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserNotificationController;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\InAppNotificationStore;
use App\Util\NotificationType;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Endpunkte der Glocke - und vor allem ihre Grenze: Niemand sieht oder
 * ändert die Einträge einer anderen Person.
 */
final class UserNotificationControllerFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $anna;
    private User $bernd;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->anna = $this->createUser('Anna', 'Amsel');
        $this->bernd = $this->createUser('Bernd', 'Buchfink');
        $_SESSION = ['user_id' => (int) $this->anna->id];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testBadgeReturnsTheOwnUnreadCountUncached(): void
    {
        $this->entry($this->anna);
        $this->entry($this->anna, readAt: Carbon::now());
        $this->entry($this->bernd);

        $response = $this->controller()->badge($this->makeRequest('GET', '/notifications/badge'), $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(['unread_count' => 1], json_decode((string) $response->getBody(), true));
    }

    public function testRecentListsOwnEntriesWithOpenLinks(): void
    {
        $own = $this->entry($this->anna, title: 'Neuer Kommentar: Saal');
        $this->entry($this->bernd, title: 'fremd');

        $response = $this->controller()->recent($this->makeRequest('GET', '/notifications/recent'), $this->makeResponse());
        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame(1, $data['unread_count']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('Neuer Kommentar: Saal', $data['items'][0]['title']);
        $this->assertSame('/notifications/' . $own->id . '/open', $data['items'][0]['url']);
        $this->assertSame('tasks', $data['items'][0]['group']);
        $this->assertTrue($data['items'][0]['unread']);
    }

    public function testOpeningMarksReadAndRedirectsToTheTarget(): void
    {
        $entry = $this->entry($this->anna, link: '/tasks/7');

        $response = $this->controller()->open(
            $this->makeRequest('GET', '/notifications/' . $entry->id . '/open'),
            $this->makeResponse(),
            ['id' => (string) $entry->id]
        );

        $this->assertRedirect($response, '/tasks/7');
        $this->assertNotNull($entry->fresh()->read_at);
    }

    public function testOpeningAForeignEntryIsNotFoundAndChangesNothing(): void
    {
        $foreign = $this->entry($this->bernd);

        try {
            $this->controller()->open(
                $this->makeRequest('GET', '/notifications/' . $foreign->id . '/open'),
                $this->makeResponse(),
                ['id' => (string) $foreign->id]
            );
            $this->fail('Ein fremder Eintrag muss 404 ergeben.');
        } catch (HttpNotFoundException) {
            $this->assertNull($foreign->fresh()->read_at);
        }
    }

    public function testReadAllOnlyTouchesTheOwnEntries(): void
    {
        $own = $this->entry($this->anna);
        $foreign = $this->entry($this->bernd);

        $response = $this->controller()->readAll(
            $this->makeRequest('POST', '/notifications/read-all', ['_csrf' => 'x']),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/notifications');
        $this->assertNotNull($own->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function testReadAllAnswersJsonWhenAskedForIt(): void
    {
        $this->entry($this->anna);

        $response = $this->controller()->readAll(
            $this->makeRequest('POST', '/notifications/read-all', [], [], ['Accept' => 'application/json']),
            $this->makeResponse()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['unread_count' => 0], json_decode((string) $response->getBody(), true));
    }

    public function testTheRelativeTimeReadsNaturally(): void
    {
        $now = Carbon::parse('2026-10-05 12:00:00');

        $this->assertSame('gerade eben', UserNotificationController::relativeTime($now->copy()->subSeconds(30), $now));
        $this->assertSame('vor 5 Min.', UserNotificationController::relativeTime($now->copy()->subMinutes(5), $now));
        $this->assertSame('vor 3 Std.', UserNotificationController::relativeTime($now->copy()->subHours(3), $now));
        $this->assertSame('gestern', UserNotificationController::relativeTime($now->copy()->subDay(), $now));
        $this->assertSame('vor 4 Tagen', UserNotificationController::relativeTime($now->copy()->subDays(4), $now));
        $this->assertSame('20.08.2026', UserNotificationController::relativeTime($now->copy()->subDays(46), $now));
    }

    private function controller(): UserNotificationController
    {
        return new UserNotificationController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new InAppNotificationStore()
        );
    }

    private function entry(
        User $user,
        string $title = 'Eintrag',
        string $link = '/tasks/1',
        ?Carbon $readAt = null
    ): UserNotification {
        return UserNotification::create([
            'user_id' => $user->id,
            'notification_type' => NotificationType::TASK_COMMENT,
            'title' => $title,
            'link' => $link,
            'read_at' => $readAt,
            'created_at' => Carbon::now(),
        ]);
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'bellctl.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }
}
```

CSRF wird von `CsrfMiddleware` für alle POSTs erzwungen; der Nachweis, dass `/notifications/read-all` darunter fällt, erfolgt über einen Routen-Test: in `tests/Feature` nach einem bestehenden Test suchen, der prüft, dass POST-Routen ohne Token abgewiesen werden (`grep -rln "CsrfMiddleware" tests`), und `/notifications/read-all` dort aufnehmen bzw. einen Fall mit `CsrfMiddleware` direkt ergänzen (Aufbau aus dem gefundenen Test kopieren).

Zweite Testklasse `NotificationMarkReadOnOpenFeatureTest`: für jede der vier Detailseiten den Controller wie in den bestehenden Feature-Tests dieses Controllers aufrufen (`grep -rln "->detail(" tests/Feature` bzw. `showMembers(`) und prüfen: ein ungelesener Eintrag der angemeldeten Person zu `(entity_type, entity_id)` ist danach gelesen, ein Eintrag derselben Person zu einem anderen Objekt und ein Eintrag einer anderen Person zum selben Objekt bleiben ungelesen. Mindestens Aufgabe und Termin vollständig; Projekt und Sponsor mit je einem positiven Fall.

- [ ] **Step 2: Run – erwartet FAIL**

Run: `ddev php vendor/bin/phpunit --filter "UserNotificationControllerFeatureTest|NotificationMarkReadOnOpenFeatureTest" 2>&1 | tail -20`

- [ ] **Step 3: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserNotification;
use App\Services\Notifications\InAppNotificationStore;
use App\Util\NotificationType;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Die Glocke: Zähler, die letzten Einträge, die ganze Liste.
 *
 * Jede Aktion arbeitet ausschließlich mit der `user_id` der Sitzung. Ein
 * fremder Eintrag ist hier nicht „verboten“, sondern gar nicht vorhanden -
 * deshalb 404 statt 403, sonst ließe sich abtasten, welche IDs es gibt.
 */
class UserNotificationController
{
    private const RECENT_LIMIT = 10;

    public function __construct(
        private readonly Twig $view,
        private readonly InAppNotificationStore $store
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $onlyUnread = ($query['unread'] ?? '') === '1';
        $page = max(1, (int) ($query['page'] ?? 1));

        $result = $this->store->paginate($this->userId(), $page, $onlyUnread);

        return $this->view->render($response, 'notifications/index.twig', [
            'notifications' => array_map(fn (UserNotification $n): array => $this->present($n), $result['items']->all()),
            'page' => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
            'only_unread' => $onlyUnread,
            'unread_count' => $this->store->unreadCount($this->userId()),
        ]);
    }

    public function badge(Request $request, Response $response): Response
    {
        return $this->json($response, ['unread_count' => $this->store->unreadCount($this->userId())]);
    }

    public function recent(Request $request, Response $response): Response
    {
        $userId = $this->userId();

        return $this->json($response, [
            'unread_count' => $this->store->unreadCount($userId),
            'items' => array_map(
                fn (UserNotification $n): array => $this->present($n),
                $this->store->recent($userId, self::RECENT_LIMIT)->all()
            ),
        ]);
    }

    /**
     * Ein normaler Link (GET), damit Mittelklick und „in neuem Tab öffnen“
     * funktionieren. Er ändert nur den eigenen Lesestatus.
     *
     * @param array{id: string} $args
     */
    public function open(Request $request, Response $response, array $args): Response
    {
        $notification = $this->store->findForUser($this->userId(), (int) $args['id']);
        if ($notification === null) {
            throw new HttpNotFoundException($request);
        }

        $this->store->markRead($notification);

        return $response->withHeader('Location', (string) $notification->link)->withStatus(302);
    }

    public function readAll(Request $request, Response $response): Response
    {
        $this->store->markAllRead($this->userId());

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return $this->json($response, ['unread_count' => 0]);
        }

        $_SESSION['success'] = 'Alle Benachrichtigungen sind als gelesen markiert.';

        return $response->withHeader('Location', '/notifications')->withStatus(302);
    }

    public static function relativeTime(CarbonInterface $at, CarbonInterface $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());

        if ($seconds < 60) {
            return 'gerade eben';
        }
        if ($seconds < 3600) {
            return 'vor ' . intdiv($seconds, 60) . ' Min.';
        }
        if ($seconds < 86400) {
            return 'vor ' . intdiv($seconds, 3600) . ' Std.';
        }

        $days = (int) $at->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());
        if ($days <= 1) {
            return 'gestern';
        }
        if ($days < 7) {
            return 'vor ' . $days . ' Tagen';
        }

        return $at->format('d.m.Y');
    }

    /**
     * @return array{id: int, title: string, body: ?string, group: string, url: string, unread: bool,
     *     created_at: string, relative_time: string}
     */
    private function present(UserNotification $notification): array
    {
        $type = (string) $notification->notification_type;
        $createdAt = $notification->created_at ?? Carbon::now();

        return [
            'id' => (int) $notification->id,
            'title' => (string) $notification->title,
            'body' => $notification->body,
            'group' => NotificationType::exists($type) ? NotificationType::definition($type)['group'] : 'other',
            'url' => '/notifications/' . $notification->id . '/open',
            'unread' => $notification->read_at === null,
            'created_at' => $createdAt->toIso8601String(),
            'relative_time' => self::relativeTime($createdAt, Carbon::now()),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write((string) json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(200);
    }

    private function userId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }
}
```

- [ ] **Step 4: Routen** (in `src/Routes.php` nach den Profil-Routen, im selben `$group`)

```php
            // Glocke - jede angemeldete Person, ohne weiteres Recht.
            $group->get('/notifications', [UserNotificationController::class, 'index']);
            $group->get('/notifications/badge', [UserNotificationController::class, 'badge']);
            $group->get('/notifications/recent', [UserNotificationController::class, 'recent']);
            $group->get('/notifications/{id:[0-9]+}/open', [UserNotificationController::class, 'open']);
            $group->post('/notifications/read-all', [UserNotificationController::class, 'readAll']);
```

`use App\Controllers\UserNotificationController;` ergänzen. In `Dependencies.php`: `InAppNotificationStore::class => \DI\autowire()`, `UserNotificationController::class => \DI\autowire()`, `NotificationBadgeViewService::class => \DI\autowire()`; in der `NotificationService`-Fabrik `$c->get(InAppNotificationStore::class)` als fünftes Argument.

- [ ] **Step 5: Badge-Dienst und Twig-Funktion**

```php
<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Psr\Log\LoggerInterface;

/**
 * Zähler der Glocke für die Kopfzeile, erst beim Rendern ermittelt - aus
 * demselben Grund wie beim Mail-Badge (`MailBadgeViewService`): Twig kann
 * entstehen, bevor eine Anmeldung per Erinnerungs-Cookie wiederhergestellt ist.
 */
class NotificationBadgeViewService
{
    public function __construct(
        private readonly InAppNotificationStore $store,
        private readonly LoggerInterface $logger
    ) {
    }

    public function forCurrentUser(): ?int
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($userId <= 0) {
            return null;
        }

        try {
            return $this->store->unreadCount($userId);
        } catch (\Throwable $exception) {
            // Ein Problem mit der Glocke darf nie jede Seite mitreißen.
            $this->logger->error('Notification badge lookup failed.', [
                'event' => 'notification.badge.failed',
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
```

In `Dependencies.php` direkt nach `mail_badge`:

```php
            $notificationBadgeView = $c->get(NotificationBadgeViewService::class);
            $environment->addFunction(new TwigFunction(
                'notification_badge',
                static fn (): ?int => $notificationBadgeView->forCurrentUser()
            ));
```

Test ergänzen (in `UserNotificationControllerFeatureTest` oder eigener Klasse): `forCurrentUser()` ist `null` ohne Sitzung und zählt nur eigene ungelesene.

- [ ] **Step 6: Seite `templates/notifications/index.twig`**

```twig
{% extends "layout.twig" %}

{% block title %}Benachrichtigungen{% endblock %}

{% block content %}
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Benachrichtigungen</h1>
        <div class="d-flex flex-wrap gap-2">
            {% if only_unread %}
                <a href="/notifications" class="btn btn-outline-secondary btn-sm">Alle anzeigen</a>
            {% else %}
                <a href="/notifications?unread=1" class="btn btn-outline-secondary btn-sm">Nur ungelesene</a>
            {% endif %}
            {% if unread_count > 0 %}
                <form action="/notifications/read-all" method="post" class="m-0">
                    <input type="hidden" name="_csrf" value="{{ csrf_token }}">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2-all"></i> Alle als gelesen markieren
                    </button>
                </form>
            {% endif %}
        </div>
    </div>

    {% if notifications is empty %}
        <p class="text-muted">
            {{ only_unread ? "Keine ungelesenen Benachrichtigungen." : "Keine Benachrichtigungen." }}
        </p>
    {% else %}
        <div class="list-group shadow-sm">
            {% for item in notifications %}
                {% set _unread_class = item.unread ? " notification-item-unread" : "" %}
                <a href="{{ item.url }}" class="list-group-item list-group-item-action notification-item{{ _unread_class }}">
                    <div class="d-flex gap-3">
                        <i class="bi {{ include("partials/navigation/notification_icon.twig", {group: item.group}) }}"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="notification-item-title">{{ item.title }}</span>
                                <small class="text-muted text-nowrap" title="{{ item.created_at|date("d.m.Y H:i") }}">
                                    {{ item.relative_time }}
                                </small>
                            </div>
                            {% if item.body %}
                                <div class="small text-muted text-truncate">{{ item.body }}</div>
                            {% endif %}
                        </div>
                    </div>
                </a>
            {% endfor %}
        </div>

        {% if pages > 1 %}
            {% set _filter = only_unread ? "&unread=1" : "" %}
            <nav class="mt-3" aria-label="Seiten">
                <ul class="pagination pagination-sm">
                    {% for p in 1..pages %}
                        <li class="page-item{{ p == page ? " active" : "" }}">
                            <a class="page-link" href="/notifications?page={{ p }}{{ _filter }}">{{ p }}</a>
                        </li>
                    {% endfor %}
                </ul>
            </nav>
        {% endif %}
    {% endif %}
{% endblock %}
```

Block-Namen (`title`, `content`) gegen `templates/layout.twig` prüfen und angleichen. Die Symbol-Zuordnung als Mini-Partial `templates/partials/navigation/notification_icon.twig`, das nur die Icon-Klasse ausgibt:

```twig
{%- set _icons = {"tasks": "bi-check2-square", "events": "bi-calendar-event", "projects": "bi-folder2-open", "sponsoring": "bi-briefcase"} -%}
{{- _icons[group]|default("bi-bell") -}}
```

Dieselbe Zuordnung steht im JS (Task 9) – beide Stellen tragen einen Kommentar, der auf die jeweils andere verweist.

- [ ] **Step 7: Gelesen beim Öffnen** – in den vier Detail-Aktionen nach der erfolgreichen Rechteprüfung, vor dem Rendern:

```php
        (new InAppNotificationStore())->markEntityRead((int) ($_SESSION['user_id'] ?? 0), 'task', (int) $task->id);
```

(`'event'`/`$event->id`, `'project'`/`$projectId`, `'sponsor'`/`$sponsor->id` entsprechend.) Inline instanziiert wie `new EventAudienceService()` in `EventController`, damit die Konstruktoren und ihre DI-Fabriken unverändert bleiben. Fehler hier dürfen die Seite nicht verhindern: in `try { ... } catch (\Throwable $e) { $this->logger->warning('Marking notifications read failed.', ['event' => 'notification.mark_entity_read_failed', 'exception' => $e]); }` einschließen – oder, um die Wiederholung zu vermeiden, `InAppNotificationStore::markEntityReadQuietly(int $userId, string $entityType, int $entityId, LoggerInterface $logger): void` ergänzen, das genau dieses try/catch kapselt, und in allen vier Controllern nutzen (bevorzugt).

- [ ] **Step 8: Run – erwartet PASS**

Run: `ddev php vendor/bin/phpunit --filter "UserNotificationControllerFeatureTest|NotificationMarkReadOnOpenFeatureTest|NotificationWiringFeatureTest|Routes" 2>&1 | tail -20`

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/ src/Services/Notifications/ src/Routes.php src/Dependencies.php templates/notifications/ templates/partials/navigation/notification_icon.twig tests/Feature/
git commit -m "feat(notifications): Endpunkte und Seite der Glocke, gelesen beim Öffnen des Objekts"
```

---

### Task 9: Glocke in der Kopfzeile (Partial, JS, CSS)

**Files:**
- Create: `templates/partials/navigation/notification_bell.twig`
- Create: `public/js/notification-bell.js`
- Modify: `templates/partials/navigation/user_menu.twig` (Einbindung vor dem Mail-Badge), `templates/layout.twig` (Script nach `mail-badge.js`), `public/css/style.css`
- Test: `tests/Feature/NotificationBellRenderFeatureTest.php` (Rendern des Partials), `tests/js/notification-bell-format.test.mjs` nur falls Logik ausgelagert wird (siehe unten)

**Interfaces:**
- Consumes: Twig-Funktion `notification_badge()`, Endpunkte `/notifications/badge`, `/notifications/recent`, `/notifications/read-all` (Task 8).
- Produces: Markup mit `data-notification-bell`, `data-notification-bell-count`, `data-notification-bell-list`, `data-notification-bell-read-all`.

- [ ] **Step 1: Failing test – Partial rendert Zähler und versteckt bei 0**

Aufbau nach `tests/Feature/MailBadgeAlwaysVisibleFeatureTest.php` (dort nachsehen, wie Twig mit Funktion `mail_badge` gestubbt wird) – zwei Fälle:
- `notification_badge()` liefert `3` → Ausgabe enthält `data-notification-bell`, Pille mit `3`, ohne `d-none`.
- liefert `0` → Pille hat `d-none`.
- liefert `150` → `99+`.
- liefert `null` → keine Glocke.

- [ ] **Step 2: Run – erwartet FAIL**

Run: `ddev php vendor/bin/phpunit --filter NotificationBellRenderFeatureTest 2>&1 | tail -15`

- [ ] **Step 3: Partial**

```twig
{# Glocke samt Zähler-Pille und Dropdown.
   Die Pille steckt immer im Markup und wird bei 0 nur ausgeblendet - wie beim
   Mail-Badge, damit notification-bell.js sie nur umschreiben muss. Die Liste
   lädt erst beim Aufklappen, damit nicht jeder Seitenaufruf sie mitholt. #}
{% set _unread = notification_badge() %}
{% if _unread is not null %}
    {% set _hidden_class = _unread > 0 ? "" : " d-none" %}
    {% set _label = _unread > 99 ? "99+" : _unread %}
    <div class="dropdown me-3" data-notification-bell>
        <button type="button"
                class="btn btn-link text-white p-0 notification-bell-trigger"
                data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                aria-expanded="false"
                title="Benachrichtigungen">
            <i class="bi bi-bell-fill fs-5"></i>
            <span class="badge bg-danger rounded-pill notification-bell-count{{ _hidden_class }}"
                  data-notification-bell-count>{{ _label }}</span>
            <span class="visually-hidden">Benachrichtigungen</span>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow p-0 notification-bell-menu">
            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                <strong>Benachrichtigungen</strong>
                <form action="/notifications/read-all" method="post" class="m-0" data-notification-bell-read-all>
                    <input type="hidden" name="_csrf" value="{{ csrf_token }}">
                    <button type="submit" class="btn btn-link btn-sm p-0">Alle als gelesen markieren</button>
                </form>
            </div>
            <div class="notification-bell-list" data-notification-bell-list>
                <div class="px-3 py-3 text-muted small">Wird geladen …</div>
            </div>
            <a href="/notifications" class="d-block text-center px-3 py-2 border-top small">Alle anzeigen</a>
        </div>
    </div>
{% endif %}
```

In `user_menu.twig` ganz oben nach dem Makro-Import (vor `{% set _mail_badge = mail_badge() %}`): `{{ include("partials/navigation/notification_bell.twig") }}`. Da die Glocke damit vor dem Mail-Badge im selben Flex-Container steht, sitzt sie links davon.

- [ ] **Step 4: JS `public/js/notification-bell.js`**

```js
/**
 * Hält die Glocke in der Kopfzeile aktuell und füllt ihr Dropdown.
 *
 * Der Zähler wird beim Zurückwechseln in den Tab und alle zwei Minuten bei
 * sichtbarem Tab abgefragt - kein WebSocket, wie beim Mail-Badge. Die Liste
 * lädt erst beim Aufklappen. Texte werden ausschließlich über textContent
 * gesetzt: Titel und Zeile stammen aus Eingaben anderer Mitglieder.
 */
document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-notification-bell]');
    if (!root) {
        return;
    }

    var pill = root.querySelector('[data-notification-bell-count]');
    var list = root.querySelector('[data-notification-bell-list]');
    var readAllForm = root.querySelector('[data-notification-bell-read-all]');

    var MIN_INTERVAL_MS = 5000;
    var POLL_INTERVAL_MS = 120000;

    // Dieselbe Zuordnung wie in templates/partials/navigation/notification_icon.twig.
    var ICONS = {
        tasks: 'bi-check2-square',
        events: 'bi-calendar-event',
        projects: 'bi-folder2-open',
        sponsoring: 'bi-briefcase'
    };

    var lastRequestedAt = 0;
    var inFlight = false;

    function renderCount(count) {
        if (typeof count !== 'number') {
            return;
        }
        pill.textContent = count > 99 ? '99+' : String(count);
        pill.classList.toggle('d-none', count <= 0);
    }

    function getJson(url) {
        return fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.ok ? response.json() : null;
        });
    }

    function refreshCount() {
        var now = Date.now();
        if (inFlight || now - lastRequestedAt < MIN_INTERVAL_MS) {
            return;
        }
        inFlight = true;
        lastRequestedAt = now;

        getJson('/notifications/badge')
            .then(function (data) {
                if (data) {
                    renderCount(data.unread_count);
                }
            })
            .catch(function () {
                // Netzfehler oder abgelaufene Sitzung: Der zuletzt gezeigte Stand bleibt.
            })
            .finally(function () {
                inFlight = false;
            });
    }

    function message(text) {
        var div = document.createElement('div');
        div.className = 'px-3 py-3 text-muted small';
        div.textContent = text;
        return div;
    }

    function renderItem(item) {
        var link = document.createElement('a');
        link.href = item.url;
        link.className = 'dropdown-item notification-item' + (item.unread ? ' notification-item-unread' : '');

        var icon = document.createElement('i');
        icon.className = 'bi ' + (ICONS[item.group] || 'bi-bell') + ' notification-item-icon';

        var text = document.createElement('div');
        text.className = 'notification-item-text';

        var title = document.createElement('div');
        title.className = 'notification-item-title';
        title.textContent = item.title;
        text.appendChild(title);

        if (item.body) {
            var body = document.createElement('div');
            body.className = 'small text-muted notification-item-body';
            body.textContent = item.body;
            text.appendChild(body);
        }

        var time = document.createElement('div');
        time.className = 'small text-muted';
        time.textContent = item.relative_time;
        text.appendChild(time);

        link.appendChild(icon);
        link.appendChild(text);
        return link;
    }

    function loadList() {
        getJson('/notifications/recent')
            .then(function (data) {
                if (!data) {
                    list.replaceChildren(message('Benachrichtigungen konnten nicht geladen werden.'));
                    return;
                }
                renderCount(data.unread_count);
                if (!data.items || data.items.length === 0) {
                    list.replaceChildren(message('Keine Benachrichtigungen'));
                    return;
                }
                list.replaceChildren.apply(list, data.items.map(renderItem));
            })
            .catch(function () {
                list.replaceChildren(message('Benachrichtigungen konnten nicht geladen werden.'));
            });
    }

    root.addEventListener('show.bs.dropdown', loadList);

    if (readAllForm) {
        readAllForm.addEventListener('submit', function (event) {
            event.preventDefault();
            fetch(readAllForm.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: new FormData(readAllForm)
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (data) {
                        renderCount(data.unread_count);
                        list.querySelectorAll('.notification-item-unread').forEach(function (el) {
                            el.classList.remove('notification-item-unread');
                        });
                    }
                })
                .catch(function () {
                    // Ohne Antwort bleibt alles, wie es war; die Seite /notifications hilft weiter.
                });
        });
    }

    window.addEventListener('focus', refreshCount);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refreshCount();
        }
    });
    window.setInterval(function () {
        if (!document.hidden) {
            refreshCount();
        }
    }, POLL_INTERVAL_MS);
});
```

In `layout.twig` nach `mail-badge.js`: `<script src="{{ asset_path('/js/notification-bell.js') }}"></script>`.

Hinweis: Prüfen, dass `HtmlFormCsrfInjectorMiddleware` das versteckte `_csrf` nicht doppelt einsetzt (Formulare mit vorhandenem Feld) – im Mail-Badge-Formular steht es ebenfalls explizit, also passt das Muster.

- [ ] **Step 5: CSS** (in `public/css/style.css` nach den `.mail-badge-*`-Regeln, Werte an diese angleichen)

```css
/* Glocke in der Kopfzeile - Pille wie beim Mail-Badge. */
.notification-bell-trigger {
    position: relative;
}

.notification-bell-trigger .notification-bell-count {
    /* gleiche Position wie .mail-badge-trigger .mail-badge-count */
}

.notification-bell-menu {
    width: min(24rem, calc(100vw - 2rem));
}

.notification-bell-list {
    max-height: 60vh;
    overflow-y: auto;
}

.notification-item {
    display: flex;
    gap: 0.75rem;
    white-space: normal;
    padding-top: 0.6rem;
    padding-bottom: 0.6rem;
}

.notification-item-icon {
    flex-shrink: 0;
    margin-top: 0.15rem;
}

.notification-item-text {
    min-width: 0;
}

.notification-item-body {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.notification-item-unread {
    background-color: var(--bs-primary-bg-subtle);
}

.notification-item-unread .notification-item-title {
    font-weight: 600;
}
```

Die Regel `.notification-bell-trigger .notification-bell-count` mit den konkreten Eigenschaften aus `.mail-badge-trigger .mail-badge-count` (Zeile ~1035) füllen – nicht leer committen. Alternativ beide Selektoren an die bestehende Regel anhängen (`.mail-badge-trigger .mail-badge-count, .notification-bell-trigger .notification-bell-count { … }`) – bevorzugt, kein Duplikat.

- [ ] **Step 6: Run – PASS; twigcs**

Run: `ddev php vendor/bin/phpunit --filter "NotificationBellRenderFeatureTest|MailBadge" 2>&1 | tail -15` und `ddev composer twigcs 2>&1 | tail -15`

- [ ] **Step 7: Commit**

```bash
git add templates/partials/navigation/ templates/layout.twig public/js/notification-bell.js public/css/style.css tests/Feature/NotificationBellRenderFeatureTest.php
git commit -m "feat(notifications): Glocke mit Dropdown in der Kopfzeile"
```

---

### Task 10: Seed-Daten

**Files:**
- Modify: `src/Services/DevSeedService.php` (`seedNotificationSettings` ~Zeile 2144, Reset-Liste ~Zeile 363, Zählung ~Zeile 201, `run()` ~Zeile 257)
- Test: bestehender DevSeed-Test (`grep -rln "DevSeedService" tests`) um `user_notifications` erweitern

**Interfaces:**
- Consumes: `UserNotification`, `NotificationChannel`, `InAppMessage`-Regeln (Link mit `/`).

- [ ] **Step 1: Failing test** – im gefundenen DevSeed-Test: nach dem Seed-Lauf ist `user_notifications` > 0, der Admin hat mindestens einen ungelesenen Eintrag, es gibt gelesene und ungelesene, und `user_notification_settings` enthält mindestens eine Zeile mit `channel = 'in_app'`. (Admin-Ermittlung wie im Test vorhanden.)

- [ ] **Step 2: Run – FAIL**

Run: `ddev php vendor/bin/phpunit --filter DevSeed 2>&1 | tail -15`

- [ ] **Step 3: Implementierung**

- `seedNotificationSettings`: `updateOrCreate` mit `channel` im Schlüssel; zusätzlich für einige Personen `channel = in_app`, `enabled = false`.
- `'user_notifications' => 0` in die Zählung, `'user_notifications'` in die Reset-Liste **vor** `users` (Fremdschlüssel).
- Neue Methode, in `run()` nach Aufgaben, Terminen und Projekten aufgerufen:

```php
    /**
     * Glocken-Einträge für die Testkonten: gemischt gelesen und ungelesen, über
     * mehrere Anlässe, damit Dropdown, Seite und Zähler etwas zu zeigen haben.
     * Die erste Person (Admin) bekommt sicher ungelesene Einträge.
     *
     * @param list<User> $activeUsers
     */
    private function seedUserNotifications(array $activeUsers): void
    {
        $task = Task::query()->orderBy('id')->first();
        $event = Event::query()->orderBy('starts_at')->first();
        $project = Project::query()->orderBy('id')->first();

        $templates = [];
        if ($task !== null) {
            $templates[] = [NotificationType::TASK_COMMENT, 'Neuer Kommentar: ' . $task->name,
                'Ich habe beim Musikhaus angefragt, Antwort kommt morgen.', '/tasks/' . $task->id, 'task', $task->id];
            $templates[] = [NotificationType::TASK_ASSIGNED, 'Neue Aufgabe: ' . $task->name,
                'Du wurdest eingetragen', '/tasks/' . $task->id, 'task', $task->id];
        }
        if ($event !== null) {
            $templates[] = [NotificationType::EVENT_CHANGED, 'Termin geändert: ' . $event->title,
                'Ort: Pfarrsaal St. Nikolaus', '/events/' . $event->id, 'event', $event->id];
        }
        if ($project !== null) {
            $templates[] = [NotificationType::PROJECT_MEMBER_ADDED, 'Neues Projekt: ' . $project->name,
                'Du bist jetzt dabei', '/projects/' . $project->id . '/members', 'project', $project->id];
        }
        if ($templates === []) {
            return;
        }

        $actor = $activeUsers[1] ?? null;
        foreach (array_slice($activeUsers, 0, 8) as $index => $user) {
            foreach ($templates as $offset => [$type, $title, $body, $link, $entityType, $entityId]) {
                $createdAt = Carbon::now()->subHours(($offset + 1) * ($index + 2));
                // Die erste Person behält alles ungelesen, die übrigen haben je
                // einen Teil schon gelesen.
                $isRead = $index > 0 && $offset % 2 === 1;

                UserNotification::create([
                    'user_id' => $user->id,
                    'notification_type' => $type,
                    'actor_user_id' => $actor !== null && (int) $actor->id !== (int) $user->id ? $actor->id : null,
                    'title' => $title,
                    'body' => $body,
                    'link' => $link,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'read_at' => $isRead ? $createdAt->copy()->addHour() : null,
                    'created_at' => $createdAt,
                ]);
                $this->counts['user_notifications']++;
            }
        }
    }
```

(Namen `$this->counts`, die Art, wie `$activeUsers` übergeben wird, und die Reihenfolge Admin-zuerst an die vorhandene Klasse angleichen; Imports für `UserNotification`, `NotificationChannel`, `Carbon` ergänzen.)

- [ ] **Step 4: Run – PASS, echter Seed-Lauf**

Run: `ddev php vendor/bin/phpunit --filter DevSeed 2>&1 | tail -15`, dann den echten Dev-Seed so ausführen, wie es im Projekt üblich ist (`grep -rn "DevSeed" bin src/Routes.php` – CLI-Einstieg oder Route) und im Bericht prüfen, dass `user_notifications` > 0.

- [ ] **Step 5: Commit**

```bash
git add src/Services/DevSeedService.php tests/
git commit -m "feat(notifications): Seed-Daten für Glocke und Kanal-Einstellungen"
```

---

### Task 11: Gesamtprüfung, Schärfeprobe, Review

- [ ] **Step 1: Stil**

Run: `ddev composer phpcbf 2>&1 | tail -5; ddev composer phpcs 2>&1 | tail -15; ddev composer twigcs 2>&1 | tail -15` – alles grün.

- [ ] **Step 2: Volle Suite**

Run: `ddev composer test:parallel 2>&1 | tail -25` – grün.

- [ ] **Step 3: Schärfeprobe** (je Sabotage: ändern, gezielten Test laufen lassen, rot belegen, zurücksetzen mit `git checkout -- <datei>`)

1. `InAppNotificationStore::forUser()` ohne `where('user_id', ...)` → `UserNotificationControllerFeatureTest::testOpeningAForeignEntryIsNotFoundAndChangesNothing` und `testReadAllOnlyTouchesTheOwnEntries` werden rot.
2. `NotificationService::optedOutUserIds()` ohne `where('channel', ...)` → `testOptingOutOfTheMailKeepsTheBell` wird rot.
3. `InAppMessage` Link-Regex auf `#^/#` → `testALinkOutOfTheApplicationIsRejected` (`//evil`) wird rot.
4. `markEntityRead` ohne `entity_id`-Bedingung → `testMarkEntityReadOnlyTouchesThatObjectAndThatPerson` wird rot.

Ergebnis jeder Probe berichten.

- [ ] **Step 4: Zeilenenden** – alle neuen/geänderten Dateien auf LF prüfen (`git diff --name-only main | xargs file` bzw. die PowerShell-Normalisierung aus `instructions/line-endings.md`).

- [ ] **Step 5: Branch-Review** durch einen frischen Reviewer (Skill `superpowers:requesting-code-review`), Befunde einarbeiten, betroffene Tests erneut laufen lassen.

- [ ] **Step 6: Abschluss über den Skill `git-commit`** (Squash auf einen Commit mit Spec und Plan, Rebase, Fast-Forward auf `main`; Push nur nach ausdrücklicher Frage).
