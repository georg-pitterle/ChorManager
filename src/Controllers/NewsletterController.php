<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Newsletter;
use App\Models\NewsletterTemplate;
use App\Models\NewsletterArchive;
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

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;
        $status = $queryParams['status'] ?? 'draft';

        if (!$projectId) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $query = Newsletter::query()->where('project_id', $projectId);

        if (in_array($status, ['draft', 'scheduled', 'sent', 'archived'])) {
            $query->where('status', $status);
        }

        $newsletters = $query->orderBy('created_at', 'desc')->get();

        $project = Project::find($projectId);
        if (!$project) {
            return $response->withStatus(404);
        }

        $userId = $_SESSION['user_id'] ?? null;

        return $this->view->render($response, 'newsletters/index.twig', [
            'newsletters' => $newsletters,
            'project' => $project,
            'status' => $status,
            'user_id' => $userId,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $projectId = !empty($queryParams['project_id']) ? (int)$queryParams['project_id'] : null;

        if (!$projectId) {
            return $response->withStatus(400);
        }

        $project = Project::find($projectId);
        if (!$project) {
            return $response->withStatus(404);
        }

        $events = Event::where('project_id', $projectId)->orderBy('event_date', 'desc')->get();
        $templates = NewsletterTemplate::where('project_id', $projectId)
            ->orWhereNull('project_id')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return $this->view->render($response, 'newsletters/create.twig', [
            'project' => $project,
            'events' => $events,
            'templates' => $templates,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $projectId = (int)($data['project_id'] ?? 0);
        $userId = $_SESSION['user_id'] ?? null;

        if (!$projectId || !$userId) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $project = Project::find($projectId);
        if (!$project) {
            return $response->withStatus(404);
        }

        $newsletter = Newsletter::create([
            'project_id' => $projectId,
            'event_id' => !empty($data['event_id']) ? (int)$data['event_id'] : null,
            'title' => $data['title'] ?? 'Untitled Newsletter',
            'content_html' => $data['content_html'] ?? '',
            'status' => 'draft',
            'created_by' => $userId,
        ]);

        $recipients = $this->recipientService->resolveRecipients($projectId, (int)($data['event_id'] ?? 0));
        $newsletter->recipient_count = count($recipients);
        $newsletter->save();

        return $response->withStatus(201)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\GuzzleHttp\Psr7\stream_for(json_encode([
                'id' => $newsletter->id,
                'redirect' => "/newsletters/{$newsletter->id}/edit?project_id={$projectId}",
            ])));
    }

    public function edit(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $queryParams = $request->getQueryParams();
        $projectId = (int)($queryParams['project_id'] ?? 0);

        $newsletter = Newsletter::find($id);
        if (!$newsletter || $newsletter->project_id !== $projectId) {
            return $response->withStatus(404);
        }

        $userId = $_SESSION['user_id'] ?? null;
        $canEdit = $this->lockingService->canEdit($newsletter, $userId);

        if (!$canEdit) {
            $lockedByUser = User::find($newsletter->locked_by);
            return $this->view->render($response, 'newsletters/locked.twig', [
                'newsletter' => $newsletter,
                'locked_by_user' => $lockedByUser,
            ], null, 423);
        }

        $this->lockingService->acquireLock($newsletter, $userId);

        $project = $newsletter->project;
        $events = Event::where('project_id', $projectId)->orderBy('event_date', 'desc')->get();

        return $this->view->render($response, 'newsletters/edit.twig', [
            'newsletter' => $newsletter,
            'project' => $project,
            'events' => $events,
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

        $newsletter->update([
            'title' => $data['title'] ?? $newsletter->title,
            'content_html' => $data['content_html'] ?? $newsletter->content_html,
            'event_id' => !empty($data['event_id']) ? (int)$data['event_id'] : null,
        ]);

        $_SESSION['success'] = 'Newsletter aktualisiert';

        return $response->withHeader('Content-Type', 'application/json')
            ->withBody(\GuzzleHttp\Psr7\stream_for(json_encode([
                'success' => true,
                'message' => 'Newsletter aktualisiert',
            ])));
    }

    public function preview(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $newsletter = Newsletter::find($id);

        if (!$newsletter) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'newsletters/preview.twig', [
            'newsletter' => $newsletter,
        ]);
    }

    public function send(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter || $newsletter->status !== 'draft') {
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

        return $response->withHeader('Location', "/newsletters?project_id={$newsletter->project_id}&status=sent")
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

        return $response->withStatus(201)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\GuzzleHttp\Psr7\stream_for(json_encode([
                'success' => true,
                'template_id' => $template->id,
            ])));
    }

    public function getTemplate(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $template = NewsletterTemplate::find($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        return $response->withHeader('Content-Type', 'application/json')
            ->withBody(\GuzzleHttp\Psr7\stream_for(json_encode([
                'id' => $template->id,
                'name' => $template->name,
                'content_html' => $template->content_html,
            ])));
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
            return $response->withHeader('Content-Type', 'application/json')
                ->withBody(\GuzzleHttp\Psr7\stream_for(json_encode([
                    'locked' => false,
                ])));
        }

        $lockedByUser = User::find($newsletter->locked_by);

        return $response->withHeader('Content-Type', 'application/json')
            ->withBody(\GuzzleHttp\Psr7\stream_for(json_encode([
                'locked' => true,
                'locked_by_user' => $lockedByUser ? $lockedByUser->first_name . ' ' . $lockedByUser->last_name : 'Unknown',
                'locked_at' => $newsletter->locked_at->format('Y-m-d H:i:s'),
                'is_me' => $newsletter->locked_by === $userId,
            ])));
    }

    public function archiveIndex(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return $response->withStatus(403);
        }

        $archived = NewsletterArchive::where('user_id', $userId)
            ->with('newsletter')
            ->orderBy('sent_at', 'desc')
            ->get();

        return $this->view->render($response, 'newsletters/archive.twig', [
            'archived_newsletters' => $archived,
        ]);
    }

    public function deleteDraft(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $userId = $_SESSION['user_id'] ?? null;

        $newsletter = Newsletter::find($id);
        if (!$newsletter || $newsletter->status !== 'draft') {
            return $response->withStatus(404);
        }

        if (!$this->lockingService->isLockedBy($newsletter, $userId)) {
            return $response->withStatus(403);
        }

        $newsletter->delete();
        $_SESSION['success'] = 'Newsletter-Entwurf gelöscht';

        return $response->withHeader('Location', "/newsletters?project_id={$newsletter->project_id}&status=draft")
            ->withStatus(302);
    }
}
