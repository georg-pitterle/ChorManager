<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserNotification;
use App\Services\Notifications\InAppNotificationStore;
use App\Util\NotificationType;
use App\Util\RequestFormat;
use App\Util\SafeRedirect;
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

    /** Ziel, wenn der Eintrag keinen brauchbaren eigenen Pfad trägt. */
    private const FALLBACK_TARGET = '/notifications';

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
        $userId = $this->userId();

        $result = $this->store->paginate($userId, $page, $onlyUnread);

        return $this->view->render($response, 'notifications/index.twig', [
            'notifications' => array_map(
                fn (UserNotification $notification): array => $this->present($notification),
                $result['items']->all()
            ),
            'page' => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
            'only_unread' => $onlyUnread,
            'unread_count' => $this->store->unreadCount($userId),
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
                fn (UserNotification $notification): array => $this->present($notification),
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

        return $response
            ->withHeader('Location', $this->ownTarget($notification->link))
            ->withStatus(302);
    }

    /**
     * Das Ziel eines Eintrags, auf die eigene Anwendung begrenzt.
     *
     * Der Link entsteht heute ausschließlich in der Anwendung selbst, es gibt
     * also keinen bekannten Weg, über den ein fremdes Ziel in die Spalte käme.
     * Genau darauf beruhte der Schutz aber: auf einer Eigenschaft der
     * Schreibstellen, nicht auf einer Prüfung an der Lesestelle. Ein künftiger
     * Anlass, dessen Ziel aus Daten entsteht, hätte daraus eine offene
     * Weiterleitung gemacht, ohne dass hier etwas aufgefallen wäre.
     *
     * Der Wert wird deshalb auf Pfad, Abfrage und Anker reduziert und erst dann
     * geprüft. Die Reduktion ist nötig und nicht nur Vorsicht: Die Dienste im
     * Betrieb speichern eine vollständige Adresse aus der Basis-Adresse, und die
     * enthält "://" - `SafeRedirect::sanitize()` allein würde jeden dieser Links
     * abweisen und die Glocke unbenutzbar machen. Ein Rechnername im Wert fällt
     * bei der Reduktion weg, womit ein fremdes Ziel zum eigenen Pfad wird.
     *
     * Bleibt kein brauchbarer Pfad übrig, führt der Eintrag zurück zur Liste -
     * gelesen ist er dann trotzdem, so wie beim Klick auf einen Eintrag ohne
     * eigenes Ziel.
     */
    private function ownTarget(?string $link): string
    {
        $raw = (string) $link;
        $parts = parse_url($raw);
        if ($parts === false) {
            return self::FALLBACK_TARGET;
        }

        $target = (string) ($parts['path'] ?? '');
        if (($parts['query'] ?? '') !== '') {
            $target .= '?' . $parts['query'];
        }
        if (($parts['fragment'] ?? '') !== '') {
            $target .= '#' . $parts['fragment'];
        }

        return SafeRedirect::sanitize($target) ?? self::FALLBACK_TARGET;
    }

    public function readAll(Request $request, Response $response): Response
    {
        $this->store->markAllRead($this->userId());

        if (RequestFormat::expectsJson($request)) {
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
            // Ohne no-store beantwortet der Browser die nächste Abfrage aus seiner
            // eigenen Kopie - also mit genau dem veralteten Zähler.
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(200);
    }

    private function userId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }
}
