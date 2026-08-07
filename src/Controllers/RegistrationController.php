<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceScopeService;
use App\Services\EventAudienceService;
use App\Services\NameFormatterService;
use App\Util\VoiceGroupOrder;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as Capsule;

class RegistrationController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AttendanceScopeService $scopeService,
        private readonly LoggerInterface $logger,
        private readonly NameFormatterService $nameFormatter
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        // Only events the user is actually part of (audience scope) are
        // relevant for self-registration.
        $events = (new EventAudienceService())->visibleEventsQuery($userId)
            ->where('registration_enabled', true)
            ->where('starts_at', '>', Carbon::now())
            ->orderBy('starts_at', 'asc')
            ->with(['registrations' => fn($q) => $q->where('user_id', $userId)])
            ->get();

        $rows = [];
        foreach ($events as $event) {
            $own = $event->registrations->first();
            $statusCounts = $this->eligibleStatusCounts($event);

            $rows[] = [
                'event' => $event,
                'own_status' => $own?->status,
                'open' => $event->isRegistrationOpen(),
                'eligible_count' => $statusCounts['eligible_count'],
                'yes_count' => $statusCounts['yes'],
                'no_count' => $statusCounts['no'],
                'maybe_count' => $statusCounts['maybe'],
                'open_count' => $statusCounts['open'],
            ];
        }

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'registrations/index.twig', [
            'rows' => $rows,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $event = $this->findRegistrationEvent((int) $args['event_id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden oder Anmeldung nicht freigeschaltet.';
            return $response->withHeader('Location', '/registrations')->withStatus(302);
        }

        // Die Anmeldeliste zeigt Namen, Status und Notizen aller Betroffenen - sie ist nur
        // fuer Mitglieder der Zielgruppe und fuer deren Verwalter bestimmt.
        if (!$this->scopeService->canAccessEvent($event)) {
            $_SESSION['error'] = 'Du gehörst nicht zur Zielgruppe dieses Termins.';
            return $response->withHeader('Location', '/registrations')->withStatus(403);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $manageableIds = $this->scopeService->getManageableUserIds();
        $users = $this->eligibleUsers($event);

        $voiceGroups = [];
        $counts = ['yes' => 0, 'no' => 0, 'maybe' => 0, 'open' => 0];
        $ownRegistration = null;

        foreach ($users as $user) {
            $registration = $user->eventRegistrations->first();
            $status = $registration?->status;
            $counts[$status ?? 'open']++;

            if ((int) $user->id === $userId) {
                $ownRegistration = $registration;
            }

            $voiceGroup = $user->voiceGroups->first();
            $groupName = $voiceGroup ? $voiceGroup->name : 'Ohne Stimmgruppe';

            $voiceGroups[$groupName][] = [
                'user_id' => (int) $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'status' => $status,
                'note' => $registration?->note,
                'updated_by_name' => $this->proxyName($registration),
                'editable' => in_array((int) $user->id, $manageableIds, true),
            ];
        }

        $voiceGroups = VoiceGroupOrder::sortNameKeyedMap($voiceGroups, ['Ohne Stimmgruppe']);

        $total = $users->count();
        $answered = $total - $counts['open'];

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'registrations/detail.twig', [
            'event' => $event,
            'voice_groups' => $voiceGroups,
            'own_registration' => $ownRegistration,
            'counts' => $counts,
            'total_eligible' => $total,
            'answered' => $answered,
            'response_rate' => $total > 0 ? (int) round($answered * 100 / $total) : 0,
            'registration_open' => $event->isRegistrationOpen(),
            'can_manage_others' => $this->scopeService->canManageOthers(),
            'success' => $success,
            'error' => $error
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $expectsJson = $this->expectsJson($request);
        $event = $this->findRegistrationEvent((int) $args['event_id']);
        if (!$event) {
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Termin nicht gefunden.'], 404);
            }
            $_SESSION['error'] = 'Termin nicht gefunden oder Anmeldung nicht freigeschaltet.';
            return $response->withHeader('Location', '/registrations')->withStatus(302);
        }

        if (!$event->isRegistrationOpen()) {
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Der Anmeldeschluss ist vorbei.'], 403);
            }
            $_SESSION['error'] = 'Der Anmeldeschluss für diesen Termin ist vorbei.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(403);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);

        // Self-registration is only allowed for members within the event's
        // audience scope — not for every otherwise authorised member.
        if (!(new EventAudienceService())->isUserEligible($event, $userId)) {
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Du bist für diesen Termin nicht angemeldet.'], 403);
            }
            $_SESSION['error'] = 'Du gehörst nicht zur Zielgruppe dieses Termins.';
            return $response
                ->withHeader('Location', '/registrations')
                ->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $status = (string) ($data['status'] ?? '');
        $note = trim((string) ($data['note'] ?? ''));

        if (!in_array($status, EventRegistration::STATUSES, true)) {
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Ungültiger Anmeldestatus.'], 422);
            }
            $_SESSION['error'] = 'Ungültiger Anmeldestatus.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(302);
        }

        try {
            EventRegistration::updateOrCreate(
                ['event_id' => (int) $event->id, 'user_id' => $userId],
                ['status' => $status, 'note' => $note !== '' ? $note : null, 'updated_by' => $userId]
            );
            $_SESSION['success'] = 'Anmeldung gespeichert.';
        } catch (\Exception $e) {
            $this->logger->error('Saving event registration failed.', [
                'event' => 'registration.save_failed',
                'event_id' => (int) $event->id,
                'user_id' => $userId,
                'exception' => $e,
            ]);

            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Fehler beim Speichern der Anmeldung.'], 500);
            }
            $_SESSION['error'] = 'Fehler beim Speichern der Anmeldung.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(302);
        }

        if ($expectsJson) {
            $counts = $this->eligibleStatusCounts($event);
            return $this->jsonResponse($response, [
                'success' => true,
                'own_status' => $status,
                'counts' => [
                    'yes' => $counts['yes'],
                    'no' => $counts['no'],
                    'maybe' => $counts['maybe'],
                    'open' => $counts['open'],
                ],
            ]);
        }

        return $response
            ->withHeader('Location', '/registrations/' . $event->id)
            ->withStatus(302);
    }

    private function expectsJson(Request $request): bool
    {
        if (strtolower(trim($request->getHeaderLine('X-Requested-With'))) === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    public function saveProxy(Request $request, Response $response, array $args): Response
    {
        $event = $this->findRegistrationEvent((int) $args['event_id']);
        if (!$event) {
            $_SESSION['error'] = 'Termin nicht gefunden oder Anmeldung nicht freigeschaltet.';
            return $response->withHeader('Location', '/registrations')->withStatus(302);
        }

        if (!$event->isRegistrationOpen()) {
            $_SESSION['error'] = 'Der Anmeldeschluss für diesen Termin ist vorbei.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(403);
        }

        if (!$this->scopeService->canManageOthers() || !$this->scopeService->canAccessEvent($event)) {
            $_SESSION['error'] = 'Zugriff verweigert: Keine Berechtigung für Vertretungseinträge.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $registrations = (array) ($data['registration'] ?? []);
        $notes = (array) ($data['note'] ?? []);

        // Vertretungseintraege sind doppelt begrenzt: auf die verwaltbaren Mitglieder und
        // auf die Zielgruppe des Termins - sonst entstuenden Anmeldungen fuer Unbeteiligte.
        $eligibleUserIds = $event->eligibleUsersQuery()
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();
        $allowedUserIds = array_intersect($this->scopeService->getManageableUserIds(), $eligibleUserIds);
        $submittedUserIds = array_values(array_unique(array_map('intval', array_keys($registrations))));
        $unauthorized = array_diff($submittedUserIds, $allowedUserIds);

        if (!empty($unauthorized)) {
            $_SESSION['error'] = 'Zugriff verweigert: Unzulässige Personen im Vertretungsformular.';
            return $response
                ->withHeader('Location', '/registrations/' . $event->id)
                ->withStatus(403);
        }

        $actorId = (int) ($_SESSION['user_id'] ?? 0);

        Capsule::beginTransaction();

        try {
            foreach ($registrations as $rawUserId => $status) {
                $targetUserId = (int) $rawUserId;
                $status = (string) $status;

                if ($status === '' || !in_array($status, EventRegistration::STATUSES, true)) {
                    continue;
                }

                $note = trim((string) ($notes[$rawUserId] ?? ''));

                EventRegistration::updateOrCreate(
                    ['event_id' => (int) $event->id, 'user_id' => $targetUserId],
                    ['status' => $status, 'note' => $note !== '' ? $note : null, 'updated_by' => $actorId]
                );
            }

            Capsule::commit();
            $_SESSION['success'] = 'Vertretungseinträge gespeichert.';
        } catch (\Exception $e) {
            Capsule::rollBack();
            $this->logger->error('Saving proxy registrations failed.', [
                'event' => 'registration.proxy_save_failed',
                'event_id' => (int) $event->id,
                'actor_id' => $actorId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Fehler beim Speichern der Vertretungseinträge.';
        }

        return $response
            ->withHeader('Location', '/registrations/' . $event->id)
            ->withStatus(302);
    }

    private function findRegistrationEvent(int $eventId): ?Event
    {
        $event = Event::find($eventId);
        if (!$event || !(bool) $event->registration_enabled) {
            return null;
        }

        return $event;
    }

    /**
     * Yes/no/maybe/open registration counts scoped to the same eligible
     * population as eligibleUsers()/eligibleUserCount() — active users,
     * restricted to project members for project-bound events. Uses lean
     * lookup queries instead of eager-loading full user models, so it is
     * safe to call once per listed event.
     *
     * @return array{eligible_count: int, yes: int, no: int, maybe: int, open: int}
     */
    private function eligibleStatusCounts(Event $event): array
    {
        $counts = ['yes' => 0, 'no' => 0, 'maybe' => 0];

        $eligibleUserIds = $event->eligibleUsersQuery()->pluck('id');

        $registrations = EventRegistration::where('event_id', (int) $event->id)
            ->whereIn('status', EventRegistration::STATUSES)
            ->whereHas('user', function ($query) use ($eligibleUserIds) {
                $query->whereIn('id', $eligibleUserIds);
            })
            ->get(['status']);

        foreach ($registrations as $registration) {
            $counts[$registration->status]++;
        }

        $eligibleCount = $this->eligibleUserCount($event);
        $answered = $counts['yes'] + $counts['no'] + $counts['maybe'];

        return [
            'eligible_count' => $eligibleCount,
            'yes' => $counts['yes'],
            'no' => $counts['no'],
            'maybe' => $counts['maybe'],
            'open' => max(0, $eligibleCount - $answered),
        ];
    }

    /**
     * Lean count of active users eligible for this event (project members
     * for project-bound events, otherwise all active users) — mirrors the
     * filtering of eligibleUsers() without eager-loading relations, so it
     * is safe to call once per listed event.
     */
    private function eligibleUserCount(Event $event): int
    {
        return $event->eligibleUsersQuery()->count();
    }

    /**
     * Active users eligible for this event: project members for
     * project-bound events, otherwise all active users.
     */
    private function eligibleUsers(Event $event)
    {
        return $event->eligibleUsersQuery()
            ->with([
                'voiceGroups',
                'eventRegistrations' => fn($q) => $q->where('event_id', (int) $event->id),
            ])
            ->get()
            ->sortBy($this->nameFormatter->orderColumns())
            ->values();
    }

    private function proxyName(?EventRegistration $registration): ?string
    {
        if (!$registration || !$registration->updated_by) {
            return null;
        }

        if ((int) $registration->updated_by === (int) $registration->user_id) {
            return null;
        }

        $updatedBy = User::find($registration->updated_by);

        return $updatedBy ? $this->nameFormatter->formatPerson($updatedBy) : null;
    }
}
