<?php

declare(strict_types=1);

namespace App\Controllers;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Exceptions\InvalidAudienceSourcesException;
use App\Models\Comment;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\ProjectQuery;
use App\Services\CalendarSubscriptionService;
use App\Services\CalendarFeedService;
use App\Services\EventAudienceService;
use App\Services\EventRecurrenceService;
use App\Services\NotificationService;
use App\Services\ModalFormService;
use App\Services\NameFormatterService;
use App\Util\AppUrlResolver;
use App\Util\NotificationType;
use App\Util\SafeRedirect;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Psr\Log\LoggerInterface;

class EventController
{
    /**
     * Schlüssel, unter dem die frisch erzeugte Abo-Adresse genau einen
     * Seitenaufruf lang in der Sitzung liegt.
     */
    private const SUBSCRIPTION_FLASH_KEY = 'calendar_subscription_token';

    /**
     * Zugelassene Takte einer Terminserie. Ein unbekannter Takt darf nicht bis in
     * die Terminerzeugung durchkommen - EventRecurrenceService kennt ihn nicht und
     * würde ihn als Wochentakt auslegen.
     */
    private const SERIES_FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    /**
     * Feldgruppen, die eine Serienänderung auf die Folgetermine überträgt. Ohne
     * Auswahl gelten alle - so verhält sich die Serienänderung wie bisher, wer
     * einzelne Termine angepasst hat, kann sie aber gezielt aussparen.
     */
    private const SERIES_FIELD_GROUPS = ['title', 'location', 'time', 'registration', 'attendance', 'audience'];

    /** Beschriftungen derselben Feldgruppen für das Bearbeiten-Formular. */
    private const SERIES_FIELD_GROUP_LABELS = [
        'title' => 'Titel und Terminart',
        'location' => 'Ort',
        'time' => 'Uhrzeit',
        'registration' => 'Anmeldung und Anmeldeschluss',
        'attendance' => 'Anwesenheitspflicht',
        'audience' => 'Zielgruppe',
    ];

    private Twig $view;
    private NameFormatterService $nameFormatter;
    private LoggerInterface $logger;

    private ?NotificationService $notificationService;

    /**
     * `$notificationService` steht am Ende und ist optional: Zahlreiche Tests
     * bauen diesen Controller mit festen Positionsargumenten, ein Parameter in
     * der Mitte hätte sie stumm verschoben. Im Betrieb reicht ihn die
     * ausdrückliche Registrierung in `Dependencies.php` durch - PHP-DI füllt
     * optionale Parameter nicht selbst, dagegen steht
     * `NotificationWiringFeatureTest`.
     */
    public function __construct(
        Twig $view,
        NameFormatterService $nameFormatter,
        LoggerInterface $logger,
        ?NotificationService $notificationService = null
    ) {
        $this->view = $view;
        $this->nameFormatter = $nameFormatter;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
    }

    /**
     * Ob dieser Vorgang die Zielgruppe benachrichtigen soll.
     *
     * Vorbelegt ist das Häkchen im Formular; fehlt das Feld ganz - etwa weil
     * ein älteres Formular oder ein Skript sendet -, wird benachrichtigt. Die
     * stille Variante wäre die falsche Vorgabe: Ein neuer Termin, von dem
     * niemand erfährt, ist schlimmer als eine Mail zu viel.
     *
     * @param array<string, mixed> $data
     */
    private function shouldNotifyMembers(array $data): bool
    {
        if (!array_key_exists('notify_members_present', $data)) {
            return true;
        }

        return !empty($data['notify_members']);
    }

    /**
     * Was sich an Zeit und Ort geändert hat, als Vorher/Nachher-Paare.
     *
     * Nur diese drei Angaben melden sich: Wegen einer verschobenen Uhrzeit oder
     * eines anderen Saals muss jemand anders planen, wegen einer korrigierten
     * Beschreibung nicht. Eine Mail für jede Kleinigkeit gewöhnt die Leute ab,
     * hinzusehen - und dann geht die verschobene Probe mit unter.
     *
     * @return list<array{label: string, before: string, after: string}>
     */
    private function describeScheduleChanges(
        Event $event,
        string $newStartsAt,
        string $newEndsAt,
        ?string $newLocation
    ): array {
        $changes = [];

        $formatMoment = static fn (?string $value): string => $value === null || $value === ''
            ? '-'
            : Carbon::parse($value)->format('d.m.Y H:i') . ' Uhr';

        $oldStartsAt = $event->starts_at ? $event->starts_at->format('Y-m-d H:i:s') : null;
        $oldEndsAt = $event->ends_at ? $event->ends_at->format('Y-m-d H:i:s') : null;

        if ($oldStartsAt !== $newStartsAt) {
            $changes[] = [
                'label' => 'Beginn',
                'before' => $formatMoment($oldStartsAt),
                'after' => $formatMoment($newStartsAt),
            ];
        }

        if ($oldEndsAt !== $newEndsAt) {
            $changes[] = [
                'label' => 'Ende',
                'before' => $formatMoment($oldEndsAt),
                'after' => $formatMoment($newEndsAt),
            ];
        }

        $oldLocation = trim((string) $event->location);
        $location = trim((string) $newLocation);
        if ($oldLocation !== $location) {
            $changes[] = [
                'label' => 'Ort',
                'before' => $oldLocation === '' ? 'ohne Ortsangabe' : $oldLocation,
                'after' => $location === '' ? 'ohne Ortsangabe' : $location,
            ];
        }

        return $changes;
    }

    /**
     * Die Zielgruppe mehrerer Termine als Mitglieder, ohne Dopplungen.
     *
     * Eigener Schritt, weil die Absage sie **vor** dem Löschen braucht: Danach
     * gibt es die Zielgruppen-Zeilen nicht mehr.
     *
     * @param list<Event> $events
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function audienceUsersFor(array $events): \Illuminate\Support\Collection
    {
        if ($events === []) {
            return new \Illuminate\Support\Collection();
        }

        $idsByEvent = (new EventAudienceService())->eligibleUserIdsForEvents($events);

        $userIds = [];
        foreach ($idsByEvent as $ids) {
            foreach ($ids as $id) {
                $userIds[(int) $id] = true;
            }
        }

        if ($userIds === []) {
            return new \Illuminate\Support\Collection();
        }

        return User::whereIn('id', array_keys($userIds))->get();
    }

    private function actorName(int $actorId): ?string
    {
        if ($actorId <= 0) {
            return null;
        }

        $actor = User::find($actorId);

        return $actor === null ? null : $this->nameFormatter->formatPerson($actor);
    }

    /**
     * Versand an eine bereits aufgelöste Empfängerliste - für die Absage, deren
     * Zielgruppe zum Zeitpunkt des Versands nicht mehr ermittelbar ist.
     *
     * @param \Illuminate\Support\Collection<int, User> $recipients
     * @param list<Event> $events
     * @param array<string, mixed> $extraContext
     */
    private function notifyResolvedAudience(
        Request $request,
        string $type,
        \Illuminate\Support\Collection $recipients,
        array $events,
        string $subject,
        string $template,
        array $extraContext = []
    ): void {
        if ($this->notificationService === null || $recipients->isEmpty() || $events === []) {
            return;
        }

        $baseUrl = AppUrlResolver::resolveBaseUrl($request);

        $this->notificationService->notify(
            $type,
            $recipients,
            $subject,
            $template,
            array_merge([
                'events' => $events,
                'event' => $events[0],
                'link' => $baseUrl . '/events',
                'profile_url' => $baseUrl . '/profile',
            ], $extraContext),
            (int) ($_SESSION['user_id'] ?? 0) ?: null
        );
    }

    /**
     * Meldet der Zielgruppe einen oder mehrere Termine.
     *
     * Die Termine kommen als Liste, auch wenn es nur einer ist: Eine Serie
     * ergibt so **eine** Mail statt vierzig - wer ein Halbjahr Montagsproben
     * anlegt, füllt sonst jedem das Postfach.
     *
     * @param list<\App\Models\Event> $events
     * @param array<string, mixed> $extraContext
     */
    private function notifyAudience(
        Request $request,
        string $type,
        array $events,
        string $subject,
        string $template,
        array $extraContext = []
    ): void {
        if ($this->notificationService === null || $events === []) {
            return;
        }

        $audienceService = new EventAudienceService();
        $recipientIds = $audienceService->eligibleUserIdsForEvents($events);

        $userIds = [];
        foreach ($recipientIds as $ids) {
            foreach ($ids as $id) {
                $userIds[(int) $id] = true;
            }
        }

        if ($userIds === []) {
            return;
        }

        $baseUrl = AppUrlResolver::resolveBaseUrl($request);
        $first = $events[0];

        $this->notificationService->notify(
            $type,
            User::whereIn('id', array_keys($userIds))->get(),
            $subject,
            $template,
            array_merge([
                'events' => $events,
                'event' => $first,
                'link' => $baseUrl . '/events/' . $first->id,
                'profile_url' => $baseUrl . '/profile',
            ], $extraContext),
            (int) ($_SESSION['user_id'] ?? 0) ?: null
        );
    }

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $seesAllEvents = $this->canManageEvents();
        $accessibleProjects = $this->getAccessibleProjects($userId, $seesAllEvents);
        $accessibleProjectIds = $accessibleProjects->pluck('id')->map(static fn($id) => (int) $id)->all();

        $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;
        $eventTypeId = !empty($queryParams['event_type_id']) ? (int)$queryParams['event_type_id'] : null;
        $sort = $queryParams['sort'] ?? 'starts_at';
        $direction = strtolower((string) ($queryParams['direction'] ?? 'asc'));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }
        $showOldEvents = !empty($queryParams['show_old_events']) ? (int)$queryParams['show_old_events'] : 0;
        $viewMode = in_array($queryParams['view'] ?? '', ['list', 'calendar'], true)
            ? $queryParams['view']
            : 'list';

        if ($projectId !== null && $projectId > 0 && !$seesAllEvents && !in_array($projectId, $accessibleProjectIds, true)) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zu diesem Projekt.',
                'event.index.project_forbidden'
            );
        }

        // Allowed sort columns
        $allowedSorts = ['starts_at', 'title', 'type', 'location'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'starts_at';
        }

        $query = Event::query();

        if (!$seesAllEvents) {
            $visibleIds = (new EventAudienceService())
                ->visibleEventsQuery($userId)
                ->pluck('id')
                ->map(static fn($id): int => (int) $id)
                ->all();
            $query->whereIn('id', $visibleIds === [] ? [0] : $visibleIds);
        }

        if ($projectId) {
            $query->whereHas('audienceSources', function ($sourceQuery) use ($projectId) {
                $sourceQuery->where('source_type', 'project_members')
                    ->where('reference_id', $projectId);
            });
        }
        if ($eventTypeId) {
            $query->where('event_type_id', $eventTypeId);
        }

        // Filter out old events (older than 14 days) unless show_old_events=1
        if (!$showOldEvents) {
            $query->whereDate('starts_at', '>=', Carbon::now()->subDays(14));
        }

        if ($sort === 'type') {
            $query->leftJoin('event_types', 'events.event_type_id', '=', 'event_types.id')
                ->orderBy('event_types.name', $direction)
                ->select('events.*');
        } else {
            $query->orderBy($sort, $direction);
        }

        $events = $query->get();

        // Manual eager loading to avoid PHP 8.4 deprecation in Eloquent
        $eventTypeIds = $events->pluck('event_type_id')->filter()->unique()->toArray();
        $seriesIds = $events->pluck('series_id')->filter()->unique()->toArray();

        $eventTypesMap = EventType::whereIn('id', $eventTypeIds)->get()->keyBy('id');
        $seriesMap = EventSeries::whereIn('id', $seriesIds)->get()->keyBy('id');

        $scopedEventIds = EventAudienceSource::query()
            ->whereIn('event_id', $events->pluck('id')->map(static fn($id) => (int) $id)->all())
            ->pluck('event_id')
            ->map(static fn($id) => (int) $id)
            ->unique()
            ->flip();

        $events->map(function ($event) use ($eventTypesMap, $seriesMap, $scopedEventIds) {
            $eventType = !is_null($event->event_type_id) ? $eventTypesMap->get($event->event_type_id) : null;
            $series = !is_null($event->series_id) ? $seriesMap->get($event->series_id) : null;

            $event->setRelation('eventType', $eventType);
            $event->setRelation('series', $series);

            // For template compatibility
            $event->type_name = $eventType ? $eventType->name : $event->type;
            $event->type_color = $eventType ? $eventType->color : 'info';
            $event->audience_label = $scopedEventIds->has((int) $event->id) ? 'Ausgewählt' : 'Alle';

            return $event;
        });

        $this->hydrateVisibleComments($events, $userId);

        $bootstrapColorMap = [
            'primary'   => '#0d6efd',
            'secondary' => '#6c757d',
            'success'   => '#198754',
            'danger'    => '#dc3545',
            'warning'   => '#ffc107',
            'info'      => '#0dcaf0',
            'light'     => '#f8f9fa',
            'dark'      => '#212529',
        ];
        $calendarEvents = $events->map(static function ($event) use ($bootstrapColorMap): array {
            $colorName = (string) ($event->type_color ?? 'secondary');
            return [
                'id'    => $event->id,
                'title' => htmlspecialchars((string) $event->title, ENT_QUOTES, 'UTF-8'),
                'start' => $event->starts_at instanceof \DateTimeInterface
                    ? $event->starts_at->format('Y-m-d\TH:i:s')
                    : (string) $event->starts_at,
                'end'   => $event->ends_at instanceof \DateTimeInterface
                    ? $event->ends_at->format('Y-m-d\TH:i:s')
                    : (string) $event->ends_at,
                'color' => $bootstrapColorMap[$colorName] ?? '#6c757d',
                'url'   => '/events/' . $event->id,
            ];
        })->values()->all();
        $calendarEventsJson = json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        $projects = $accessibleProjects;
        $eventTypes = EventType::orderBy('name')->get();
        $roles = Role::query()->orderBy('name')->get();
        $voiceGroups = VoiceGroup::query()->orderBy('id')->get();
        $audienceUsersQuery = User::query()->where('is_active', 1);
        foreach ($this->nameFormatter->orderColumns() as $column) {
            $audienceUsersQuery->orderBy($column);
        }
        $audienceUsers = $audienceUsersQuery->get();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);
        $createService = new ModalFormService('event_create');
        $createState = $createService->getState();
        $createService->clear();

        $calendarSubscription = $this->calendarSubscriptionState($request, $userId);

        return $this->view->render($response, 'events/index.twig', [
            'events' => $events,
            'projects' => $projects,
            'event_types' => $eventTypes,
            'filters' => [
                'project_id' => $projectId,
                'event_type_id' => $eventTypeId,
                'show_old_events' => $showOldEvents,
                'sort' => $sort,
                'direction' => $direction
            ],
            'success' => $success,
            'error' => $error,
            'create_form' => $createState,
            'view_mode' => $viewMode,
            'calendar_events' => $calendarEventsJson,
            'calendar_subscription' => $calendarSubscription,
            'roles' => $roles,
            'voice_groups' => $voiceGroups,
            'audience_users' => $audienceUsers,
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $event = Event::find((int) $args['id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.detail.forbidden',
                (int) $event->id
            );
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $event->setRelation('comments', $this->getVisibleEventComments($event->id, $userId));

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'events/detail.twig', [
            'event' => $event,
            'success' => $success,
            'error' => $error,
            'calendar_subscription' => $this->calendarSubscriptionState($request, $userId),
        ]);
    }

    /**
     * Erzeugt ein Kalender-Abo und verwirft ein vorhandenes.
     *
     * Die Adresse wird nur hier ausgegeben - gespeichert ist ab Migration
     * 20260825120300 nur noch ihr Hash. Sie liegt deshalb genau einen
     * Seitenaufruf lang in der Sitzung und wird von der Zielseite geleert.
     */
    public function createSubscription(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->denyEventAccess(
                $response,
                'Für ein Kalender-Abo musst du angemeldet sein.',
                'event.subscription.no_session'
            );
        }

        $data = (array) $request->getParsedBody();
        $target = $this->subscriptionRedirectTarget($data['redirect_to'] ?? null);

        $subscriptionService = new CalendarSubscriptionService();
        $rotated = $subscriptionService->hasTokenForUser($userId);
        $token = $subscriptionService->rotateTokenForUser($userId);

        // Der Token statt der fertigen Adresse: Aus ihm entstehen beide Links,
        // und ob der zweite gebraucht wird, entscheidet erst die Anzeige.
        $_SESSION[self::SUBSCRIPTION_FLASH_KEY] = $token;

        // Bewusst ohne Token im Kontext: Die Adresse ist das Geheimnis selbst und
        // hat in keinem Log etwas verloren.
        $this->logger->info('Calendar subscription token issued.', [
            'event' => 'calendar_subscription.token.issued',
            'user_id' => $userId,
            'replaced_existing' => $rotated,
        ]);

        $_SESSION['success'] = $rotated
            ? 'Neue Abo-Adresse erzeugt. Die bisherige ist ab sofort ungültig.'
            : 'Abo-Adresse erzeugt.';

        return $response->withHeader('Location', $target)->withStatus(302);
    }

    /**
     * Anzeigezustand des Kalender-Abos.
     *
     * `url` ist nur gesetzt, wenn die Adresse überhaupt zeigbar ist: direkt nach
     * dem Erzeugen, oder bei einem Altbestand-Abo, das noch im Klartext vorliegt.
     * Sonst steht nur fest, ob ein Abo aktiv ist. `autoshow` ist nur beim frischen
     * Erzeugen wahr - ein zeigbares Altbestand-Abo würde sonst bei jedem Aufruf der
     * Seite erneut das Fenster öffnen.
     *
     * `task_url` steht nur da, wenn die Person im Profil ein eigenes Aufgaben-Abo
     * gewählt hat. Bei `combined` liefert schon `url` die Aufgaben mit, ein
     * zweiter Link würde dann dieselben Einträge ein zweites Mal einspielen.
     *
     * @return array{exists: bool, url: string|null, task_url: string|null, autoshow: bool}|null
     */
    private function calendarSubscriptionState(Request $request, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $freshToken = $_SESSION[self::SUBSCRIPTION_FLASH_KEY] ?? null;
        unset($_SESSION[self::SUBSCRIPTION_FLASH_KEY]);

        if (is_string($freshToken) && $freshToken !== '') {
            return $this->subscriptionStateForToken($request, $userId, $freshToken, true);
        }

        $subscriptionService = new CalendarSubscriptionService();
        $legacyToken = $subscriptionService->findLegacyTokenForUser($userId);

        if ($legacyToken !== null) {
            return $this->subscriptionStateForToken($request, $userId, $legacyToken, false);
        }

        return [
            'exists' => $subscriptionService->hasTokenForUser($userId),
            'url' => null,
            'task_url' => null,
            'autoshow' => false,
        ];
    }

    /**
     * @return array{exists: bool, url: string|null, task_url: string|null, autoshow: bool}
     */
    private function subscriptionStateForToken(
        Request $request,
        int $userId,
        string $token,
        bool $autoshow
    ): array {
        $wantsOwnTaskFeed = User::where('id', $userId)
            ->where('calendar_task_feed', User::CALENDAR_TASK_FEED_SEPARATE)
            ->exists();

        return [
            'exists' => true,
            'url' => $this->subscriptionUrl($request, $token),
            'task_url' => $wantsOwnTaskFeed ? $this->taskSubscriptionUrl($request, $token) : null,
            'autoshow' => $autoshow,
        ];
    }

    private function subscriptionUrl(Request $request, string $token): string
    {
        return AppUrlResolver::resolveBaseUrl($request) . '/events/export/' . $token . '.ics';
    }

    private function taskSubscriptionUrl(Request $request, string $token): string
    {
        return AppUrlResolver::resolveBaseUrl($request) . '/tasks/export/' . $token . '.ics';
    }

    /**
     * Rücksprungziel nach dem Erzeugen. Zugelassen sind nur Termin-Seiten dieser
     * Anwendung - SafeRedirect wehrt fremde Ziele ab, die Präfixprüfung hält den
     * Knopf auf den Seiten, die das Abo-Fenster überhaupt kennen.
     */
    private function subscriptionRedirectTarget(mixed $candidate): string
    {
        $target = SafeRedirect::sanitize(is_string($candidate) ? $candidate : null);

        if ($target === null || !str_starts_with($target, '/events')) {
            return '/events';
        }

        return $target;
    }

    public function exportCalendar(Request $request, Response $response, array $args): Response
    {
        $subscription = (new CalendarSubscriptionService())->findByToken((string) $args['token']);
        if (!$subscription) {
            return $response->withStatus(404);
        }

        $user = User::find((int) $subscription->user_id);
        if (!$user) {
            return $response->withStatus(404);
        }

        $content = (new CalendarFeedService($this->nameFormatter))
            ->buildEventCalendar($user, AppUrlResolver::resolveBaseUrl($request));

        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="chor-manager.ics"');
    }

    /**
     * Extract audience sources from a submitted event form. Prefers the
     * JSON payload built client-side, falling back to a plain sources array.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{type:string, reference_id:int}>
     */
    private function readAudienceSources(array $data): array
    {
        $sourcesJson = trim((string) ($data['sources_json'] ?? ''));
        if ($sourcesJson !== '') {
            $decoded = json_decode($sourcesJson, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        }

        if (isset($data['sources']) && is_array($data['sources'])) {
            return $data['sources'];
        }

        return [];
    }

    public function addNote(Request $request, Response $response, array $args): Response
    {
        $event = Event::find((int) $args['id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.note.event_forbidden',
                (int) $event->id
            );
        }

        $data = (array) $request->getParsedBody();
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            $_SESSION['error'] = 'Bemerkung darf nicht leer sein.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
        }

        $isPrivate = !empty($data['is_private']);

        Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'comment' => $content,
            'is_private' => $isPrivate,
        ]);

        // Eine private Bemerkung sieht nur, wer sie geschrieben hat - sie per
        // Mail an die ganze Zielgruppe zu tragen, kehrte ihren Zweck um.
        if (!$isPrivate) {
            $this->notifyAudience(
                $request,
                NotificationType::EVENT_NOTE,
                [$event],
                'Bemerkung zu: ' . $event->title,
                'emails/notification_event_note.twig',
                [
                    'note_text' => $content,
                    'actor_name' => $this->actorName((int) ($_SESSION['user_id'] ?? 0)),
                ]
            );
        }

        $_SESSION['success'] = 'Bemerkung hinzugefügt.';
        return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
    }

    public function updateNote(Request $request, Response $response, array $args): Response
    {
        $event = Event::find((int) $args['id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.note.event_forbidden',
                (int) $event->id
            );
        }

        $note = $this->findEventNote($event->id, (int) $args['note_id']);
        if (!$note) {
            $_SESSION['error'] = 'Bemerkung nicht gefunden.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
        }

        if (!$this->canManageEventNote($note, $event)) {
            return $this->denyEventAccess(
                $response,
                'Diese Bemerkung darfst du nicht bearbeiten.',
                'event.note.note_forbidden',
                (int) $event->id
            );
        }

        $data = (array) $request->getParsedBody();
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            $_SESSION['error'] = 'Bemerkung darf nicht leer sein.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
        }

        $note->update(['comment' => $content]);

        $_SESSION['success'] = 'Private Bemerkung aktualisiert.';
        return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
    }

    public function deleteNote(Request $request, Response $response, array $args): Response
    {
        $event = Event::find((int) $args['id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.note.event_forbidden',
                (int) $event->id
            );
        }

        $note = $this->findEventNote($event->id, (int) $args['note_id']);
        if (!$note) {
            $_SESSION['error'] = 'Bemerkung nicht gefunden.';
            return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
        }

        if (!$this->canManageEventNote($note, $event)) {
            return $this->denyEventAccess(
                $response,
                'Diese Bemerkung darfst du nicht bearbeiten.',
                'event.note.note_forbidden',
                (int) $event->id
            );
        }

        $note->delete();

        $_SESSION['success'] = 'Private Bemerkung gelöscht.';
        return $response->withHeader('Location', '/events/' . $event->id)->withStatus(302);
    }

    /**
     * Dieselbe Auswahl wie in den Auswertungen, deshalb liegt sie in ProjectQuery.
     * Der Konstruktor bleibt unverändert: NameFormatterService ist ohnehin da, und
     * ein zusätzlicher Parameter hätte jede Aufrufstelle im Container und in sechs
     * Testdateien angefasst.
     */
    private function getAccessibleProjects(int $userId, bool $seesAllEvents)
    {
        return (new ProjectQuery($this->nameFormatter))->getAccessibleProjects($userId, $seesAllEvents);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $title = trim($data['title'] ?? '');
        $startsAtDate = $data['starts_at'] ?? '';
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        $eventTypeId = !empty($data['event_type_id']) ? (int)$data['event_type_id'] : null;
        $repeat = !empty($data['repeat']);
        $registrationEnabled = !empty($data['registration_enabled']);
        $attendanceRequired = !empty($data['attendance_required']);
        $registrationDeadlineRaw = trim((string) ($data['registration_deadline'] ?? ''));
        $registrationDeadline = null;
        if ($registrationEnabled && $registrationDeadlineRaw !== '') {
            try {
                $registrationDeadline = Carbon::parse($registrationDeadlineRaw)->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $registrationDeadline = null;
            }
        }

        $formData = [
            'title' => $title,
            'starts_at' => $startsAtDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'event_type_id' => $eventTypeId ?? '',
            'location' => trim($data['location'] ?? ''),
            'repeat' => $repeat,
            'recurrence_interval' => trim((string) ($data['recurrence_interval'] ?? '1')),
            'frequency' => trim((string) ($data['frequency'] ?? 'weekly')),
            'weekdays' => array_values(array_map('intval', (array) ($data['weekdays'] ?? [1]))),
            'series_end_date' => trim((string) ($data['series_end_date'] ?? '')),
            'registration_enabled' => $registrationEnabled,
            'registration_deadline' => $registrationDeadlineRaw,
            'attendance_required' => $attendanceRequired,
        ];

        $audienceService = new EventAudienceService();
        $rawSources = $this->readAudienceSources($data);
        $sources = $audienceService->normalizeSources($rawSources);

        // Ein Termin ohne Quellen gilt für alle Mitglieder. Bleibt von einer
        // angegebenen Zielgruppe nichts übrig, wäre das Speichern also eine
        // stillschweigende Verbreiterung - dann lieber gar nicht speichern.
        if ($rawSources !== [] && $sources === []) {
            $createService = new ModalFormService('event_create');
            $createService->setError(InvalidAudienceSourcesException::MESSAGE, $formData);
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$startsAtDate || !$startTime || !$endTime) {
            $createService = new ModalFormService('event_create');
            $createService->setError('Datum, Startzeit und Endzeit sind Pflichtfelder.', $formData);
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        try {
            $parsedStart = Carbon::createFromFormat('Y-m-d H:i', $startsAtDate . ' ' . $startTime);
            $parsedEnd   = Carbon::createFromFormat('Y-m-d H:i', $startsAtDate . ' ' . $endTime);
        } catch (Exception $e) {
            $parsedStart = false;
            $parsedEnd   = false;
        }
        if (!$parsedStart || !$parsedEnd) {
            $createService = new ModalFormService('event_create');
            $createService->setError('Ungültiges Datum oder Zeitformat.', $formData);
            return $response->withHeader('Location', '/events')->withStatus(302);
        }
        if (!$parsedEnd->greaterThan($parsedStart)) {
            $createService = new ModalFormService('event_create');
            $createService->setError('Endzeit muss nach der Startzeit liegen.', $formData);
            return $response->withHeader('Location', '/events')->withStatus(302);
        }
        $startsAt = $parsedStart->format('Y-m-d H:i:s');
        $endsAt   = $parsedEnd->format('Y-m-d H:i:s');

        // Takt und Intervall auch serverseitig prüfen: Das Formular bietet nur die
        // vier Takte und `min="1"` an, ein manipulierter Beitrag käme sonst aber bis
        // in die Erzeugungsschleife - mit unbekanntem Takt endlos, mit Intervall 0
        // oder weniger mit 501 Terminen auf demselben Tag.
        $frequency = 'weekly';
        $interval = 1;
        $weekdays = [];
        if ($repeat) {
            $frequency = self::normalizeSeriesFrequency($data['frequency'] ?? null);
            $interval = self::normalizeRecurrenceInterval($data['recurrence_interval'] ?? null);

            // Wie bei den Zielgruppen-Quellen: Bleibt von angegebenen Wochentagen
            // nichts Gültiges übrig, wäre die Serie stillschweigend eine tägliche -
            // dann lieber gar nicht anlegen.
            $rawWeekdays = is_array($data['weekdays'] ?? null) ? $data['weekdays'] : [];
            $weekdays = self::normalizeWeekdays($rawWeekdays);
            $weekdaysDropped = $rawWeekdays !== [] && $weekdays === [];

            if ($frequency === null || $interval === null || $weekdaysDropped) {
                $createService = new ModalFormService('event_create');
                $createService->setError('Ungültige Wiederholung. Bitte Takt und Intervall prüfen.', $formData);
                return $response->withHeader('Location', '/events')->withStatus(302);
            }

            // Wochentage gehören zum Wochentakt. Bei täglich, monatlich und jährlich
            // wertet sie niemand aus; gespeichert würden sie eine Regel behaupten,
            // die die Serie nicht befolgt.
            if ($frequency !== EventRecurrenceService::FREQUENCY_WEEKLY) {
                $weekdays = [];
            }
        }

        try {
            $eventType = null;
            if ($eventTypeId) {
                $eventType = EventType::find($eventTypeId);
            }
            $typeName = $eventType ? $eventType->name : 'Probe';

            if (empty($title)) {
                $title = $typeName;
            }

            if (!$repeat) {
                // Single event
                $event = Event::create([
                    'title' => $title,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'event_type_id' => $eventTypeId,
                    'type' => $typeName,
                    'location' => trim($data['location'] ?? ''),
                    'registration_enabled' => $registrationEnabled,
                    'registration_deadline' => $registrationDeadline,
                    'attendance_required' => $attendanceRequired,
                ]);
                $audienceService->setSources($event, $sources);

                if ($this->shouldNotifyMembers($data)) {
                    $this->notifyAudience(
                        $request,
                        NotificationType::EVENT_CREATED,
                        [$event->fresh()],
                        'Neuer Termin: ' . $title,
                        'emails/notification_event_created.twig',
                        ['series_title' => $title]
                    );
                }

                $_SESSION['success'] = 'Event erfolgreich angelegt.';
            } else {
                // Series - $frequency, $interval und $weekdays (1 = Mo bis 7 = So)
                // sind oben bereits geprüft.
                $endDateStr = $data['series_end_date'] ?? null;

                if (!$endDateStr) {
                    throw new Exception('Enddatum für die Serie ist erforderlich.');
                }

                $series = EventSeries::create([
                    'frequency' => $frequency,
                    'recurrence_interval' => $interval,
                    'weekdays' => !empty($weekdays) ? implode(',', $weekdays) : null,
                    'end_date' => $endDateStr
                ]);

                $occurrences = (new EventRecurrenceService())->occurrences(
                    CarbonImmutable::parse($startsAtDate),
                    $frequency,
                    $interval,
                    $weekdays,
                    CarbonImmutable::parse($endDateStr)
                );

                // Anmeldeschluss wie bei der Serienänderung als Vorlauf zum jeweiligen
                // Terminbeginn. Ein absoluter Zeitpunkt schlösse alle Termine der Serie
                // gleichzeitig; ihn stattdessen zu verwerfen, ließe die Eingabe aus dem
                // Formular kommentarlos verschwinden.
                $deadlineLeadSeconds = null;
                if ($registrationEnabled && $registrationDeadline !== null) {
                    $deadlineLeadSeconds = Carbon::parse($registrationDeadline)->getTimestamp()
                        - Carbon::parse($startsAt)->getTimestamp();
                }

                $count = 0;
                $createdEvents = [];
                foreach ($occurrences as $occurrence) {
                    $day = $occurrence->format('Y-m-d');
                    $occurrenceStart = Carbon::parse($day . ' ' . $startTime . ':00');

                    $seriesEvent = Event::create([
                        'title' => $title,
                        'starts_at' => $occurrenceStart->format('Y-m-d H:i:s'),
                        'ends_at' => $day . ' ' . $endTime . ':00',
                        'event_type_id' => $eventTypeId,
                        'type' => $typeName,
                        'series_id' => $series->id,
                        'location' => trim($data['location'] ?? ''),
                        'registration_enabled' => $registrationEnabled,
                        'registration_deadline' => $deadlineLeadSeconds === null
                            ? null
                            : $occurrenceStart->copy()->addSeconds($deadlineLeadSeconds)->format('Y-m-d H:i:s'),
                        'attendance_required' => $attendanceRequired,
                    ]);
                    $audienceService->setSources($seriesEvent, $sources);
                    $createdEvents[] = $seriesEvent->fresh();
                    $count++;
                }

                if ($this->shouldNotifyMembers($data)) {
                    $this->notifyAudience(
                        $request,
                        NotificationType::EVENT_CREATED,
                        $createdEvents,
                        'Neue Termine: ' . $title,
                        'emails/notification_event_created.twig',
                        ['series_title' => $title]
                    );
                }

                $_SESSION['success'] = "Serie erfolgreich angelegt ($count Termine).";
            }
        } catch (Exception $e) {
            $createService = new ModalFormService('event_create');
            $createService->setError('Fehler beim Anlegen: ' . $e->getMessage(), $formData);
        }

        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $event = Event::find($id);
        if (!$event) {
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.edit.forbidden',
                (int) $event->id
            );
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $seesAllEvents = $this->canManageEvents();
        $projects = $this->getAccessibleProjects($userId, $seesAllEvents);
        $eventTypes = EventType::orderBy('name')->get();
        $roles = Role::query()->orderBy('name')->get();
        $voiceGroups = VoiceGroup::query()->orderBy('id')->get();
        $usersQuery = User::query()->where('is_active', 1);
        foreach ($this->nameFormatter->orderColumns() as $column) {
            $usersQuery->orderBy($column);
        }
        $users = $usersQuery->get();
        $audienceSources = (new EventAudienceService())->getSources($event);

        // Get error and form data from ModalFormService
        $editService = new ModalFormService('event_edit');
        $state = $editService->getState();
        $error = $state['open_modal'] ? ($_SESSION['error'] ?? null) : null;
        unset($_SESSION['error']);
        $editForm = $state['form'] ?? [];
        $editService->clear();

        // If no form data from service, build from event
        if (empty($editForm)) {
            $editForm = [
                'title' => (string) $event->title,
                'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
                'start_time' => Carbon::parse($event->starts_at)->format('H:i'),
                'end_time' => Carbon::parse($event->ends_at)->format('H:i'),
                'event_type_id' => $event->event_type_id !== null ? (string) $event->event_type_id : '',
                'location' => (string) ($event->location ?? ''),
                'update_series' => false,
                'registration_enabled' => (bool) $event->registration_enabled,
                'registration_deadline' => $event->registration_deadline
                    ? Carbon::parse($event->registration_deadline)->format('Y-m-d\TH:i')
                    : '',
                'attendance_required' => (bool) $event->attendance_required,
            ];
        }

        return $this->view->render($response, 'events/edit.twig', [
            'event' => $event,
            'projects' => $projects,
            'event_types' => $eventTypes,
            'roles' => $roles,
            'voice_groups' => $voiceGroups,
            'users' => $users,
            'audience_sources' => $audienceSources,
            'error' => $error,
            'edit_form' => $editForm,
            'series_field_groups' => self::seriesFieldGroupOptions(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $event = Event::find($id);
        if (!$event) {
            $_SESSION['error'] = 'Event nicht gefunden.';
            return $response->withHeader('Location', '/events')->withStatus(302);
        }

        if (!$this->canAccessEvent($event)) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.update.forbidden',
                (int) $event->id
            );
        }

        $data = (array)$request->getParsedBody();
        $title = trim($data['title'] ?? '');
        $startsAtDate = $data['starts_at'] ?? '';
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        $eventTypeId = !empty($data['event_type_id']) ? (int)$data['event_type_id'] : null;
        $updateSeries = !empty($data['update_series']);
        $seriesFields = self::normalizeSeriesFieldGroups($data['series_fields'] ?? null);
        $registrationEnabled = !empty($data['registration_enabled']);
        $attendanceRequired = !empty($data['attendance_required']);
        $registrationDeadlineRaw = trim((string) ($data['registration_deadline'] ?? ''));
        $registrationDeadline = null;
        if ($registrationEnabled && $registrationDeadlineRaw !== '') {
            try {
                $registrationDeadline = Carbon::parse($registrationDeadlineRaw)->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $registrationDeadline = null;
            }
        }

        $formData = [
            'title' => $title,
            'starts_at' => $startsAtDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'event_type_id' => $eventTypeId ?? '',
            'location' => trim($data['location'] ?? ''),
            'update_series' => $updateSeries,
            'series_fields' => $seriesFields,
            'registration_enabled' => $registrationEnabled,
            'registration_deadline' => $registrationDeadlineRaw,
            'attendance_required' => $attendanceRequired,
        ];

        $audienceService = new EventAudienceService();
        $rawSources = $this->readAudienceSources($data);
        $sources = $audienceService->normalizeSources($rawSources);

        // Siehe save(): Eine verworfene Zielgruppe würde den Termin auf alle
        // Mitglieder ausweiten, statt die Änderung zu verweigern.
        if ($rawSources !== [] && $sources === []) {
            $editService = new ModalFormService('event_edit');
            $editService->setError(InvalidAudienceSourcesException::MESSAGE, $formData);
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }

        if (!$startsAtDate || !$startTime || !$endTime) {
            $editService = new ModalFormService('event_edit');
            $editService->setError('Datum, Startzeit und Endzeit sind Pflichtfelder.', $formData);
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }

        try {
            $parsedStart = Carbon::createFromFormat('Y-m-d H:i', $startsAtDate . ' ' . $startTime);
            $parsedEnd   = Carbon::createFromFormat('Y-m-d H:i', $startsAtDate . ' ' . $endTime);
        } catch (Exception $e) {
            $parsedStart = false;
            $parsedEnd   = false;
        }
        if (!$parsedStart || !$parsedEnd) {
            $editService = new ModalFormService('event_edit');
            $editService->setError('Ungültiges Datum oder Zeitformat.', $formData);
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }
        if (!$parsedEnd->greaterThan($parsedStart)) {
            $editService = new ModalFormService('event_edit');
            $editService->setError('Endzeit muss nach der Startzeit liegen.', $formData);
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }
        $startsAt = $parsedStart->format('Y-m-d H:i:s');
        $endsAt   = $parsedEnd->format('Y-m-d H:i:s');

        try {
            $eventType = null;
            if ($eventTypeId) {
                $eventType = EventType::find($eventTypeId);
            }
            $typeName = $eventType ? $eventType->name : $event->type;

            if (empty($title)) {
                $title = $typeName;
            }

            $updateData = [
                'title' => $title,
                'event_type_id' => $eventTypeId,
                'type' => $typeName,
                'location' => trim($data['location'] ?? ''),
                'registration_enabled' => $registrationEnabled,
                'attendance_required' => $attendanceRequired,
            ];

            if ($updateSeries && $event->series_id) {
                if (Carbon::parse($event->starts_at)->format('Y-m-d') !== Carbon::parse($startsAt)->format('Y-m-d')) {
                    $editService = new ModalFormService('event_edit');
                    $editService->setError(
                        'Bei einer Serienänderung kann nur die Uhrzeit geändert werden, nicht das Datum.',
                        $formData
                    );
                    return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
                }

                $eventsToUpdate = Event::where('series_id', $event->series_id)
                    ->where('starts_at', '>=', $event->starts_at)
                    ->get();

                $hasUnauthorizedSeriesEvent = $eventsToUpdate->contains(function ($seriesEvent) {
                    return !$this->canAccessEvent($seriesEvent);
                });

                if ($hasUnauthorizedSeriesEvent) {
                    return $this->denyEventAccess(
                        $response,
                        'Die Serie enthält Termine, die du nicht bearbeiten darfst. '
                        . 'Es wurde nichts geändert.',
                        'event.update.series_forbidden',
                        (int) $event->id
                    );
                }

                $newStartTime = Carbon::parse($startsAt)->format('H:i');
                $newEndTime = Carbon::parse($endsAt)->format('H:i');

                // Ein absoluter Anmeldeschluss ergibt für eine ganze Serie keinen
                // Sinn - er würde alle Termine zum selben Zeitpunkt schließen.
                // Übernommen wird daher der Vorlauf zum jeweiligen Terminbeginn.
                $deadlineLeadSeconds = null;
                if ($registrationEnabled && $registrationDeadline !== null) {
                    $deadlineLeadSeconds = Carbon::parse($registrationDeadline)->getTimestamp()
                        - Carbon::parse($startsAt)->getTimestamp();
                }

                // Nur die gewählten Feldgruppen wandern auf die Folgetermine. Wer
                // einen einzelnen Termin bewusst anders angesetzt hat - eigener
                // Titel, eigener Ort, engere Zielgruppe - verliert das sonst mit
                // jeder Serienänderung, ohne es zu merken.
                foreach ($eventsToUpdate as $eventInSeries) {
                    $seriesUpdate = [];

                    if (in_array('title', $seriesFields, true)) {
                        $seriesUpdate['title'] = $updateData['title'];
                        $seriesUpdate['event_type_id'] = $updateData['event_type_id'];
                        $seriesUpdate['type'] = $updateData['type'];
                    }

                    if (in_array('location', $seriesFields, true)) {
                        $seriesUpdate['location'] = $updateData['location'];
                    }

                    if (in_array('attendance', $seriesFields, true)) {
                        $seriesUpdate['attendance_required'] = $updateData['attendance_required'];
                    }

                    $seriesStart = Carbon::parse($eventInSeries->starts_at)->setTimeFromTimeString($newStartTime);

                    if (in_array('time', $seriesFields, true)) {
                        $seriesUpdate['starts_at'] = $seriesStart;
                        $seriesUpdate['ends_at'] = Carbon::parse($eventInSeries->ends_at)
                            ->setTimeFromTimeString($newEndTime);
                    }

                    if (in_array('registration', $seriesFields, true)) {
                        $seriesUpdate['registration_enabled'] = $updateData['registration_enabled'];
                        // Der Vorlauf zählt ab dem Beginn, den dieser Termin nachher hat.
                        $deadlineBase = in_array('time', $seriesFields, true)
                            ? $seriesStart
                            : Carbon::parse($eventInSeries->starts_at);
                        $seriesUpdate['registration_deadline'] = $deadlineLeadSeconds === null
                            ? null
                            : (clone $deadlineBase)->addSeconds($deadlineLeadSeconds);
                    }

                    if ($seriesUpdate !== []) {
                        $eventInSeries->update($seriesUpdate);
                    }

                    if (in_array('audience', $seriesFields, true)) {
                        $audienceService->setSources($eventInSeries, $sources);
                    }
                }

                $_SESSION['success'] = 'Event-Serie (' . count($eventsToUpdate) . ' Termine) erfolgreich aktualisiert.';
            } else {
                $updateData['starts_at'] = $startsAt;
                $updateData['ends_at'] = $endsAt;
                $updateData['registration_deadline'] = $registrationDeadline;

                // Der Vergleich braucht die alten Werte, also vor dem Speichern.
                $changes = $this->describeScheduleChanges($event, $startsAt, $endsAt, $updateData['location'] ?? null);

                $event->update($updateData);
                $audienceService->setSources($event, $sources);

                if ($changes !== [] && $this->shouldNotifyMembers($data)) {
                    $this->notifyAudience(
                        $request,
                        NotificationType::EVENT_CHANGED,
                        [$event->fresh()],
                        'Termin geändert: ' . $event->title,
                        'emails/notification_event_changed.twig',
                        ['changes' => $changes]
                    );
                }

                $_SESSION['success'] = 'Event erfolgreich aktualisiert.';
            }
        } catch (Exception $e) {
            $editService = new ModalFormService('event_edit');
            $editService->setError('Fehler beim Aktualisieren: ' . $e->getMessage(), $formData);
            return $response->withHeader('Location', '/events/' . $id . '/edit')->withStatus(302);
        }

        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $event = Event::find($id);
        if ($event && $this->canAccessEvent($event)) {
            // Empfänger und Eckdaten stehen fest, bevor gelöscht wird: Die
            // Zielgruppen-Zeilen hängen am Termin und gehen mit ihm (CASCADE) -
            // danach wüsste niemand mehr, wer die Absage bekommen müsste.
            $cancelled = $event->replicate();
            $cancelled->id = $event->id;
            $recipients = $this->audienceUsersFor([$event]);

            $event->delete();

            $this->notifyResolvedAudience(
                $request,
                NotificationType::EVENT_CANCELLED,
                $recipients,
                [$cancelled],
                'Abgesagt: ' . $cancelled->title,
                'emails/notification_event_cancelled.twig'
            );

            $_SESSION['success'] = 'Termin gelöscht.';
        } elseif ($event) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.delete.forbidden',
                (int) $event->id
            );
        }
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    public function deleteSeries(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $event = Event::find($id);
        if ($event && !$this->canAccessEvent($event)) {
            return $this->denyEventAccess(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.delete_series.forbidden',
                (int) $event->id
            );
        }

        if ($event && $event->series_id) {
            $seriesId = $event->series_id;
            $eventsToDelete = Event::where('series_id', $seriesId)
                ->where('starts_at', '>=', $event->starts_at)
                ->get();

            $hasUnauthorizedSeriesEvent = $eventsToDelete->contains(function ($seriesEvent) {
                return !$this->canAccessEvent($seriesEvent);
            });

            if ($hasUnauthorizedSeriesEvent) {
                return $this->denyEventAccess(
                    $response,
                    'Die Serie enthält Termine, die du nicht löschen darfst. '
                    . 'Es wurde nichts gelöscht.',
                    'event.delete_series.series_forbidden',
                    (int) $event->id
                );
            }

            // Gelöscht wird ab dem angeklickten Termin - liegt der in der
            // Vergangenheit, trifft es auch vergangene Termine samt ihrer
            // Anwesenheits- und Anmeldedaten (ON DELETE CASCADE). Die Meldung
            // benennt deshalb den tatsächlichen Umfang.
            $deletedFrom = Carbon::parse($event->starts_at)->format('d.m.Y');
            $deletedCount = $eventsToDelete->count();

            // Wie beim Einzeltermin: erst auflösen, dann löschen.
            $cancelled = $eventsToDelete
                ->map(static function (Event $seriesEvent): Event {
                    $copy = $seriesEvent->replicate();
                    $copy->id = $seriesEvent->id;

                    return $copy;
                })
                ->values()
                ->all();
            $recipients = $this->audienceUsersFor($eventsToDelete->all());

            Event::whereIn('id', $eventsToDelete->pluck('id')->all())->delete();

            $this->notifyResolvedAudience(
                $request,
                NotificationType::EVENT_CANCELLED,
                $recipients,
                $cancelled,
                'Abgesagt: ' . $event->title,
                'emails/notification_event_cancelled.twig'
            );

            $_SESSION['success'] = sprintf(
                'Termine der Serie ab dem %s gelöscht (%d Termine, inklusive Anwesenheiten und Anmeldungen).',
                $deletedFrom,
                $deletedCount
            );
        }
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    private function canAccessEvent(Event $event): bool
    {
        // Terminverwalter muessen auch Termine ausserhalb ihrer eigenen Zielgruppe sehen,
        // sonst koennten sie genau die Termine nicht pflegen, fuer die sie zustaendig sind.
        if ($this->canManageEvents()) {
            return true;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        return (new EventAudienceService())->isUserEligible($event, $userId);
    }

    private function hydrateVisibleComments(EloquentCollection $events, int $userId): void
    {
        $eventIds = $events->pluck('id')->map(static fn($id) => (int) $id)->all();
        if ($eventIds === []) {
            return;
        }

        $comments = Comment::with('user')
            ->where('entity_type', 'event')
            ->whereIn('entity_id', $eventIds)
            ->visibleTo($userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('entity_id');

        foreach ($events as $event) {
            $event->setRelation('comments', $comments->get($event->id) ?? collect());
        }
    }

    private function getVisibleEventComments(int $eventId, int $userId)
    {
        return Comment::with('user')
            ->where('entity_type', 'event')
            ->where('entity_id', $eventId)
            ->visibleTo($userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->values();
    }

    private function findEventNote(int $eventId, int $noteId): ?Comment
    {
        return Comment::query()
            ->where('id', $noteId)
            ->where('entity_type', 'event')
            ->where('entity_id', $eventId)
            ->first();
    }

    private function canManageEventNote(Comment $note, Event $event): bool
    {
        if ($note->entity_type !== 'event') {
            return false;
        }

        if ((bool) $note->is_private) {
            return (int) $note->user_id === (int) ($_SESSION['user_id'] ?? 0);
        }

        return $this->canEditEvent($event);
    }

    private function canEditEvent(Event $event): bool
    {
        return $this->canManageEvents() && $this->canAccessEvent($event);
    }

    private function canManageEvents(): bool
    {
        return (bool) ($_SESSION['can_manage_events'] ?? false);
    }

    /**
     * Abweisung mit sichtbarer Begründung: Ein 403 mit Location-Header führt zu einer
     * leeren Seite, weil Browser nur 3xx-Weiterleitungen folgen - und ein 403 ganz
     * ohne Körper ebenfalls. Die Flash-Meldung bekam dabei nie eine Seite, auf der
     * sie erscheinen konnte.
     */
    private function denyEventAccess(
        Response $response,
        string $message,
        string $reason,
        ?int $eventId = null
    ): Response {
        $this->logger->info('Access denied.', [
            'event' => 'authz.denied',
            'reason' => $reason,
            'event_id' => $eventId,
        ]);

        return $this->view->render($response->withStatus(403), 'errors/403.twig', [
            'error' => $message,
        ]);
    }

    /**
     * Liefert den Takt einer Serie oder null, wenn der Wert nicht zu den vier
     * unterstützten Takten gehört. Ein fehlendes Feld gilt weiterhin als
     * "wöchentlich", ein gesetzter, aber unbekannter Wert nicht.
     */
    /**
     * Feldgruppen, die eine Serienänderung überträgt. Fehlt die Angabe ganz -
     * etwa aus einem älteren Formular -, gelten alle: das ist das bisherige
     * Verhalten und die einzige Auslegung, die keine Änderung verschluckt.
     *
     * @return list<string>
     */
    /**
     * Feldgruppen mit Beschriftung für das Formular.
     *
     * @return list<array{key: string, label: string}>
     */
    private static function seriesFieldGroupOptions(): array
    {
        $options = [];
        foreach (self::SERIES_FIELD_GROUPS as $group) {
            $options[] = ['key' => $group, 'label' => self::SERIES_FIELD_GROUP_LABELS[$group]];
        }

        return $options;
    }

    private static function normalizeSeriesFieldGroups(mixed $value): array
    {
        if (!is_array($value)) {
            return self::SERIES_FIELD_GROUPS;
        }

        $selected = [];
        foreach ($value as $candidate) {
            $group = strtolower(trim((string) $candidate));
            if (in_array($group, self::SERIES_FIELD_GROUPS, true)) {
                $selected[$group] = true;
            }
        }

        return array_values(array_keys($selected));
    }

    private static function normalizeSeriesFrequency(mixed $value): ?string
    {
        if ($value === null) {
            return 'weekly';
        }

        $candidate = strtolower(trim((string) $value));

        return in_array($candidate, self::SERIES_FREQUENCIES, true) ? $candidate : null;
    }

    /**
     * Liefert das Wiederholungsintervall oder null bei einer ungültigen Angabe.
     * Ein leeres Feld bleibt beim bisherigen Standardwert 1; 0 oder weniger ist
     * kein Intervall, sondern ein Stillstand der Erzeugungsschleife.
     */
    private static function normalizeRecurrenceInterval(mixed $value): ?int
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === '') {
            return 1;
        }

        return ctype_digit($candidate) && (int) $candidate >= 1 ? (int) $candidate : null;
    }

    /**
     * Reduziert die gewählten Wochentage auf gültige ISO-Nummern (1 = Mo bis 7 = So).
     * Ein unbekannter Wert würde sonst als Text in der Serie landen und auf keinen
     * Kalendertag passen.
     *
     * @param array<array-key, mixed> $value
     * @return list<int>
     */
    private static function normalizeWeekdays(array $value): array
    {
        $weekdays = [];
        foreach ($value as $day) {
            $candidate = trim((string) $day);
            if (ctype_digit($candidate) && (int) $candidate >= 1 && (int) $candidate <= 7) {
                $weekdays[] = (int) $candidate;
            }
        }

        return array_values(array_unique($weekdays));
    }
}
