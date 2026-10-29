<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use App\Models\Concerns\HasEntityComments;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasEntityAttachments;
    use HasEntityComments;

    /** Der Wert, unter dem Notizen, Anhänge und Verlauf an dieser Aufgabe hängen. */
    public const ENTITY_TYPE = 'task';

    /**
     * Zustand einer Aufgabe. Die Werte spiegeln das ENUM aus Migration
     * 20261022130000.
     *
     * Bis dahin hielt die Spalte deutsche Werte ('Offen', 'In Bearbeitung',
     * 'Abgeschlossen', 'Blockiert') - entgegen `instructions/naming.md`, nach
     * dem ein im Code adressierter Datenbankwert ein englischer Bezeichner ist
     * und das Deutsche ins Label gehört. Zusätzlich standen sie als
     * Zeichenketten verstreut im TaskController, im NotificationReminderService,
     * im CalendarFeedService, im Seed, in zwei Vorlagen und in
     * `public/js/kanban-sortable-init.js`, wo ein Leerzeichen im Wert
     * ('In Bearbeitung') zugleich als `data-drop-zone` der Kanban-Spalte
     * diente.
     */
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Reihenfolge wie die Spalten des Kanban-Boards - sie gilt auch für das
     * ENUM aus Migration 20261022130000 und für das Auswahlfeld. Gleiche
     * Begründung wie bei `AudienceFilterCondition::CATEGORIES`: Eine zweite
     * Reihenfolge daneben läuft irgendwann auseinander, und vorher stand das
     * Board in einer anderen Ordnung als das ENUM.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_BLOCKED,
        self::STATUS_DONE,
    ];

    /**
     * Dringlichkeit einer Aufgabe. Die Werte spiegeln das ENUM aus Migration
     * 20261022130000; vorher 'Niedrig', 'Mittel', 'Hoch'.
     */
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';

    /** @var list<string> */
    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
    ];

    /**
     * Beschriftung je Zustand für die Oberfläche.
     *
     * Steht hier und nicht in den Vorlagen - gleiche Begründung wie bei
     * `MailQueue::MAIL_TYPE_LABELS`: Die Zuordnung stand in
     * `projects/tasks.twig` als vierfach wiederholte `if/elseif`-Kette und in
     * `projects/task_detail.twig` als eigene Zuordnung ein zweites Mal da, und
     * die beiden waren sich nicht einig - die Kanban-Spalte hieß
     * "In Bearbeitung", das Auswahlfeld daneben "In Arbeit", und derselbe
     * Zustand stand damit je nach Ansicht anders da. Maßgeblich ist jetzt die
     * Schreibweise der Kanban-Spalten, weil das die Ansicht ist, in der
     * Aufgaben bewegt werden.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Offen',
        self::STATUS_IN_PROGRESS => 'In Bearbeitung',
        self::STATUS_BLOCKED => 'Blockiert',
        self::STATUS_DONE => 'Abgeschlossen',
    ];

    /** @var array<string, string> */
    public const PRIORITY_LABELS = [
        self::PRIORITY_LOW => 'Niedrig',
        self::PRIORITY_MEDIUM => 'Mittel',
        self::PRIORITY_HIGH => 'Hoch',
    ];

    /**
     * Abzeichen-Klasse je Zustand bzw. Dringlichkeit. Zieht die Zuordnung aus
     * denselben vier `if/elseif`-Ketten in `projects/tasks.twig` heraus, die
     * schon die Labels doppelt hielten.
     *
     * @var array<string, string>
     */
    public const STATUS_BADGE_CLASSES = [
        self::STATUS_OPEN => 'text-bg-secondary',
        self::STATUS_IN_PROGRESS => 'text-bg-info',
        self::STATUS_BLOCKED => 'text-bg-danger',
        self::STATUS_DONE => 'text-bg-success',
    ];

    /** @var array<string, string> */
    public const PRIORITY_BADGE_CLASSES = [
        self::PRIORITY_LOW => 'text-bg-secondary',
        self::PRIORITY_MEDIUM => 'text-bg-warning',
        self::PRIORITY_HIGH => 'text-bg-danger',
    ];

    /**
     * Sinnbild je Zustand, aus derselben Zuordnung wie Label und Abzeichen.
     *
     * Die Kette in `projects/tasks.twig` kannte `blockiert` nicht: Ihr
     * `{% else %}` fing sowohl "offen" als auch "blockiert" ab, und eine
     * blockierte Aufgabe stand in der Listenansicht als "Offen" da, während die
     * Kanban-Ansicht daneben ihre eigene Spalte dafür hatte. Über eine
     * Zuordnung statt einer Kette kann ein Zustand nicht mehr durchfallen.
     *
     * @var array<string, string>
     */
    public const STATUS_ICONS = [
        self::STATUS_OPEN => 'bi-circle',
        self::STATUS_IN_PROGRESS => 'bi-arrow-repeat',
        self::STATUS_BLOCKED => 'bi-x-circle',
        self::STATUS_DONE => 'bi-check-circle',
    ];

    /**
     * Schriftfarbe des Sinnbilds in der Spaltenüberschrift des Kanban-Boards.
     *
     * Eigene Zuordnung und nicht aus STATUS_BADGE_CLASSES abgeleitet: Dass
     * `text-bg-info` und `text-info` sich nur um drei Zeichen unterscheiden, ist
     * eine Eigenheit von Bootstrap und kein Zusammenhang, auf den sich eine
     * Vorlage stützen sollte.
     *
     * @var array<string, string>
     */
    public const STATUS_ICON_COLORS = [
        self::STATUS_OPEN => 'text-secondary',
        self::STATUS_IN_PROGRESS => 'text-info',
        self::STATUS_BLOCKED => 'text-danger',
        self::STATUS_DONE => 'text-success',
    ];

    /**
     * Hinweis in einer leeren Kanban-Spalte.
     *
     * Stand bisher zweimal da: in `projects/tasks.twig` als Text im Platzhalter
     * und in `public/js/kanban-sortable-init.js` als eigene, nach den deutschen
     * Statuswerten verschlüsselte Zuordnung - das JavaScript legt den
     * Platzhalter nach einem Zug neu an. Die Vorlage gibt den Text jetzt als
     * `data-empty-text` an die Spalte mit; das JavaScript liest ihn dort ab und
     * kennt die Zustände nicht mehr.
     *
     * @var array<string, string>
     */
    public const STATUS_EMPTY_TEXTS = [
        self::STATUS_OPEN => 'Keine offenen Aufgaben',
        self::STATUS_IN_PROGRESS => 'Keine Aufgaben in Bearbeitung',
        self::STATUS_BLOCKED => 'Keine blockierten Aufgaben',
        self::STATUS_DONE => 'Keine erledigten Aufgaben',
    ];

    protected $table = 'tasks';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'start_date',
        'end_date',
        'status',
        'priority',
        'created_by',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Zugewiesene Personen. Eine Aufgabe kann mehreren gehören - bis
     * 20260830120000 hielt `tasks.assigned_to` genau eine, und wer zu zweit an
     * etwas arbeitete, legte die Aufgabe doppelt an.
     */
    public function assignees()
    {
        return $this->belongsToMany(User::class, 'task_assignees', 'task_id', 'user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Der Verlauf, der neueste Eintrag zuerst. `id` bricht den Gleichstand -
     * gleiche Begründung wie bei `HasEntityComments::comments()`:
     * `activities.created_at` ist ein `timestamp` und steht auf Sekunden genau.
     *
     * Hier wog der fehlende zweite Schlüssel schwerer als bei den Notizen:
     * `projects/task_detail.twig` dreht die Beziehung mit `|reverse` wieder um.
     * Zwei Einträge derselben Sekunde kamen aus der Datenbank in
     * Einfügereihenfolge zurück - also schon entgegen der zugesicherten -, und
     * `|reverse` stellte sie dann endgültig verkehrt in den Verlauf: "Status
     * geändert" vor "Aufgabe erstellt".
     */
    public function activities()
    {
        return $this->hasMany(Activity::class, 'entity_id')
            ->where('entity_type', self::ENTITY_TYPE)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
    }
}
