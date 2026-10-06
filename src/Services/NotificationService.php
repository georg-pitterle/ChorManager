<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\UserNotificationSetting;
use App\Services\Notifications\InAppMessage;
use App\Services\Notifications\InAppNotificationStore;
use App\Util\MailBranding;
use App\Util\NotificationChannel;
use App\Util\NotificationType;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Der einzige Weg, auf dem eine Benachrichtigung nach draußen geht.
 *
 * Drei Instanzen dürfen widersprechen, und alle drei werden hier geprüft statt
 * an jedem Auslöser erneut: das Modul (ist die Funktion überhaupt in Betrieb),
 * die Verwaltung (`AppSetting`, installationsweit) und die Person selbst
 * (`user_notification_settings`). Stünde diese Kette in den Controllern, fehlte
 * sie über kurz oder lang an einer Stelle - und genau dort schriebe der
 * ChorManager jemandem, der das abbestellt hat.
 *
 * Jeder Anlass geht über zwei Kanäle: die Mail und die Glocke in der Kopfzeile.
 * Modul und Verwaltung gelten für beide gemeinsam, die Person entscheidet je
 * Kanal. Eine gültige E-Mail-Adresse braucht nur die Mail - wer keine hat,
 * bekommt trotzdem den Eintrag in der Glocke.
 */
class NotificationService
{
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

    /**
     * Benachrichtigt alle Empfänger, die es wollen - per Mail und in der Glocke,
     * jeder Kanal für sich geprüft. Gibt zurück, wie viele **Mails** eingereiht
     * wurden.
     *
     * Der Glockentext ist Pflicht, damit kein Auslöser den zweiten Kanal
     * vergessen kann.
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

    /**
     * @param array<int, User> $candidates
     * @param array<string, mixed> $context
     */
    private function enqueueMails(
        string $type,
        array $candidates,
        string $subject,
        string $template,
        array $context
    ): int {
        $eligible = $this->withoutOptedOut(
            $type,
            NotificationChannel::MAIL,
            array_filter($candidates, fn (User $user): bool => $this->hasUsableEmail($user))
        );

        if ($eligible === []) {
            return 0;
        }

        $branding = MailBranding::resolve();
        $enqueued = 0;

        foreach ($eligible as $user) {
            try {
                $bodyHtml = $this->view->fetch($template, array_merge($branding, $context, [
                    'user' => $user,
                    'notification_label' => NotificationType::label($type),
                ]));

                $this->mailQueueService->enqueueNotificationMail(
                    (string) $user->email,
                    $subject,
                    $bodyHtml,
                    $type,
                    (int) $user->id,
                    $this->entityReference($context)
                );

                $enqueued++;
            } catch (\Throwable $e) {
                // Ein Empfänger, an dem es scheitert, darf die übrigen nicht
                // mitreißen - sonst entscheidet die Reihenfolge der Liste
                // darüber, wer benachrichtigt wird.
                $this->logger->error('Enqueueing notification failed.', [
                    'event' => 'notification.enqueue_failed',
                    'notification_type' => $type,
                    'user_id' => (int) $user->id,
                    'exception' => $e,
                ]);
            }
        }

        return $enqueued;
    }

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

    /**
     * Ob der Anlass überhaupt auftreten darf: Modul in Betrieb und von der
     * Verwaltung nicht abgeschaltet.
     */
    public function isAvailable(string $type): bool
    {
        if (!NotificationType::exists($type)) {
            return false;
        }

        $module = NotificationType::module($type);
        if ($module !== null && !($this->modules[$module] ?? false)) {
            return false;
        }

        return $this->isEnabledGlobally($type);
    }

    private function isEnabledGlobally(string $type): bool
    {
        $value = AppSetting::query()
            ->where('setting_key', NotificationType::settingKey($type))
            ->value('setting_value');

        // Kein Eintrag heißt "wie vorgesehen" - die Verwaltung muss nicht erst
        // jeden Anlass bestätigen, damit er funktioniert.
        if ($value === null || $value === '') {
            return NotificationType::defaultEnabled($type);
        }

        return (string) $value === '1';
    }

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

            if ($userId <= 0 || ($actorUserId !== null && $userId === $actorUserId)) {
                continue;
            }

            if (!$user->is_active) {
                continue;
            }

            // Dieselbe Person kann über zwei Wege in der Liste stehen - etwa als
            // Zugewiesene *und* als Erstellerin einer Aufgabe.
            $candidates[$userId] = $user;
        }

        return $candidates;
    }

    private function hasUsableEmail(User $user): bool
    {
        $email = trim((string) $user->email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param array<int, User> $users nach `user_id`
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

    /**
     * Wer diesen Anlass auf diesem Kanal abbestellt hat. Eine Abfrage für alle
     * Empfänger statt einer je Person - bei einem Chortermin sind das schnell
     * hundert.
     *
     * @param list<int> $userIds
     * @return list<int>
     */
    private function optedOutUserIds(string $type, string $channel, array $userIds): array
    {
        return array_map('intval', UserNotificationSetting::query()
            ->where('notification_type', $type)
            ->where('channel', $channel)
            ->whereIn('user_id', $userIds)
            ->where('enabled', false)
            ->pluck('user_id')
            ->all());
    }

    /**
     * Bezug auf das auslösende Objekt für die Nutzlast der Warteschlange -
     * damit sich eine Mail später einem Termin oder einer Aufgabe zuordnen
     * lässt, ohne den Betreff zu deuten.
     *
     * @param array<string, mixed> $context
     * @return array{entity_type: string, entity_id: int}|null
     */
    private function entityReference(array $context): ?array
    {
        $entityKeys = [
            'task' => 'task',
            'event' => 'event',
            'project' => 'project',
            'contact' => 'sponsoring_contact',
        ];

        foreach ($entityKeys as $key => $entityType) {
            $entity = $context[$key] ?? null;
            if (is_object($entity) && isset($entity->id)) {
                return ['entity_type' => $entityType, 'entity_id' => (int) $entity->id];
            }
        }

        return null;
    }

    /**
     * Die anzeigbaren Anlässe nach Gruppen.
     *
     * Welches Modul läuft, weiß dieser Dienst ohnehin - die Aufrufer sollen die
     * Modul-Flags nicht ein zweites Mal beschaffen und dabei zu einem anderen
     * Ergebnis kommen als der Versand.
     *
     * @return array<string, list<array{type: string, label: string, description: string}>>
     */
    public function availableGrouped(): array
    {
        $groups = [];

        foreach (NotificationType::grouped($this->modules) as $group => $types) {
            $available = array_values(array_filter(
                $types,
                fn (array $type): bool => $this->isAvailable($type['type'])
            ));

            if ($available !== []) {
                $groups[$group] = $available;
            }
        }

        return $groups;
    }

    /**
     * Die Anlässe, die das jeweilige Modul zulässt - ohne Rücksicht darauf, ob
     * die Verwaltung sie abgeschaltet hat.
     *
     * Genau das braucht die Verwaltungsseite: Sie muss auch die abgeschalteten
     * zeigen, sonst ließe sich ein einmal abgeschalteter nie wieder einschalten.
     *
     * @return array<string, list<array{type: string, label: string, description: string}>>
     */
    public function moduleAvailableGrouped(): array
    {
        return NotificationType::grouped($this->modules);
    }

    /**
     * Die Schlüssel aller anzeigbaren Anlässe - für Formulare, die eine
     * Eingabe gegen das Erlaubte prüfen.
     *
     * @return list<string>
     */
    public function availableTypes(): array
    {
        $types = [];
        foreach ($this->availableGrouped() as $group) {
            foreach ($group as $entry) {
                $types[] = $entry['type'];
            }
        }

        return $types;
    }

    /**
     * Ob eine bestimmte Person diesen Anlass auf diesem Kanal bekommen möchte.
     * Für das Profilformular und für Einzelfallprüfungen.
     */
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
     * Entscheidung mit ihr, verschwindet die Zeile wieder. So bleibt die
     * Tabelle klein und eine später geänderte Vorgabe greift für alle, die
     * nichts anderes wollten. Ein Kanal, der in `$decisions` fehlt, bleibt
     * unverändert.
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

    /**
     * Mail-Empfänger als Sammlung, gefiltert wie beim Versand - für Auslöser,
     * die vorher wissen wollen, ob überhaupt jemand übrig bleibt.
     *
     * @param iterable<User> $recipients
     * @return Collection<int, User>
     */
    public function eligibleRecipients(string $type, iterable $recipients, ?int $actorUserId = null): Collection
    {
        if (!$this->isAvailable($type)) {
            return new Collection();
        }

        $withEmail = array_filter(
            $this->candidates($recipients, $actorUserId),
            fn (User $user): bool => $this->hasUsableEmail($user)
        );

        return new Collection($this->withoutOptedOut($type, NotificationChannel::MAIL, $withEmail));
    }
}
