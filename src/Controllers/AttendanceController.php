<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Event;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Database\Capsule\Manager as Capsule;

class AttendanceController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $eventId = isset($args['event_id']) ? (int)$args['event_id'] : null;

        $events = Event::orderBy('event_date', 'desc')->get();

        if (!$eventId && $events->count() > 0) {
            $eventId = $events->first()->id;
        }

        $event = null;
        $voiceGroups = [];

        if ($eventId) {
            $event = Event::find($eventId);

            if ($event) {
                $canManageUsers = $_SESSION['can_manage_users'] ?? false;
                $userVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];
                $roleLevel = $_SESSION['role_level'] ?? 0;

                // Same logic as before: If not admin/board, restrict to own voice groups
                if (!$canManageUsers && $roleLevel < 80) {
                    if (!empty($userVoiceGroupIds)) {
                        $users = User::whereHas('voiceGroups', function ($q) use ($userVoiceGroupIds) {
                            $q->whereIn('voice_group_id', $userVoiceGroupIds);
                        });
                    } else {
                        // Edge case: no voice group assigned but is a stimmsprecher
                        $users = User::whereRaw('1 = 0'); // show nothing
                    }
                } else {
                    $users = User::query();
                }

                $users = $users->where('is_active', 1)
                    ->with(['voiceGroups', 'subVoices.voiceGroup', 'attendances' => function ($q) use ($eventId) {
                        $q->where('event_id', $eventId);
                    }])
                    ->get()
                    ->sortBy(['last_name', 'first_name']);

                foreach ($users as $u) {
                    $vgName = 'Ohne Stimmgruppe';

                    $voiceGroup = $u->voiceGroups->first();
                    if ($voiceGroup) {
                        $vgName = $voiceGroup->name;
                    }

                    if (!isset($voiceGroups[$vgName])) {
                        $voiceGroups[$vgName] = [];
                    }

                    $attendance = $u->attendances->first();
                    $status = $attendance ? $attendance->status : 'unbekannt';
                    $note = $attendance ? $attendance->note : null;

                    $svName = null;
                    if ($voiceGroup && $voiceGroup->pivot->sub_voice_id) {
                        $subVoice = $u->subVoices->firstWhere('id', $voiceGroup->pivot->sub_voice_id);
                        if ($subVoice) {
                            $svName = $subVoice->name;
                        }
                    }

                    $voiceGroups[$vgName][] = [
                        'user_id' => $u->id,
                        'first_name' => $u->first_name,
                        'last_name' => $u->last_name,
                        'voice_group_name' => $vgName !== 'Ohne Stimmgruppe' ? $vgName : null,
                        'sub_voice_name' => $svName,
                        'status' => $status,
                        'note' => $note
                    ];
                }

                ksort($voiceGroups);
                if (isset($voiceGroups['Ohne Stimmgruppe'])) {
                    $ungrouped = $voiceGroups['Ohne Stimmgruppe'];
                    unset($voiceGroups['Ohne Stimmgruppe']);
                    $voiceGroups['Ohne Stimmgruppe'] = $ungrouped;
                }
            }
        }

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'attendance/show.twig', [
            'events' => $events,
            'current_event' => $event,
            'voice_groups' => $voiceGroups,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $eventId = (int)$args['event_id'];
        $data = (array)$request->getParsedBody();
        $attendances = $data['attendance'] ?? [];
        $notes = $data['note'] ?? [];

        Capsule::beginTransaction();

        try {
            foreach ($attendances as $userId => $status) {
                if (!in_array($status, ['present', 'excused', 'unexcused'])) {
                    continue;
                }

                $note = trim($notes[$userId] ?? '');

                Attendance::updateOrCreate(
                    ['event_id' => $eventId, 'user_id' => $userId],
                    ['status' => $status, 'note' => $note]
                );
            }

            Capsule::commit();
            $_SESSION['success'] = 'Anwesenheiten erfolgreich gespeichert.';
        } catch (\Exception $e) {
            Capsule::rollBack();
            $_SESSION['error'] = 'Fehler beim Speichern aufgetreten: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/attendance/' . $eventId)->withStatus(302);
    }
}
