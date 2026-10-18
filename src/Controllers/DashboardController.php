<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Event;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\Project;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Services\EventAudienceService;
use App\Services\EventScheduleFormatter;
use App\Services\NameFormatterService;
use App\Services\NewsletterPlaceholderService;
use App\Services\Storage\StorageUsageService;
use App\Util\AppUrlResolver;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;

class DashboardController
{
    private Twig $view;
    private \App\Services\MailQueueAdminService $mailQueueAdminService;
    private TaskPolicy $taskPolicy;
    private array $settings;

    public function __construct(
        Twig $view,
        \App\Services\MailQueueAdminService $mailQueueAdminService,
        TaskPolicy $taskPolicy,
        array $settings = [],
        private readonly ?StorageUsageService $storageUsage = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->view = $view;
        $this->mailQueueAdminService = $mailQueueAdminService;
        $this->taskPolicy = $taskPolicy;
        $this->settings = $settings;
    }

    public function index(Request $request, Response $response): Response
    {
        $today = date('Y-m-d');
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        // Eine Quelle für das Aufgaben-Recht: die Policy. Der Sitzungsschlüssel
        // wurde hier zuvor ein zweites Mal selbst gelesen.
        $canManageTasks = $this->taskPolicy->canManageTasks();
        $tasksModuleEnabled = (bool) ($this->settings['modules']['tasks'] ?? false);
        $newsletterModuleEnabled = (bool) ($this->settings['modules']['newsletter'] ?? false);
        $canViewNewsletterArea = $newsletterModuleEnabled
            && (bool) ($_SESSION['can_manage_newsletters'] ?? false);

        $currentProject = null;
        $upcomingProject = null;

        if ($tasksModuleEnabled && $canManageTasks) {
            $currentProject = Project::where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->orderBy('end_date', 'asc')
                ->first();

            $upcomingProject = Project::where('start_date', '>', $today)
                ->orderBy('start_date', 'asc')
                ->first();
        }

        $latestSentNewsletter = null;
        $deadMailCount = null;

        if ($canViewNewsletterArea && $userId > 0) {
            // Der Zugang zur Kachel hängt allein am Recht can_manage_newsletters,
            // nicht an der Projektmitgliedschaft – ein weiterer Filter auf
            // erreichbare Projekte würde projektlose Newsletter grundsätzlich
            // ausblenden, da whereIn() keine NULL-Werte trifft.
            $latestSentNewsletter = Newsletter::query()
                ->where('status', Newsletter::STATUS_SENT)
                ->with(['project', 'recipientSources'])
                ->orderBy('sent_at', 'desc')
                ->orderBy('created_at', 'desc')
                ->first();
        }

        // Wer den Newsletter nicht verwalten darf, liest ihn: der neueste, der ihm selbst
        // zugestellt wurde. Fremde Newsletter erscheinen nie.
        if ($latestSentNewsletter === null && $newsletterModuleEnabled && $userId > 0) {
            $latestSentNewsletter = NewsletterArchive::query()
                ->where('user_id', $userId)
                ->whereNotNull('sent_at')
                ->with('newsletter.project')
                ->orderBy('sent_at', 'desc')
                ->orderBy('id', 'desc')
                ->first()
                ?->newsletter;
        }

        $latestNewsletterSubject = $latestSentNewsletter === null
            ? null
            : $this->renderedSubject($request, $latestSentNewsletter, $userId);
        // Der Name kommt von hier, nicht aus dem Template: Twig liefert bei einem Newsletter
        // ohne Projekt das Relationsobjekt statt null, und das gilt dort als wahr.
        $latestNewsletterProject = $latestSentNewsletter?->project?->name;

        $draftCount = null;
        if ($canViewNewsletterArea) {
            $draftCount = Newsletter::where('status', Newsletter::STATUS_DRAFT)->count();
        }

        if ((bool) ($_SESSION['can_manage_mail_queue'] ?? false)) {
            $deadMailCount = $this->mailQueueAdminService->countDeadLetters();
        }

        $registrationModuleEnabled = (bool) ($this->settings['modules']['registration'] ?? false);
        $registrationSummary = null;
        if ($registrationModuleEnabled && $userId > 0) {
            $registrationSummary = (new \App\Services\PendingRegistrationSummaryService())->forUser($userId);
        }

        $upcomingEvents = $userId > 0 ? $this->upcomingEvents($userId, $registrationModuleEnabled) : [];
        $todaysEvent = null;
        foreach ($upcomingEvents as $row) {
            if (Carbon::parse($row->event->starts_at)->isToday()) {
                $todaysEvent = $row->event;
                break;
            }
        }

        $memberCount = null;
        if ((bool) ($_SESSION['can_manage_users'] ?? false)) {
            $memberCount = User::where('is_active', 1)->count();
        }

        // Nur die zwischengespeicherte Kurzfassung - das Dashboard soll nicht bei
        // jedem Aufruf Verzeichnisse durchlaufen. Fehlt sie oder ist sie zu alt,
        // misst summary() einmal neu.
        $storageTile = null;
        if ($this->storageUsage !== null && (bool) ($_SESSION['can_manage_storage'] ?? false)) {
            try {
                $storageTile = ['summary' => $this->storageUsage->summary()];
            } catch (\Throwable $e) {
                $this->logger->error('Storage tile could not be filled.', [
                    'event' => 'storage.dashboard_tile_failed',
                    'exception' => $e,
                ]);
                $storageTile = ['summary' => null];
            }
        }

        $data = [
            'storage_tile' => $storageTile,
            'current_project' => $currentProject,
            'upcoming_project' => $upcomingProject,
            'latest_sent_newsletter' => $latestSentNewsletter,
            'latest_newsletter_subject' => $latestNewsletterSubject,
            'latest_newsletter_project' => $latestNewsletterProject,
            'newsletter_draft_count' => $draftCount,
            'dead_mail_count' => $deadMailCount,
            'registration_summary' => $registrationSummary,
            'upcoming_events' => $upcomingEvents,
            'todays_event' => $todaysEvent,
            'member_count' => $memberCount,
        ];

        return $this->view->render($response, 'dashboard/index.twig', $data);
    }

    /**
     * Die nächsten drei Termine, die das Mitglied sehen darf. Ein Termin, der gerade läuft,
     * zählt noch: In der Probe soll die Probe oben stehen, nicht der Termin danach.
     *
     * @return list<object{event: Event, weekday: string, relative_label: ?string,
     *     own_status: ?string, open: bool}>
     */
    private function upcomingEvents(int $userId, bool $registrationModuleEnabled): array
    {
        $now = Carbon::now();
        $formatter = new EventScheduleFormatter();

        $events = (new EventAudienceService())->visibleEventsQuery($userId)
            ->where(static function ($query) use ($now): void {
                $query->where('events.ends_at', '>=', $now)->orWhere('events.starts_at', '>=', $now);
            })
            ->orderBy('events.starts_at', 'asc')
            ->limit(3)
            ->with(['registrations' => static function ($query) use ($userId): void {
                $query->where('user_id', $userId);
            }])
            ->get();

        $rows = [];
        foreach ($events as $event) {
            $rows[] = (object) [
                'event' => $event,
                'weekday' => $formatter->weekday($event->starts_at),
                'relative_label' => $formatter->relativeLabel($event->starts_at, $now),
                'own_status' => $event->registrations->first()?->status,
                'open' => $registrationModuleEnabled && $event->isRegistrationOpen(),
            ];
        }

        return $rows;
    }

    /**
     * Betreff so, wie er in der Mail ankam: ein Titel mit Platzhaltern stünde sonst als
     * "{{vorname}}: Probenplan" auf der Startseite.
     */
    private function renderedSubject(Request $request, Newsletter $newsletter, int $userId): string
    {
        $placeholders = new NewsletterPlaceholderService(new NameFormatterService());
        $context = $placeholders->contextFor($newsletter, AppUrlResolver::resolveBaseUrl($request));

        return $placeholders->renderSubject((string) $newsletter->title, $context, User::find($userId));
    }
}
