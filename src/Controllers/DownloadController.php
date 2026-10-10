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

    /**
     * MIME-Typen, die der MIDI-Abspieler bekommt. Die Seite lädt dessen
     * Skripte nur, wenn mindestens eine solche Datei darauf steht.
     */
    private const MIDI_MIME_TYPES = ['audio/midi', 'audio/x-midi', 'application/x-midi'];

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

    /**
     * Teilt Projekte in laufende und vergangene. Vergangen ist, was ein Enddatum
     * vor `$today` hat; ohne Enddatum oder mit Ende heute gilt es als laufend.
     * Die Reihenfolge der Eingabe bleibt in beiden Gruppen erhalten.
     *
     * @param iterable<Project> $projects
     * @param string $today Datum als Y-m-d
     * @return array{current: list<Project>, past: list<Project>}
     */
    public static function splitByEnd(iterable $projects, string $today): array
    {
        $current = [];
        $past = [];

        foreach ($projects as $project) {
            $end = $project->end_date;
            if ($end !== null && $end->format('Y-m-d') < $today) {
                $past[] = $project;
            } else {
                $current[] = $project;
            }
        }

        return ['current' => $current, 'past' => $past];
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            return $this->view->render($response, 'songs/downloads.twig', [
                'current_projects' => [],
                'past_projects' => [],
                'has_midi' => false,
                'midi_mime_types' => self::MIDI_MIME_TYPES,
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

        $groups = self::splitByEnd($projects, date('Y-m-d'));

        return $this->view->render($response, 'songs/downloads.twig', [
            'current_projects' => $groups['current'],
            'past_projects' => $groups['past'],
            'has_midi' => $this->hasMidi($projects),
            'midi_mime_types' => self::MIDI_MIME_TYPES,
            'active_nav' => 'downloads',
            'webdav' => $this->webdavState($request, $userId),
        ]);
    }

    /**
     * @param iterable<Project> $projects
     */
    private function hasMidi(iterable $projects): bool
    {
        foreach ($projects as $project) {
            foreach ($project->assignedSongs as $song) {
                foreach ($song->attachments as $attachment) {
                    if (in_array(strtolower((string) $attachment->mime_type), self::MIDI_MIME_TYPES, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Erzeugt ein neues Zugangstoken für den Noten-Ordner und verwirft das alte.
     *
     * Das erste Erzeugen braucht keine Rückfrage. Gibt es schon einen Zugang,
     * fragt die Seite vorher nach (data-confirm am Formular): Das neue Token
     * sperrt jedes Gerät aus, auf dem noch das bisherige steht, und das lässt
     * sich nicht zurücknehmen.
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
