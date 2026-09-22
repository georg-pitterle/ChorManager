<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\WebdavAccessService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use App\Util\UploadValidator;

class DownloadController
{
    /**
     * Der Klartext des WebDAV-Tokens liegt genau einen Seitenaufruf lang in der
     * Sitzung - gespeichert ist nur sein Hash. Gleiche Bauart wie beim
     * Kalender-Abo (EventController::SUBSCRIPTION_FLASH_KEY).
     */
    private const WEBDAV_FLASH_KEY = 'webdav_access_token';

    private Twig $view;
    private WebdavAccessService $webdav;
    private LoggerInterface $logger;

    public function __construct(Twig $view, WebdavAccessService $webdav, LoggerInterface $logger)
    {
        $this->view = $view;
        $this->webdav = $webdav;
        $this->logger = $logger;
    }

    /**
     * Was der Abspieler auf der Download-Seite abspielen darf, ist genau das,
     * was der Upload durchlässt. Eine zweite Liste an dieser Stelle war der
     * Grund, warum der Abspieler ins Leere lief: Sie führte MP3 und MIDI, der
     * UploadValidator kannte kein einziges Audioformat.
     *
     * @return list<string>
     */
    public static function streamableMimeTypes(): array
    {
        return UploadValidator::getAudioMimeTypes();
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            return $this->view->render($response, 'songs/downloads.twig', [
                'projects' => [],
                'active_nav' => 'downloads',
                'webdav' => null,
            ]);
        }

        $projects = Project::query()
            ->select('projects.*')
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->with([
                'assignedSongs' => function ($query) {
                    $query->orderBy('title', 'asc');
                },
                'assignedSongs.attachments' => function ($query) {
                    $query->orderBy('original_name', 'asc');
                },
                'assignedSongs.linkResources' => function ($query) {
                    $query->where('resource_type', 'link')->orderBy('title', 'asc');
                }
            ])
            ->distinct()
            ->chronological()
            ->get();

        return $this->view->render($response, 'songs/downloads.twig', [
            'projects' => $projects,
            'active_nav' => 'downloads',
            'webdav' => $this->webdavState($request, $userId),
        ]);
    }

    /**
     * Erzeugt ein neues Zugangstoken für den Noten-Ordner und verwirft das alte.
     *
     * Bewusst ohne Rückfrage: Wer den Knopf drückt, hat entweder noch keinen
     * Zugang oder will den alten loswerden. Dass dabei jedes Gerät mit dem
     * bisherigen Token aussteigt, steht an der Schaltfläche.
     */
    public function rotateWebdavToken(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $replaced = $this->webdav->hasTokenForUser($userId);
        $_SESSION[self::WEBDAV_FLASH_KEY] = $this->webdav->rotateTokenForUser($userId);

        // Ohne Token im Kontext: Er ist das Geheimnis selbst und hat in keinem
        // Log etwas verloren.
        $this->logger->info('WebDAV-Zugang für den Noten-Ordner erzeugt.', [
            'event' => 'webdav.token.issued',
            'user_id' => $userId,
            'replaced_existing' => $replaced,
        ]);

        $_SESSION['success'] = $replaced
            ? 'Neues Zugangswort erzeugt. Geräte mit dem bisherigen kommen nicht mehr an die Noten.'
            : 'Zugangswort erzeugt.';

        return $response->withHeader('Location', '/downloads')->withStatus(302);
    }

    /**
     * Anzeigezustand des Noten-Ordners.
     *
     * `token` ist nur direkt nach dem Erzeugen gesetzt; danach steht in der
     * Datenbank nur der Hash, und die Seite meldet lediglich, dass ein Zugang
     * besteht.
     *
     * @return array{url: string, user: string, exists: bool, token: string|null}
     */
    private function webdavState(Request $request, int $userId): array
    {
        $fresh = $_SESSION[self::WEBDAV_FLASH_KEY] ?? null;
        unset($_SESSION[self::WEBDAV_FLASH_KEY]);

        return [
            'url' => WebdavController::baseUrl($request),
            'user' => (string) (User::where('id', $userId)->value('email') ?? ''),
            'exists' => $this->webdav->hasTokenForUser($userId),
            'token' => is_string($fresh) && $fresh !== '' ? $fresh : null,
        ];
    }
}
