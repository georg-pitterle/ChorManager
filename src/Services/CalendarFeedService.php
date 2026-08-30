<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Event;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Util\Timezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Baut die abonnierbaren Kalender.
 *
 * Der Aufbau stand vorher im EventController. Seit Aufgaben mit im Kalender
 * landen können, hätte der Controller etwas über Aufgaben wissen müssen - und
 * der zweite Feed hätte den halben Controller ein zweites Mal gebraucht.
 */
class CalendarFeedService
{
    /**
     * Aufgaben, die als erledigt gelten - die gehören in keinen Kalender mehr.
     */
    private const COMPLETED_STATUS = 'Abgeschlossen';

    /**
     * VTODO kennt nur 1 (hoch) bis 9 (niedrig); dazwischen liegt die Skala
     * dieser Anwendung mit drei Stufen.
     */
    private const TODO_PRIORITIES = [
        'Hoch' => 1,
        'Mittel' => 5,
        'Niedrig' => 9,
    ];

    private NameFormatterService $nameFormatter;
    private EventAudienceService $audienceService;

    public function __construct(
        NameFormatterService $nameFormatter,
        ?EventAudienceService $audienceService = null
    ) {
        $this->nameFormatter = $nameFormatter;
        $this->audienceService = $audienceService ?? new EventAudienceService();
    }

    /**
     * Der bestehende Termin-Feed. Bei der Einstellung `combined` hängen die
     * Aufgaben mit drin - so bleibt es bei einem Abo, das man einmal einrichtet.
     */
    public function buildEventCalendar(User $user, string $baseUrl): string
    {
        $timezone = Timezone::resolveAppTimezone();
        $includesTasks = (string) $user->calendar_task_feed === User::CALENDAR_TASK_FEED_COMBINED;

        $lines = [];
        foreach ($this->visibleEvents((int) $user->id) as $event) {
            $lines = array_merge($lines, $this->buildIcsEventLines($event, $baseUrl, $timezone));
        }

        if ($includesTasks) {
            foreach ($this->openTasksFor($user) as $task) {
                $lines = array_merge($lines, $this->buildIcsTaskLines($task, $user, $baseUrl));
            }
        }

        return $this->wrapCalendar($lines, $includesTasks ? 'Termine und Aufgaben' : 'Termine', $timezone);
    }

    /**
     * Der eigene Aufgaben-Feed, nur bei der Einstellung `separate` gefüllt.
     *
     * Bei jeder anderen Einstellung kommt ein leerer Kalender zurück statt eines
     * Fehlers: Wer von `separate` auf `combined` wechselt, hat den Link meist
     * noch im Kalenderprogramm stehen. Dort leert sich das Abo dann still,
     * während ein 404 dauerhaft eine Fehlermeldung stehen ließe.
     */
    public function buildTaskCalendar(User $user, string $baseUrl): string
    {
        $lines = [];

        if ((string) $user->calendar_task_feed === User::CALENDAR_TASK_FEED_SEPARATE) {
            foreach ($this->openTasksFor($user) as $task) {
                $lines = array_merge($lines, $this->buildIcsTaskLines($task, $user, $baseUrl));
            }
        }

        return $this->wrapCalendar($lines, 'Aufgaben', Timezone::resolveAppTimezone());
    }

    /**
     * @return Collection<int, Event>
     */
    private function visibleEvents(int $userId): Collection
    {
        return $this->audienceService
            ->visibleEventsQuery($userId)
            ->where('ends_at', '>=', Carbon::now())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Die offenen Aufgaben der Person mit Enddatum.
     *
     * Ohne Enddatum lässt sich nichts einplanen - solche Aufgaben hätten im
     * Kalender keinen Platz, an den sie gehören, und blieben deshalb außen vor.
     *
     * @return Collection<int, Task>
     */
    private function openTasksFor(User $user): Collection
    {
        return Task::query()
            ->whereHas('assignees', static function ($query) use ($user): void {
                $query->where('users.id', $user->id);
            })
            ->whereNotNull('end_date')
            ->where('status', '!=', self::COMPLETED_STATUS)
            ->with('project')
            ->orderBy('end_date')
            ->get();
    }

    /**
     * @param list<string> $lines
     */
    private function wrapCalendar(array $lines, string $calendarName, string $timezone): string
    {
        $appName = (string) (AppSetting::query()
            ->where('setting_key', 'app_name')
            ->value('setting_value') ?? 'Chor Manager');

        $header = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . $appName . '//Calendar Subscription//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $appName . ' ' . $calendarName,
            'X-WR-TIMEZONE:' . $timezone,
        ];

        $all = array_merge($header, $lines, ['END:VCALENDAR']);

        return implode("\r\n", $all) . "\r\n";
    }

    /**
     * @return list<string>
     */
    private function buildIcsEventLines(Event $event, string $baseUrl, string $timezone): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:event-' . $event->id . '@chor-manager',
            'DTSTAMP:' . Carbon::now('UTC')->format('Ymd\THis\Z'),
            'DTSTART;TZID=' . $timezone . ':' . $event->starts_at->format('Ymd\THis'),
            'DTEND;TZID=' . $timezone . ':' . $event->ends_at->format('Ymd\THis'),
            'SUMMARY:' . $this->escapeIcsText((string) $event->title),
            'DESCRIPTION:' . $this->escapeIcsText($this->buildEventDescription($event, $baseUrl)),
            'URL:' . $this->escapeIcsText($baseUrl . '/events/' . $event->id),
        ];

        if (!empty($event->location)) {
            $lines[] = 'LOCATION:' . $this->escapeIcsText((string) $event->location);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Eine Aufgabe wird entweder ein ganztägiger Termin oder eine echte Aufgabe -
     * je nach Einstellung im Profil.
     *
     * @return list<string>
     */
    private function buildIcsTaskLines(Task $task, User $user, string $baseUrl): array
    {
        $due = Carbon::parse((string) $task->end_date);
        $stamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $url = $baseUrl . '/tasks/' . $task->id;
        $description = $this->buildTaskDescription($task, $baseUrl);

        if ((string) $user->calendar_task_format === User::CALENDAR_TASK_FORMAT_TODO) {
            return [
                'BEGIN:VTODO',
                'UID:task-' . $task->id . '@chor-manager',
                'DTSTAMP:' . $stamp,
                'DUE;VALUE=DATE:' . $due->format('Ymd'),
                'SUMMARY:' . $this->escapeIcsText((string) $task->name),
                'DESCRIPTION:' . $this->escapeIcsText($description),
                'URL:' . $this->escapeIcsText($url),
                'STATUS:' . ((string) $task->status === 'In Bearbeitung' ? 'IN-PROCESS' : 'NEEDS-ACTION'),
                'PRIORITY:' . (self::TODO_PRIORITIES[(string) $task->priority] ?? 5),
                'END:VTODO',
            ];
        }

        return [
            'BEGIN:VEVENT',
            'UID:task-' . $task->id . '@chor-manager',
            'DTSTAMP:' . $stamp,
            'DTSTART;VALUE=DATE:' . $due->format('Ymd'),
            // DTEND ist bei ganztägigen Terminen der erste Tag *danach*; ohne den
            // zusätzlichen Tag zeigen Kalender einen Termin ohne Länge.
            'DTEND;VALUE=DATE:' . $due->copy()->addDay()->format('Ymd'),
            'SUMMARY:' . $this->escapeIcsText('Aufgabe: ' . (string) $task->name),
            'DESCRIPTION:' . $this->escapeIcsText($description),
            'URL:' . $this->escapeIcsText($url),
            'END:VEVENT',
        ];
    }

    private function buildEventDescription(Event $event, string $baseUrl): string
    {
        $description = 'Termin: ' . $event->title;
        $audienceLabel = $this->buildAudienceLabel($event);
        if ($audienceLabel !== '') {
            $description .= '\nZielgruppe: ' . $audienceLabel;
        }
        if (!empty($event->location)) {
            $description .= '\nOrt: ' . $event->location;
        }
        $description .= '\nDetails: ' . $baseUrl . '/events/' . $event->id;

        return $description;
    }

    private function buildTaskDescription(Task $task, string $baseUrl): string
    {
        $description = 'Aufgabe: ' . $task->name;
        if ($task->project) {
            $description .= '\nProjekt: ' . $task->project->name;
        }
        $description .= '\nStatus: ' . $task->status;
        if (!empty($task->priority)) {
            $description .= '\nPriorität: ' . $task->priority;
        }
        $description .= '\nDetails: ' . $baseUrl . '/tasks/' . $task->id;

        return $description;
    }

    private function buildAudienceLabel(Event $event): string
    {
        $sources = $event->audienceSources()->get();
        if ($sources->isEmpty()) {
            return 'Alle Mitglieder';
        }

        $labels = [];
        foreach ($sources as $source) {
            $refId = (int) $source->reference_id;
            $labels[] = match ((string) $source->source_type) {
                'project_members' => 'Projekt: ' . (optional(Project::find($refId))->name ?? '—'),
                'role' => 'Rolle: ' . (optional(Role::find($refId))->name ?? '—'),
                'voice_group' => 'Stimmgruppe: ' . (optional(VoiceGroup::find($refId))->name ?? '—'),
                'user' => 'Person: ' . $this->nameFormatter->formatPerson(User::find($refId)),
                default => '',
            };
        }

        return implode(', ', array_filter($labels));
    }

    private function escapeIcsText(string $text): string
    {
        return str_replace(
            ["\r\n", ',', ';'],
            ['\n', '\\,', '\;'],
            $text
        );
    }
}
