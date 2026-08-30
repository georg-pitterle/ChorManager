<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Die Anlässe, zu denen der ChorManager von sich aus eine Mail schreibt.
 *
 * Alles über einen Anlass steht hier an einer Stelle: sein Schlüssel, wie er im
 * Profil und in der Verwaltung heißt, ob er ohne Zutun aktiv ist und an welchem
 * Modul er hängt. Ein neuer Anlass ist damit ein Eintrag in dieser Liste plus
 * eine Mail-Vorlage - nicht eine Migration, eine Spalte im Profilformular und
 * drei Stellen, die auseinanderlaufen können.
 */
final class NotificationType
{
    public const TASK_ASSIGNED = 'task_assigned';
    public const TASK_COMMENT = 'task_comment';
    public const TASK_DUE_SOON = 'task_due_soon';
    public const EVENT_CREATED = 'event_created';
    public const EVENT_CHANGED = 'event_changed';
    public const EVENT_CANCELLED = 'event_cancelled';
    public const EVENT_NOTE = 'event_note';
    public const PROJECT_MEMBER_ADDED = 'project_member_added';
    public const SPONSORING_FOLLOW_UP_DUE = 'sponsoring_follow_up_due';

    /**
     * Gruppen für die Anzeige - dieselbe Ordnung im Profil und in der
     * Verwaltung, damit man einen Anlass an derselben Stelle sucht.
     *
     * Die Beschriftungen sind bewusst neutral gehalten ("Aufgabe zugewiesen",
     * nicht "Aufgabe wurde mir zugewiesen"): Dieselbe Liste steht im Profil, wo
     * man über sich entscheidet, und in der Verwaltung, wo man über alle
     * entscheidet. Die Beschreibungen dürfen die Empfängerin ansprechen - auch
     * in der Verwaltung erklären sie, was beim Mitglied ankommt.
     */
    public const GROUPS = [
        'tasks' => 'Aufgaben',
        'events' => 'Termine',
        'projects' => 'Projekte',
        'sponsoring' => 'Sponsoring',
    ];

    /**
     * Alle Anlässe in Anzeigereihenfolge.
     *
     * `module` nennt das Flag aus `settings.modules`, ohne das der Anlass gar
     * nicht auftreten kann; `null` heißt: hängt an keinem Modul.
     *
     * `default` ist die Vorgabe für Mitglieder, die nichts eingestellt haben.
     * Alle stehen auf `true`: Eine Benachrichtigung, die man erst suchen und
     * einschalten muss, erreicht die wenigsten - und abschalten kann sie jeder
     * im Profil mit einem Klick.
     *
     * @var array<string, array{group: string, label: string, description: string, module: ?string, default: bool}>
     */
    private const DEFINITIONS = [
        self::TASK_ASSIGNED => [
            'group' => 'tasks',
            'label' => 'Aufgabe zugewiesen',
            'description' => 'Sobald dich jemand für eine Aufgabe einträgt.',
            'module' => 'tasks',
            'default' => true,
        ],
        self::TASK_COMMENT => [
            'group' => 'tasks',
            'label' => 'Kommentar zu einer Aufgabe',
            'description' => 'Wenn jemand eine Aufgabe kommentiert, die dir gehört oder die du angelegt hast.',
            'module' => 'tasks',
            'default' => true,
        ],
        self::TASK_DUE_SOON => [
            'group' => 'tasks',
            'label' => 'Aufgabe wird bald fällig',
            'description' => 'Kurz vor dem Fälligkeitsdatum einer offenen Aufgabe, die dir zugewiesen ist.',
            'module' => 'tasks',
            'default' => true,
        ],
        self::EVENT_CREATED => [
            'group' => 'events',
            'label' => 'Neuer Termin',
            'description' => 'Wenn ein Termin angelegt wird, der dich betrifft. Eine Serie kommt als eine Mail.',
            'module' => null,
            'default' => true,
        ],
        self::EVENT_CHANGED => [
            'group' => 'events',
            'label' => 'Termin verschoben oder verlegt',
            'description' => 'Nur bei geänderter Zeit oder geändertem Ort - nicht bei jeder kleinen Korrektur.',
            'module' => null,
            'default' => true,
        ],
        self::EVENT_CANCELLED => [
            'group' => 'events',
            'label' => 'Termin abgesagt',
            'description' => 'Wenn ein Termin oder eine ganze Serie gelöscht wird.',
            'module' => null,
            'default' => true,
        ],
        self::EVENT_NOTE => [
            'group' => 'events',
            'label' => 'Neue Bemerkung zu einem Termin',
            'description' => 'Wenn jemand eine öffentliche Bemerkung zu einem deiner Termine schreibt.',
            'module' => null,
            'default' => true,
        ],
        self::PROJECT_MEMBER_ADDED => [
            'group' => 'projects',
            'label' => 'Zu einem Projekt hinzugefügt',
            'description' => 'Sobald dich jemand einem Projekt zuordnet.',
            'module' => null,
            'default' => true,
        ],
        self::SPONSORING_FOLLOW_UP_DUE => [
            'group' => 'sponsoring',
            'label' => 'Wiedervorlage wird fällig',
            'description' => 'Kurz vor dem Wiedervorlage-Datum eines Kontakts, den du protokolliert hast.',
            'module' => 'sponsoring',
            'default' => true,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::DEFINITIONS);
    }

    /**
     * @return array{group: string, label: string, description: string, module: ?string, default: bool}
     */
    public static function definition(string $type): array
    {
        if (!self::exists($type)) {
            throw new \InvalidArgumentException('Unbekannter Benachrichtigungs-Anlass: ' . $type);
        }

        return self::DEFINITIONS[$type];
    }

    public static function defaultEnabled(string $type): bool
    {
        return self::definition($type)['default'];
    }

    public static function module(string $type): ?string
    {
        return self::definition($type)['module'];
    }

    public static function label(string $type): string
    {
        return self::definition($type)['label'];
    }

    /**
     * Der Schlüssel, unter dem die Verwaltung den Anlass installationsweit
     * abschaltet.
     */
    public static function settingKey(string $type): string
    {
        return 'notification_' . $type . '_enabled';
    }

    /**
     * Die Anlässe nach Gruppen, für die Anzeige in Profil und Verwaltung.
     *
     * @param array<string, bool> $activeModules Flags aus `settings.modules`
     * @return array<string, list<array{type: string, label: string, description: string}>>
     */
    public static function grouped(array $activeModules): array
    {
        $grouped = [];

        foreach (self::DEFINITIONS as $type => $definition) {
            $module = $definition['module'];
            if ($module !== null && !($activeModules[$module] ?? false)) {
                continue;
            }

            $grouped[$definition['group']][] = [
                'type' => $type,
                'label' => $definition['label'],
                'description' => $definition['description'],
            ];
        }

        return $grouped;
    }
}
