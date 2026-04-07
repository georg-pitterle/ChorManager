<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterTemplate;
use App\Models\Project;
use App\Models\Event;
use App\Models\User;
use App\Services\NewsletterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterRecipientService;

class NewsletterController
{
    private Twig $view;
    private NewsletterService $newsletterService;
    private NewsletterLockingService $lockingService;
    private NewsletterRecipientService $recipientService;

    public function __construct(
        Twig $view,
        NewsletterService $newsletterService,
        NewsletterLockingService $lockingService,
        NewsletterRecipientService $recipientService
    ) {
        $this->view = $view;
        $this->newsletterService = $newsletterService;
        $this->lockingService = $lockingService;
        $this->recipientService = $recipientService;
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

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $userId = $_SESSION['user_id'] ?? null;
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
            ]);
        }

        $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;
        $status = $queryParams['status'] ?? Newsletter::STATUS_DRAFT;
        $allowedStatuses = Newsletter::SUPPORTED_STATUSES;

        if (!in_array($status, $allowedStatuses, true)) {
            $status = Newsletter::STATUS_DRAFT;
        }

        if ($status === Newsletter::STATUS_SENT) {
            $newsletters = Newsletter::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', Newsletter::STATUS_SENT)
                ->with(['createdBy', 'project', 'event'])
                ->orderBy('sent_at', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->view->render($response, 'newsletters/index.twig', [
                'newsletters' => $newsletters,
                'project' => null,
                'projects' => $projects,
                'status' => $status,
                'user_id' => $userId,
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

        $newsletters = $query
            ->with(['createdBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->view->render($response, 'newsletters/index.twig', [
            'newsletters' => $newsletters,
            'project' => $project,
            'projects' => $projects,
            'status' => $status,
            'user_id' => $userId,
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
            ->with(['newsletter.createdBy', 'newsletter.project', 'newsletter.event'])
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
            ->orderBy('event_date', 'desc')
            ->get();
        $templates = NewsletterTemplate::where('project_id', $projectId)
            ->orWhereNull('project_id')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return $this->view->render($response, 'newsletters/create.twig', [
            'project' => $project,
            'projects' => $projects,
            'events' => $events,
            'templates' => $templates,
            'is_modal' => $isModal,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $projectId = (int)($data['project_id'] ?? 0);
        $isModal = ((string) ($data['modal'] ?? '0')) === '1';
        $userId = $_SESSION['user_id'] ?? null;

        if (!$projectId || !$userId) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $projects = $this->getAccessibleProjects($userId);
        $canAccessProject = $projects->contains(function ($project) use ($projectId) {
            return (int) $project->id === $projectId;
        });

        if (!$canAccessProject) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $project = Project::find($projectId);
        if (!$project) {
            return $response->withStatus(404);
        }

        $eventId = null;
        if (!empty($data['event_id'])) {
            $requestedEventId = (int) $data['event_id'];
            $event = Event::where('id', $requestedEventId)
                ->where('project_id', $projectId)
                ->first();
            if ($event) {
                $eventId = $requestedEventId;
            }
        }

        $newsletter = Newsletter::create([
            'project_id' => $projectId,
            'event_id' => $eventId,
            'title' => $data['title'] ?? 'Untitled Newsletter',
            'content_html' => $data['content_html'] ?? '',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $userId,
        ]);

        $recipients = $this->recipientService->resolveRecipients($projectId, (int) ($eventId ?? 0));
        $this->recipientService->setRecipients($newsletter, $recipients->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all());

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
            return $this->view->render($response, 'newsletters/locked.twig', [
                'newsletter' => $newsletter,
                'locked_by_user' => $lockedByUser,
                'is_modal' => $isModal,
            ], null, 423);
        }

        $this->lockingService->acquireLock($newsletter, $userId);

        $project = $newsletter->project;
        $events = Event::with('project')
            ->whereIn('project_id', $projects->pluck('id')->toArray())
            ->orderBy('event_date', 'desc')
            ->get();

        return $this->view->render($response, 'newsletters/edit.twig', [
            'newsletter' => $newsletter,
            'project' => $project,
            'projects' => $projects,
            'events' => $events,
            'is_modal' => $isModal,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $data = $request->getParsedBody();
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $response->withStatus(404);
        }

        if (!$this->lockingService->isLockedBy($newsletter, $userId)) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $projectId = !empty($data['project_id']) ? (int) $data['project_id'] : (int) $newsletter->project_id;
        $projects = $this->getAccessibleProjects($userId);
        $canAccessProject = $projects->contains(function ($project) use ($projectId) {
            return (int) $project->id === $projectId;
        });

        if (!$canAccessProject) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $eventId = null;
        if (!empty($data['event_id'])) {
            $requestedEventId = (int) $data['event_id'];
            $event = Event::where('id', $requestedEventId)
                ->where('project_id', $projectId)
                ->first();
            if ($event) {
                $eventId = $requestedEventId;
            }
        }

        $newsletter->update([
            'project_id' => $projectId,
            'title' => $data['title'] ?? $newsletter->title,
            'content_html' => $data['content_html'] ?? $newsletter->content_html,
            'event_id' => $eventId,
        ]);

        $recipients = $this->recipientService->resolveRecipients($projectId, (int) ($eventId ?? 0));
        $this->recipientService->setRecipients($newsletter, $recipients->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all());

        $_SESSION['success'] = 'Newsletter aktualisiert';

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Newsletter aktualisiert',
        ]);
    }

    public function preview(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $queryParams = $request->getQueryParams();
        $isModal = ((string) ($queryParams['modal'] ?? '0')) === '1';
        $newsletter = Newsletter::find($id);

        if (!$newsletter) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'newsletters/preview.twig', [
            'newsletter' => $newsletter,
            'is_modal' => $isModal,
        ]);
    }

    public function send(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter || !$newsletter->isDraft()) {
            return $response->withStatus(404);
        }

        if (!$this->lockingService->isLockedBy($newsletter, $userId)) {
            return $response->withStatus(403);
        }

        $this->lockingService->releaseLock($newsletter);

        try {
            $this->newsletterService->send($newsletter, $userId);
            $_SESSION['success'] = 'Newsletter versendet';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Versand: ' . $e->getMessage();
            return $response->withStatus(500);
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
        $data = $request->getParsedBody();
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter) {
            return $response->withStatus(404);
        }

        $template = NewsletterTemplate::create([
            'name' => $data['template_name'] ?? $newsletter->title,
            'description' => $data['template_description'] ?? '',
            'content_html' => $newsletter->content_html,
            'project_id' => $newsletter->project_id,
            'category' => $data['template_category'] ?? 'general',
            'created_by' => $userId,
        ]);

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

        if (!$this->lockingService->isLockedBy($newsletter, $userId)) {
            return $response->withStatus(403);
        }

        $newsletter->delete();
        $_SESSION['success'] = 'Newsletter-Entwurf gelöscht';

        return $response->withHeader(
            'Location',
            "/newsletters?project_id={$newsletter->project_id}&status=" . Newsletter::STATUS_DRAFT
        )
            ->withStatus(302);
    }
}
