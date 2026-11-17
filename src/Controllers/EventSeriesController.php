<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Event;
use App\Policies\EventPolicy;
use App\Services\EntityCleanupService;
use App\Services\EventAudienceNotifier;
use App\Services\EventWriteService;
use App\Util\AppUrlResolver;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Die Terminserie als Ganzes - heute: ihre Löschung ab einem Termin.
 *
 * Herausgezogen aus dem EventController mit der Antwort auf Review-Lauf 77
 * (Frage 2b). Der Controller war mit 1813 Zeilen die größte Datei des Projekts,
 * und die Serie ist der Teil darin, der eine eigene Entscheidung trifft: Sie
 * wirkt auf alle Termine ab dem angeklickten, prüft das Recht für jeden
 * einzelnen davon und sagt am Ende, wie viele es waren.
 *
 * Was bewusst beim EventController geblieben ist: das Anlegen und das Ändern.
 * Beide kommen aus demselben Formular wie der Einzeltermin und entscheiden erst
 * an einem Feld (`repeat` bzw. `series_scope`), ob eine Serie gemeint ist - eine
 * eigene Route dafür hieße, das Formular sein Ziel wechseln zu lassen. Die
 * Regeln ihrer Eingabe stehen trotzdem beisammen: in `App\Util\EventSeriesInput`.
 */
class EventSeriesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly EventPolicy $policy,
        private readonly EventAudienceNotifier $notifier,
        private readonly EventWriteService $eventWriter,
        private readonly EntityCleanupService $cleanup,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Löscht die Termine der Serie ab dem angeklickten Termin.
     *
     * Erst wird geprüft, dann aufgelöst, dann gelöscht, dann abgesagt: Die
     * Zielgruppen-Zeilen hängen an den Terminen und gehen mit ihnen (CASCADE) -
     * danach wüsste niemand mehr, wer die Absage bekommen müsste.
     *
     * @param array{id: string} $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $event = Event::find($args['id']);
        if ($event === null) {
            return $this->back($response);
        }

        if (!$this->policy->canAccessEvent($event)) {
            return $this->deny(
                $response,
                'Du gehörst nicht zur Zielgruppe dieses Termins.',
                'event.delete_series.forbidden',
                (int) $event->id
            );
        }

        if (!$event->series_id) {
            return $this->back($response);
        }

        $eventsToDelete = Event::where('series_id', $event->series_id)
            ->where('starts_at', '>=', $event->starts_at)
            ->get();

        $hasUnauthorizedSeriesEvent = $eventsToDelete->contains(
            fn (Event $seriesEvent): bool => !$this->policy->canAccessEvent($seriesEvent)
        );
        if ($hasUnauthorizedSeriesEvent) {
            return $this->deny(
                $response,
                'Die Serie enthält Termine, die du nicht löschen darfst. '
                . 'Es wurde nichts gelöscht.',
                'event.delete_series.series_forbidden',
                (int) $event->id
            );
        }

        // Liegt der angeklickte Termin in der Vergangenheit, trifft es auch
        // vergangene Termine samt ihrer Anwesenheits- und Anmeldedaten. Die
        // Meldung benennt deshalb den tatsächlichen Umfang.
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
        $recipients = $this->notifier->recipientsFor($eventsToDelete->all());

        $this->eventWriter->deleteSeriesEvents($eventsToDelete->all(), $this->cleanup);

        $this->notifier->notifyCancellation(
            $recipients,
            $cancelled,
            (string) $event->title,
            AppUrlResolver::resolveBaseUrl($request),
            $this->policy->currentUserId() ?: null
        );

        $_SESSION['success'] = sprintf(
            'Termine der Serie ab dem %s gelöscht (%d Termine, inklusive Anwesenheiten und Anmeldungen).',
            $deletedFrom,
            $deletedCount
        );

        return $this->back($response);
    }

    private function back(Response $response): Response
    {
        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    /**
     * Abweisung mit sichtbarer Begründung: Ein 403 mit Location-Header führt zu einer
     * leeren Seite, weil Browser nur 3xx-Weiterleitungen folgen - und ein 403 ganz
     * ohne Körper ebenfalls. Die Flash-Meldung bekam dabei nie eine Seite, auf der
     * sie erscheinen konnte.
     */
    private function deny(Response $response, string $message, string $reason, int $eventId): Response
    {
        $this->logger->info('Access denied.', [
            'event' => 'authz.denied',
            'reason' => $reason,
            'event_id' => $eventId,
        ]);

        return $this->view->render($response->withStatus(403), 'errors/403.twig', [
            'error' => $message,
        ]);
    }
}
