<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Exceptions\NewsletterWithoutRecipientsException;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use App\Models\NewsletterRecipientSource;
use App\Models\NewsletterTemplate;
use App\Models\Project;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Services\NewsletterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterRecipientService;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Util\EnvHelper;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;

class NewsletterController
{
    private Twig $view;
    private NewsletterService $newsletterService;
    private NewsletterLockingService $lockingService;
    private NewsletterRecipientService $recipientService;
    private HtmlSanitizer $htmlSanitizer;
    private LoggerInterface $logger;
    private NameFormatterService $nameFormatter;

    public function __construct(
        Twig $view,
        NewsletterService $newsletterService,
        NewsletterLockingService $lockingService,
        NewsletterRecipientService $recipientService,
        HtmlSanitizer $htmlSanitizer,
        LoggerInterface $logger,
        NameFormatterService $nameFormatter
    ) {
        $this->view = $view;
        $this->newsletterService = $newsletterService;
        $this->lockingService = $lockingService;
        $this->recipientService = $recipientService;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->logger = $logger;
        $this->nameFormatter = $nameFormatter;
    }

    /**
     * Active users ordered by the configured name display format.
     */
    private function activeUsersInNameOrder(): Collection
    {
        $query = User::query()->where('is_active', 1);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    /**
     * Build a JSON response without relying on framework-specific helper functions.
     *
     * @param Response $response
     * @param array<string, mixed> $payload
     * @param int $status
     * @return Response
     */
    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    private function expectsJson(Request $request): bool
    {
        $xRequestedWith = strtolower(trim($request->getHeaderLine('X-Requested-With')));
        if ($xRequestedWith === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        return str_contains($accept, 'application/json');
    }

    /**
     * Alle Projekte als Auswahl. Der Zugang zum Modul wird allein über das Recht
     * can_manage_newsletters geregelt, nicht über die Projektmitgliedschaft.
     *
     * @return \Illuminate\Support\Collection<int, Project>
     */
    private function selectableProjects()
    {
        return Project::query()->orderBy('name')->get();
    }

    /**
     * Vorlagen für das Auswahlfeld: global zuerst, danach je Projekt.
     *
     * @return array<int, array{label: string, templates: \Illuminate\Support\Collection}>
     */
    private function groupedTemplates(): array
    {
        $templates = NewsletterTemplate::query()->with('project')->orderBy('name')->get();

        $global = $templates->filter(static fn ($template): bool => $template->project_id === null)->values();
        $groups = [];

        if ($global->isNotEmpty()) {
            $groups[] = ['label' => 'Global', 'templates' => $global];
        }

        $byProject = $templates
            ->filter(static fn ($template): bool => $template->project_id !== null)
            ->groupBy(static fn ($template): string => (string) ($template->project->name ?? 'Projekt'));

        foreach ($byProject->sortKeys() as $projectName => $projectTemplates) {
            $groups[] = ['label' => (string) $projectName, 'templates' => $projectTemplates->values()];
        }

        return $groups;
    }

    private function canManageNewsletters(): bool
    {
        return (bool) ($_SESSION['can_manage_newsletters'] ?? false);
    }

    private function canAccessReceivedNewsletterById(int $newsletterId, ?int $userId): bool
    {
        if (!$userId) {
            return false;
        }

        return NewsletterArchive::query()
            ->where('newsletter_id', $newsletterId)
            ->where('user_id', (int) $userId)
            ->exists();
    }

    private function validateNewsletterDraftInput(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $contentHtml = $this->htmlSanitizer->sanitizeNewsletterHtml($data['content_html'] ?? '');
        $plainContent = trim(strip_tags((string) $contentHtml));
        $hasMediaContent = (bool) preg_match('/<(img|table)\b/i', (string) $contentHtml);

        if ($title === '' || ($plainContent === '' && !$hasMediaContent)) {
            return ['ok' => false, 'message' => 'Titel und Inhalt sind Pflichtfelder.', 'payload' => []];
        }

        if (mb_strlen($title) > 255) {
            return ['ok' => false, 'message' => 'Der Titel ist zu lang (max. 255 Zeichen).', 'payload' => []];
        }

        return [
            'ok' => true,
            'message' => null,
            'payload' => [
                'title' => $title,
                'content_html' => $contentHtml,
            ],
        ];
    }

    /**
     * Empfängerquellen sind beim Speichern freiwillig; erst der Versand verlangt
     * mindestens eine aufgelöste Person.
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool, message:?string, payload:array<string, mixed>}
     */
    private function validateNewsletterSourcesInput(array $data): array
    {
        $sources = $data['sources'] ?? null;
        if (!is_array($sources)) {
            $sources = [];
        }

        $allowedTypes = [
            NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
            NewsletterRecipientSource::TYPE_EVENT_ATTENDEES,
            NewsletterRecipientSource::TYPE_ROLE,
            NewsletterRecipientSource::TYPE_USER,
        ];

        $normalized = [];
        $seen = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $type = trim((string) ($source['type'] ?? ''));
            $referenceId = (int) ($source['reference_id'] ?? 0);

            if (!in_array($type, $allowedTypes, true) || $referenceId <= 0) {
                continue;
            }

            if ($type === NewsletterRecipientSource::TYPE_PROJECT_MEMBERS) {
                if (!Project::query()->where('id', $referenceId)->exists()) {
                    continue;
                }
            }

            if ($type === NewsletterRecipientSource::TYPE_EVENT_ATTENDEES) {
                $event = Event::query()->find($referenceId);
                if (!$event) {
                    continue;
                }
            }

            if (
                $type === NewsletterRecipientSource::TYPE_ROLE
                && !Role::query()->where('id', $referenceId)->exists()
            ) {
                continue;
            }

            if (
                $type === NewsletterRecipientSource::TYPE_USER
                && !User::query()->where('id', $referenceId)->where('is_active', 1)->exists()
            ) {
                continue;
            }

            $dedupeKey = $type . ':' . $referenceId;
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $normalized[] = [
                'type' => $type,
                'reference_id' => $referenceId,
            ];
        }

        return [
            'ok' => true,
            'message' => null,
            'payload' => [
                'sources' => $normalized,
            ],
        ];
    }

    /**
     * @param array<int, array{type:string, reference_id:int}> $sources
     * @return Collection<int, NewsletterRecipientSource>
     */
    private function buildSourceCollection(array $sources): Collection
    {
        $items = [];
        foreach ($sources as $source) {
            $items[] = new NewsletterRecipientSource([
                'source_type' => (string) $source['type'],
                'reference_id' => (int) $source['reference_id'],
            ]);
        }

        return new Collection($items);
    }

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $userId = $_SESSION['user_id'] ?? null;
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        $projects = $this->selectableProjects();

        $status = (string) ($queryParams['status'] ?? Newsletter::STATUS_DRAFT);
        if (!in_array($status, Newsletter::SUPPORTED_STATUSES, true)) {
            $status = Newsletter::STATUS_DRAFT;
        }

        $recipientType = trim((string) ($queryParams['recipient_type'] ?? ''));
        $allowedRecipientTypes = [
            NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
            NewsletterRecipientSource::TYPE_EVENT_ATTENDEES,
            NewsletterRecipientSource::TYPE_ROLE,
            NewsletterRecipientSource::TYPE_USER,
        ];
        if (!in_array($recipientType, $allowedRecipientTypes, true)) {
            $recipientType = '';
        }

        // '' = alle Projekte, 'none' = ohne Projekt, sonst eine konkrete ID.
        // Unbekannte Werte fallen auf 'alle' zurück – das gilt auch für eine
        // numerische, aber nicht (mehr) existierende Projekt-Kennung, sonst
        // wirkt die Liste grundlos leer, während das Auswahlfeld mangels
        // passender Option "Alle Projekte" anzeigt.
        $projectFilter = trim((string) ($queryParams['project_id'] ?? ''));
        if ($projectFilter !== '' && $projectFilter !== 'none' && !ctype_digit($projectFilter)) {
            $projectFilter = '';
        }
        if ($projectFilter !== '' && $projectFilter !== 'none' && !$projects->contains('id', (int) $projectFilter)) {
            $projectFilter = '';
        }

        $query = Newsletter::query()
            ->where('status', $status)
            ->with(['createdBy', 'project']);

        if ($projectFilter === 'none') {
            $query->whereNull('project_id');
        } elseif ($projectFilter !== '') {
            $query->where('project_id', (int) $projectFilter);
        }

        if ($recipientType !== '') {
            $query->whereHas('recipientSources', function ($sourceQuery) use ($recipientType) {
                $sourceQuery->where('source_type', $recipientType);
            });
        }

        if ($status === Newsletter::STATUS_SENT) {
            $query->orderBy('sent_at', 'desc');
        }

        $newsletters = $query->orderBy('created_at', 'desc')->get();

        return $this->view->render($response, 'newsletters/index.twig', [
            'newsletters' => $newsletters,
            'projects' => $projects,
            'project_filter' => $projectFilter,
            'status' => $status,
            'recipient_type' => $recipientType,
            'user_id' => $userId,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function archive(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $archives = NewsletterArchive::query()
            ->where('user_id', (int) $userId)
            ->with(['newsletter.createdBy', 'newsletter.project'])
            ->orderBy('sent_at', 'desc')
            ->get();

        return $this->view->render($response, 'newsletters/archive.twig', [
            'archives' => $archives,
            'active_nav' => 'newsletters_archive',
            'user_id' => $userId,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $isModal = ((string) ($queryParams['modal'] ?? '0')) === '1';
        $projects = $this->selectableProjects();

        $projectId = !empty($queryParams['project_id']) ? (int) $queryParams['project_id'] : null;
        $project = $projectId === null ? null : $projects->firstWhere('id', $projectId);

        $events = Event::query()
            ->orderBy('starts_at', 'desc')
            ->get();
        $roles = Role::query()->orderBy('name')->get();
        $users = $this->activeUsersInNameOrder();

        return $this->view->render($response, 'newsletters/create.twig', [
            'project' => $project,
            'projects' => $projects,
            'events' => $events,
            'roles' => $roles,
            'users' => $users,
            'recipient_sources' => $project === null ? [] : [
                [
                    'type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
                    'reference_id' => (int) $project->id,
                ],
            ],
            'template_groups' => $this->groupedTemplates(),
            'is_modal' => $isModal,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $isModal = ((string) ($data['modal'] ?? '0')) === '1';
        $userId = $_SESSION['user_id'] ?? null;
        $expectsJson = $this->expectsJson($request);

        if (!$userId) {
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Zugriff verweigert.'], 403);
            }

            return $response->withStatus(403);
        }

        $projectId = null;
        if (($data['project_id'] ?? '') !== '') {
            $projectId = (int) $data['project_id'];
        }

        if ($projectId !== null && !Project::query()->where('id', $projectId)->exists()) {
            $message = 'Das gewählte Projekt existiert nicht.';
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => $message], 422);
            }

            $_SESSION['error'] = $message;
            return $response->withHeader('Location', '/newsletters/create')->withStatus(302);
        }

        $validation = $this->validateNewsletterDraftInput($data);
        if (!$validation['ok']) {
            $message = (string) ($validation['message'] ?? 'Ungültige Eingabedaten.');

            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => $message], 422);
            }

            $_SESSION['error'] = $message;
            return $response
                ->withHeader(
                    'Location',
                    '/newsletters/create' . ($projectId !== null ? '?project_id=' . $projectId : '')
                )
                ->withStatus(302);
        }

        $sourceValidation = $this->validateNewsletterSourcesInput($data);
        if (!$sourceValidation['ok']) {
            $message = (string) ($sourceValidation['message'] ?? 'Ungültige Empfängerquellen.');

            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => $message], 422);
            }

            $_SESSION['error'] = $message;
            return $response
                ->withHeader(
                    'Location',
                    '/newsletters/create' . ($projectId !== null ? '?project_id=' . $projectId : '')
                )
                ->withStatus(302);
        }

        $newsletter = Newsletter::create([
            'project_id' => $projectId,
            'title' => $validation['payload']['title'],
            'content_html' => $validation['payload']['content_html'],
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $userId,
        ]);

        $this->recipientService->setSources($newsletter, $sourceValidation['payload']['sources']);

        return $this->jsonResponse($response, [
            'id' => $newsletter->id,
            'redirect' => "/newsletters/{$newsletter->id}/edit" . ($isModal ? '?modal=1' : ''),
        ], 201);
    }

    public function edit(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $queryParams = $request->getQueryParams();
        $isModal = ((string) ($queryParams['modal'] ?? '0')) === '1';
        $userId = $_SESSION['user_id'] ?? null;
        $projects = $this->selectableProjects();

        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $response->withStatus(404);
        }

        $canEdit = $this->lockingService->canEdit($newsletter, $userId);

        if (!$canEdit) {
            $lockedByUser = User::find($newsletter->locked_by);
            return $this->view->render($response->withStatus(423), 'newsletters/locked.twig', [
                'newsletter' => $newsletter,
                'locked_by_user' => $lockedByUser,
                'is_modal' => $isModal,
            ]);
        }

        $this->lockingService->acquireLock($newsletter, $userId);

        $project = $newsletter->project;
        $events = Event::query()
            ->orderBy('starts_at', 'desc')
            ->get();
        $roles = Role::query()->orderBy('name')->get();
        $users = $this->activeUsersInNameOrder();
        $sources = $this->recipientService->getSources($newsletter);

        return $this->view->render($response, 'newsletters/edit.twig', [
            'newsletter' => $newsletter,
            'project' => $project,
            'projects' => $projects,
            'events' => $events,
            'roles' => $roles,
            'users' => $users,
            'recipient_sources' => $sources,
            'template_groups' => $this->groupedTemplates(),
            'is_modal' => $isModal,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $data = (array) $request->getParsedBody();
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $response->withStatus(404);
        }

        if (!$this->lockingService->isLockedBy($newsletter, $userId)) {
            return $this->jsonResponse($response, ['error' => 'Newsletter ist nicht für diese Sitzung gesperrt.'], 403);
        }

        $projectId = null;
        if (($data['project_id'] ?? '') !== '') {
            $projectId = (int) $data['project_id'];
        }

        if ($projectId !== null && !Project::query()->where('id', $projectId)->exists()) {
            return $this->jsonResponse($response, ['error' => 'Das gewählte Projekt existiert nicht.'], 422);
        }

        $validation = $this->validateNewsletterDraftInput($data);
        if (!$validation['ok']) {
            $message = (string) ($validation['message'] ?? 'Ungültige Eingabedaten.');
            return $this->jsonResponse($response, ['error' => $message], 422);
        }

        $sourceValidation = $this->validateNewsletterSourcesInput($data);
        if (!$sourceValidation['ok']) {
            $message = (string) ($sourceValidation['message'] ?? 'Ungültige Empfängerquellen.');
            return $this->jsonResponse($response, ['error' => $message], 422);
        }

        $newsletter->update([
            'project_id' => $projectId,
            'title' => $validation['payload']['title'],
            'content_html' => $validation['payload']['content_html'],
        ]);

        $this->recipientService->setSources($newsletter, $sourceValidation['payload']['sources']);

        $suppressFlash = ((string) ($data['suppress_flash'] ?? '0')) === '1';
        if (!$suppressFlash) {
            $_SESSION['success'] = 'Newsletter gespeichert';
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Newsletter gespeichert',
        ]);
    }

    public function resolveRecipientsPreview(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        $validation = $this->validateNewsletterSourcesInput($data);
        if (!$validation['ok']) {
            return $this->jsonResponse($response, [
                'errors' => [(string) ($validation['message'] ?? 'Ungültige Empfängerquellen.')],
            ], 422);
        }

        $newsletter = new Newsletter();
        $newsletter->setRelation('recipientSources', $this->buildSourceCollection($validation['payload']['sources']));
        $count = $this->recipientService->resolveRecipients($newsletter)->count();

        return $this->jsonResponse($response, ['count' => $count]);
    }

    public function preview(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $queryParams = $request->getQueryParams();
        $isModal = ((string) ($queryParams['modal'] ?? '0')) === '1';
        $userId = $_SESSION['user_id'] ?? null;

        if (!$this->canManageNewsletters() && !$this->canAccessReceivedNewsletterById($id, $userId)) {
            return $response->withStatus(403);
        }

        $newsletter = Newsletter::find($id);

        if (!$newsletter) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'newsletters/preview.twig', [
            'newsletter' => $newsletter,
            'preview_content_html' => $this->htmlSanitizer->sanitizeNewsletterHtml((string) $newsletter->content_html),
            'is_modal' => $isModal,
        ]);
    }

    public function send(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;
        $expectsJson = $this->expectsJson($request);

        $newsletter = Newsletter::find($id);
        if (!$newsletter || !$newsletter->isDraft()) {
            if (!$expectsJson) {
                $_SESSION['error'] = 'Newsletter-Entwurf wurde nicht gefunden.';
                return $response->withHeader('Location', '/newsletters?status=' . Newsletter::STATUS_DRAFT)
                    ->withStatus(302);
            }

            return $response->withStatus(404);
        }

        if ($newsletter->isLocked() && !$this->lockingService->isLockedBy($newsletter, $userId)) {
            $message = 'Newsletter wird gerade von einer anderen Person bearbeitet und kann derzeit nicht versendet werden.';
            if (!$expectsJson) {
                $_SESSION['error'] = $message;
                return $response->withHeader(
                    'Location',
                    "/newsletters?project_id={$newsletter->project_id}&status=" . Newsletter::STATUS_DRAFT
                )
                    ->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => $message], 409);
        }

        if (!$newsletter->isLocked()) {
            $this->lockingService->acquireLock($newsletter, $userId);
        }

        $this->lockingService->releaseLock($newsletter);

        try {
            $recipientCount = $this->newsletterService->send($newsletter, $userId);
            if (EnvHelper::readBool('DISABLE_MAIL_SEND', true)) {
                $_SESSION['success'] = "[Dev-Modus] Mailversand deaktiviert – {$recipientCount} Mail(s) wären versendet worden.";
            } else {
                $_SESSION['success'] = 'Newsletter versendet';
            }

            if ($expectsJson) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'redirect' => "/newsletters?project_id={$newsletter->project_id}&status=" . Newsletter::STATUS_SENT,
                ]);
            }
        } catch (NewsletterWithoutRecipientsException $e) {
            $message = $e->getMessage();
            $this->logger->info(
                'Newsletter send blocked without recipients.',
                [
                    'event' => 'newsletter.send.blocked_without_recipients',
                    'newsletter_id' => $id,
                    'user_id' => is_numeric($userId) ? (int) $userId : null,
                ]
            );

            if (!$expectsJson) {
                $_SESSION['error'] = $message;
                return $response
                    ->withHeader('Location', '/newsletters?status=' . Newsletter::STATUS_DRAFT)
                    ->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => $message], 422);
        } catch (\Exception $e) {
            $this->logger->error(
                'Newsletter send failed.',
                [
                    'event' => 'newsletter.send.failed',
                    'newsletter_id' => $id,
                    'user_id' => is_numeric($userId) ? (int) $userId : null,
                    'exception' => $e,
                ]
            );
            $message = 'Fehler beim Versand.';
            if (!$expectsJson) {
                $_SESSION['error'] = $message;
                return $response->withHeader(
                    'Location',
                    "/newsletters?project_id={$newsletter->project_id}&status=" . Newsletter::STATUS_DRAFT
                )
                    ->withStatus(302);
            }

            $_SESSION['error'] = $message;
            return $this->jsonResponse($response, ['error' => $message], 500);
        }

        return $response->withHeader(
            'Location',
            "/newsletters?project_id={$newsletter->project_id}&status=" . Newsletter::STATUS_SENT
        )
            ->withStatus(302);
    }

    public function checkLock(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $response->withStatus(404);
        }

        if (!$newsletter->isLocked()) {
            return $this->jsonResponse($response, [
                'locked' => false,
            ]);
        }

        $lockedByUser = User::find($newsletter->locked_by);

        return $this->jsonResponse($response, [
            'locked' => true,
            'locked_by_user' => $lockedByUser
                ? $this->nameFormatter->formatPerson($lockedByUser)
                : 'Unknown',
            'locked_at' => $newsletter->locked_at->format('Y-m-d H:i:s'),
            'is_me' => $newsletter->locked_by === $userId,
        ]);
    }

    public function releaseLock(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $response->withStatus(404);
        }

        if ($this->lockingService->isLockedBy($newsletter, $userId)) {
            $this->lockingService->releaseLock($newsletter);
        }

        return $this->jsonResponse($response, ['released' => true]);
    }

    public function deleteDraft(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter || !$newsletter->isDraft()) {
            return $response->withStatus(404);
        }

        if ($newsletter->isLocked() && (int) ($newsletter->locked_by ?? 0) !== (int) ($userId ?? 0)) {
            $_SESSION['error'] =
                'Newsletter-Entwurf wird gerade von einer anderen Person bearbeitet und kann derzeit nicht gelöscht werden.';
            return $response->withHeader(
                'Location',
                "/newsletters?project_id={$newsletter->project_id}&status=" . Newsletter::STATUS_DRAFT
            )
                ->withStatus(302);
        }

        NewsletterRecipient::where('newsletter_id', $newsletter->id)->delete();
        $newsletter->delete();
        $_SESSION['success'] = 'Newsletter-Entwurf gelöscht';

        return $response->withHeader(
            'Location',
            "/newsletters?project_id={$newsletter->project_id}&status=" . Newsletter::STATUS_DRAFT
        )
            ->withStatus(302);
    }
}
