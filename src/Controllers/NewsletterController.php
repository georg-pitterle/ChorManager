<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
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
use App\Queries\NewsletterTemplateQuery;
use App\Persistence\NewsletterTemplatePersistence;
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
    private NewsletterTemplateQuery $templateQuery;
    private NewsletterTemplatePersistence $templatePersistence;
    private LoggerInterface $logger;

    public function __construct(
        Twig $view,
        NewsletterService $newsletterService,
        NewsletterLockingService $lockingService,
        NewsletterRecipientService $recipientService,
        HtmlSanitizer $htmlSanitizer,
        NewsletterTemplateQuery $templateQuery,
        NewsletterTemplatePersistence $templatePersistence,
        LoggerInterface $logger
    ) {
        $this->view = $view;
        $this->newsletterService = $newsletterService;
        $this->lockingService = $lockingService;
        $this->recipientService = $recipientService;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->templateQuery = $templateQuery;
        $this->templatePersistence = $templatePersistence;
        $this->logger = $logger;
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
        $response->getBody()->write((string) json_encode($payload));

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
     * Resolve projects the current user is allowed to access.
     *
     * @param int|null $userId
     * @return \Illuminate\Support\Collection<int, Project>
     */
    private function getAccessibleProjects(?int $userId)
    {
        if (!$userId) {
            return Project::query()->whereRaw('1 = 0')->get();
        }

        $user = User::find($userId);
        if (!$user) {
            return Project::query()->whereRaw('1 = 0')->get();
        }

        $isAdmin = $user->roles()->where('name', 'Admin')->exists();
        if ($isAdmin) {
            return Project::query()->orderBy('name')->get();
        }

        return $user->projects()->orderBy('name')->get();
    }

    protected function getAccessibleProjectIds(?int $userId): array
    {
        return $this->getAccessibleProjects($userId)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    private function canAccessTemplateContext(?int $templateProjectId, array $accessibleProjectIds): bool
    {
        if ($templateProjectId === null) {
            return true;
        }

        return in_array($templateProjectId, $accessibleProjectIds, true);
    }

    private function canAccessNewsletterById(int $newsletterId, ?int $userId): bool
    {
        $newsletter = Newsletter::query()->select(['id', 'project_id'])->find($newsletterId);
        if (!$newsletter) {
            return false;
        }

        $accessibleProjectIds = $this->getAccessibleProjectIds($userId);
        return in_array((int) $newsletter->project_id, $accessibleProjectIds, true);
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

    private function validateTemplateInput(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $contentHtml = $this->htmlSanitizer->sanitizeNewsletterHtml($data['content_html'] ?? '');
        $description = trim((string) ($data['description'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255 || $contentHtml === '') {
            return ['ok' => false, 'payload' => []];
        }

        return [
            'ok' => true,
            'payload' => [
                'name' => $name,
                'content_html' => $contentHtml,
                'description' => $description,
            ],
        ];
    }

    private function validateNewsletterDraftInput(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $contentHtml = $this->htmlSanitizer->sanitizeNewsletterHtml($data['content_html'] ?? '');
        $plainContent = trim(strip_tags((string) $contentHtml));

        if ($title === '' || $plainContent === '') {
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
     * @param array<string, mixed> $data
     * @return array{ok:bool, message:?string, payload:array<string, mixed>}
     */
    private function validateNewsletterSourcesInput(array $data, array $accessibleProjectIds = []): array
    {
        $sources = $data['sources'] ?? null;
        if (!is_array($sources) || $sources === []) {
            return [
                'ok' => false,
                'message' => 'Mindestens eine Empfängerquelle ist erforderlich.',
                'payload' => [],
            ];
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
                $project = Project::query()->find($referenceId);
                if (!$project) {
                    continue;
                }

                if ($accessibleProjectIds !== [] && !in_array((int) $project->id, $accessibleProjectIds, true)) {
                    continue;
                }
            }

            if ($type === NewsletterRecipientSource::TYPE_EVENT_ATTENDEES) {
                $event = Event::query()->find($referenceId);
                if (!$event) {
                    continue;
                }

                if ($accessibleProjectIds !== [] && !in_array((int) $event->project_id, $accessibleProjectIds, true)) {
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

        if ($normalized === []) {
            return [
                'ok' => false,
                'message' => 'Mindestens eine gültige Empfängerquelle ist erforderlich.',
                'payload' => [],
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

        $projects = $this->getAccessibleProjects($userId);
        $projectIds = $projects->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        if ($projects->isEmpty()) {
            return $this->view->render($response, 'newsletters/index.twig', [
                'newsletters' => [],
                'project' => null,
                'projects' => $projects,
                'status' => Newsletter::STATUS_DRAFT,
                'user_id' => $userId,
                'success' => $success,
                'error' => $error,
            ]);
        }

        $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;
        $status = $queryParams['status'] ?? Newsletter::STATUS_DRAFT;
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
        $allowedStatuses = Newsletter::SUPPORTED_STATUSES;

        if (!in_array($status, $allowedStatuses, true)) {
            $status = Newsletter::STATUS_DRAFT;
        }

        if ($status === Newsletter::STATUS_SENT) {
            $query = Newsletter::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', Newsletter::STATUS_SENT)
                ->with(['createdBy', 'project']);

            if ($recipientType !== '') {
                $query->whereHas('recipientSources', function ($sourceQuery) use ($recipientType) {
                    $sourceQuery->where('source_type', $recipientType);
                });
            }

            $newsletters = $query
                ->orderBy('sent_at', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->view->render($response, 'newsletters/index.twig', [
                'newsletters' => $newsletters,
                'project' => null,
                'projects' => $projects,
                'status' => $status,
                'recipient_type' => $recipientType,
                'user_id' => $userId,
                'success' => $success,
                'error' => $error,
            ]);
        }

        if (!$projectId) {
            $defaultProject = $projects->first();
            return $response->withHeader('Location', '/newsletters?project_id=' . $defaultProject->id . '&status=' . $status)
                ->withStatus(302);
        }

        $project = $projects->firstWhere('id', $projectId);
        if (!$project) {
            return $response->withStatus(403);
        }

        $query = Newsletter::query()->where('project_id', $projectId);

        $query->where('status', $status);

        if ($recipientType !== '') {
            $query->whereHas('recipientSources', function ($sourceQuery) use ($recipientType) {
                $sourceQuery->where('source_type', $recipientType);
            });
        }

        $newsletters = $query
            ->with(['createdBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->view->render($response, 'newsletters/index.twig', [
            'newsletters' => $newsletters,
            'project' => $project,
            'projects' => $projects,
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
        $userId = $_SESSION['user_id'] ?? null;
        $projects = $this->getAccessibleProjects($userId);

        if ($projects->isEmpty()) {
            return $response->withStatus(403);
        }

        $projectId = !empty($queryParams['project_id'])
            ? (int)$queryParams['project_id']
            : (int) $projects->first()->id;

        $project = $projects->firstWhere('id', $projectId);

        if (!$project) {
            return $response->withStatus(403);
        }

        $events = Event::with('project')
            ->whereIn('project_id', $projects->pluck('id')->toArray())
            ->orderBy('starts_at', 'desc')
            ->get();
        $roles = Role::query()->orderBy('name')->get();
        $users = User::query()->where('is_active', 1)->orderBy('last_name')->orderBy('first_name')->get();
        $templates = NewsletterTemplate::where('project_id', $projectId)
            ->orWhereNull('project_id')
            ->orderBy('name')
            ->get();

        return $this->view->render($response, 'newsletters/create.twig', [
            'project' => $project,
            'projects' => $projects,
            'events' => $events,
            'roles' => $roles,
            'users' => $users,
            'recipient_sources' => [
                [
                    'type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
                    'reference_id' => $projectId,
                ],
            ],
            'templates' => $templates,
            'is_modal' => $isModal,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $projectId = (int)($data['project_id'] ?? 0);
        $isModal = ((string) ($data['modal'] ?? '0')) === '1';
        $userId = $_SESSION['user_id'] ?? null;
        $expectsJson = $this->expectsJson($request);

        if (!$projectId || !$userId) {
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Zugriff verweigert.'], 403);
            }

            return $response->withStatus(403);
        }

        $projects = $this->getAccessibleProjects($userId);
        $canAccessProject = $projects->contains(function ($project) use ($projectId) {
            return (int) $project->id === $projectId;
        });

        if (!$canAccessProject) {
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Zugriff verweigert.'], 403);
            }

            return $response->withStatus(403);
        }

        $project = Project::find($projectId);
        if (!$project) {
            return $response->withStatus(404);
        }

        $validation = $this->validateNewsletterDraftInput($data);
        if (!$validation['ok']) {
            $message = (string) ($validation['message'] ?? 'Ungültige Eingabedaten.');

            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => $message], 422);
            }

            $_SESSION['error'] = $message;
            return $response
                ->withHeader('Location', '/newsletters/create?project_id=' . $projectId)
                ->withStatus(302);
        }

        $accessibleProjectIds = $projects->pluck('id')->map(static fn($id): int => (int) $id)->all();
        $sourceValidation = $this->validateNewsletterSourcesInput($data, $accessibleProjectIds);
        if (!$sourceValidation['ok']) {
            $message = (string) ($sourceValidation['message'] ?? 'Ungültige Empfängerquellen.');

            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => $message], 422);
            }

            $_SESSION['error'] = $message;
            return $response
                ->withHeader('Location', '/newsletters/create?project_id=' . $projectId)
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
            'redirect' => "/newsletters/{$newsletter->id}/edit?project_id={$projectId}" . ($isModal ? '&modal=1' : ''),
        ], 201);
    }

    public function edit(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $queryParams = $request->getQueryParams();
        $isModal = ((string) ($queryParams['modal'] ?? '0')) === '1';
        $userId = $_SESSION['user_id'] ?? null;
        $projects = $this->getAccessibleProjects($userId);

        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $response->withStatus(404);
        }

        $canAccessNewsletterProject = $projects->contains(function ($project) use ($newsletter) {
            return (int) $project->id === (int) $newsletter->project_id;
        });

        if (!$canAccessNewsletterProject) {
            return $response->withStatus(403);
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
        $events = Event::with('project')
            ->whereIn('project_id', $projects->pluck('id')->toArray())
            ->orderBy('starts_at', 'desc')
            ->get();
        $roles = Role::query()->orderBy('name')->get();
        $users = User::query()->where('is_active', 1)->orderBy('last_name')->orderBy('first_name')->get();
        $sources = $this->recipientService->getSources($newsletter);
        if ($sources === []) {
            $sources = [[
                'type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
                'reference_id' => (int) $newsletter->project_id,
            ]];
        }

        return $this->view->render($response, 'newsletters/edit.twig', [
            'newsletter' => $newsletter,
            'project' => $project,
            'projects' => $projects,
            'events' => $events,
            'roles' => $roles,
            'users' => $users,
            'recipient_sources' => $sources,
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

        $projectId = !empty($data['project_id']) ? (int) $data['project_id'] : (int) $newsletter->project_id;
        $projects = $this->getAccessibleProjects($userId);
        $canAccessProject = $projects->contains(function ($project) use ($projectId) {
            return (int) $project->id === $projectId;
        });

        if (!$canAccessProject) {
            return $this->jsonResponse($response, ['error' => 'Zugriff verweigert.'], 403);
        }

        $validation = $this->validateNewsletterDraftInput($data);
        if (!$validation['ok']) {
            $message = (string) ($validation['message'] ?? 'Ungültige Eingabedaten.');
            return $this->jsonResponse($response, ['error' => $message], 422);
        }

        $accessibleProjectIds = $projects->pluck('id')->map(static fn($id): int => (int) $id)->all();
        $sourceValidation = $this->validateNewsletterSourcesInput($data, $accessibleProjectIds);
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
        $userId = $_SESSION['user_id'] ?? null;
        $projectId = (int) ($data['project_id'] ?? 0);

        $projects = $this->getAccessibleProjects($userId);
        $accessibleProjectIds = $projects->pluck('id')->map(static fn($id): int => (int) $id)->all();
        if ($projectId <= 0 || !in_array($projectId, $accessibleProjectIds, true)) {
            return $this->jsonResponse($response, [
                'errors' => ['Zugriff verweigert.'],
            ], 403);
        }

        $validation = $this->validateNewsletterSourcesInput($data, $accessibleProjectIds);
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

        if (!$this->canAccessNewsletterById($id, $userId) && !$this->canAccessReceivedNewsletterById($id, $userId)) {
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

        if (!$this->canAccessNewsletterById($id, $userId)) {
            return $response->withStatus(403);
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

    public function saveAsTemplate(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $data = (array) $request->getParsedBody();
        $userId = $_SESSION['user_id'] ?? null;
        $expectsJson = $this->expectsJson($request);

        if (!$this->canAccessNewsletterById($id, $userId)) {
            return $response->withStatus(403);
        }

        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $response->withStatus(404);
        }

        $templateName = trim((string) ($data['template_name'] ?? $newsletter->title));
        $templateDescription = trim((string) ($data['template_description'] ?? ''));
        $templateContentHtml = $this->htmlSanitizer->sanitizeNewsletterHtml($newsletter->content_html);

        if ($templateName === '' || mb_strlen($templateName) > 255 || trim(strip_tags($templateContentHtml)) === '') {
            if ($expectsJson) {
                return $this->jsonResponse($response, ['error' => 'Ungültige Vorlagendaten.'], 422);
            }

            $_SESSION['error'] = 'Ungültige Vorlagendaten.';
            return $response
                ->withHeader('Location', '/newsletters/' . $id . '/edit')
                ->withStatus(302);
        }

        $template = NewsletterTemplate::create([
            'name' => $templateName,
            'description' => $templateDescription,
            'content_html' => $templateContentHtml,
            'project_id' => $newsletter->project_id,
            'created_by' => $userId,
        ]);

        if (!$expectsJson) {
            $_SESSION['success'] = 'Vorlage gespeichert';
            return $response
                ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                ->withStatus(302);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'template_id' => $template->id,
        ], 201);
    }

    public function getTemplate(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $template = NewsletterTemplate::find($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$this->canAccessTemplateContext($template->project_id, $this->getAccessibleProjectIds($userId))) {
            return $response->withStatus(403);
        }

        return $this->jsonResponse($response, [
            'id' => $template->id,
            'name' => $template->name,
            'content_html' => $template->content_html,
        ]);
    }

    public function checkLock(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;

        if (!$this->canAccessNewsletterById($id, $userId)) {
            return $response->withStatus(403);
        }

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
            'locked_by_user' => $lockedByUser ? $lockedByUser->first_name . ' ' . $lockedByUser->last_name : 'Unknown',
            'locked_at' => $newsletter->locked_at->format('Y-m-d H:i:s'),
            'is_me' => $newsletter->locked_by === $userId,
        ]);
    }

    public function deleteDraft(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter || !$newsletter->isDraft()) {
            return $response->withStatus(404);
        }

        if (!$this->canAccessNewsletterById($id, $userId)) {
            return $response->withStatus(403);
        }

        if ($newsletter->isLocked() && (int) ($newsletter->locked_by ?? 0) !== (int) ($userId ?? 0)) {
            $_SESSION['error'] =
                'Newsletter-Entwurf wird gerade von einer anderen Person bearbeitet und kann derzeit nicht geloescht werden.';
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

    public function listTemplates(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'] ?? null;
        $projects = $this->getAccessibleProjects($userId);
        $templates = $this->templateQuery->getForAccessibleProjects($this->getAccessibleProjectIds($userId));
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'newsletters/templates_index.twig', [
            'projects' => $projects,
            'templates' => $templates,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function createTemplate(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $validation = $this->validateTemplateInput($data);
        if (!$validation['ok']) {
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = 'Ungueltige Vorlagendaten';
                return $response
                    ->withHeader('Location', '/newsletters/templates')
                    ->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => 'Ungueltige Vorlagendaten'], 422);
        }

        $projectId = null;
        if (($data['project_id'] ?? '') !== '') {
            $projectId = (int) $data['project_id'];
        }

        $accessibleProjectIds = $this->getAccessibleProjectIds($userId);
        if (!$this->canAccessTemplateContext($projectId, $accessibleProjectIds)) {
            return $response->withStatus(403);
        }

        $template = $this->templatePersistence->createTemplate($validation['payload'], $userId, $projectId);

        if (!$this->expectsJson($request)) {
            $_SESSION['success'] = 'Vorlage erstellt';
            return $response
                ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                ->withStatus(302);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'template_id' => $template->id,
            'redirect' => '/newsletters/templates/' . $template->id . '/edit',
        ], 201);
    }

    public function editTemplate(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $queryParams = $request->getQueryParams();
        $isModal = ((string) ($queryParams['modal'] ?? '0')) === '1';
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$this->canAccessTemplateContext($template->project_id, $this->getAccessibleProjectIds($userId))) {
            return $response->withStatus(403);
        }

        return $this->view->render($response, 'newsletters/templates_edit.twig', [
            'template' => $template,
            'is_modal' => $isModal,
        ]);
    }

    public function updateTemplate(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $userId = $_SESSION['user_id'] ?? null;
        $accessibleProjectIds = $this->getAccessibleProjectIds($userId);

        if (!$this->canAccessTemplateContext($template->project_id, $accessibleProjectIds)) {
            return $response->withStatus(403);
        }

        $validation = $this->validateTemplateInput((array) $request->getParsedBody());
        if (!$validation['ok']) {
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = 'Ungueltige Vorlagendaten';
                return $response
                    ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                    ->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => 'Ungueltige Vorlagendaten'], 422);
        }

        $this->templatePersistence->updateTemplate($template, $validation['payload']);
        $_SESSION['success'] = 'Vorlage gespeichert';

        if (!$this->expectsJson($request)) {
            return $response
                ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                ->withStatus(302);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }

    public function cloneTemplate(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withStatus(403);
        }

        $accessibleProjectIds = $this->getAccessibleProjectIds($userId);
        if (!$this->canAccessTemplateContext($template->project_id, $accessibleProjectIds)) {
            return $response->withStatus(403);
        }

        $clone = $this->templatePersistence->cloneTemplate($template, $userId);

        if (!$this->expectsJson($request)) {
            $_SESSION['success'] = 'Vorlage geklont';
            return $response
                ->withHeader('Location', '/newsletters/templates/' . $clone->id . '/edit')
                ->withStatus(302);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'template_id' => $clone->id,
            'redirect' => '/newsletters/templates/' . $clone->id . '/edit',
        ], 201);
    }
}
