<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Newsletter;
use App\Models\NewsletterTemplate;
use App\Models\Project;
use App\Persistence\NewsletterTemplatePersistence;
use App\Queries\NewsletterTemplateQuery;
use App\Services\HtmlSanitizer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Verwaltung der Newsletter-Vorlagen. Der Zugang hängt allein am Recht
 * can_manage_newsletters, das die Routengruppe absichert.
 */
class NewsletterTemplateController
{
    private Twig $view;
    private HtmlSanitizer $htmlSanitizer;
    private NewsletterTemplateQuery $templateQuery;
    private NewsletterTemplatePersistence $templatePersistence;

    public function __construct(
        Twig $view,
        HtmlSanitizer $htmlSanitizer,
        NewsletterTemplateQuery $templateQuery,
        NewsletterTemplatePersistence $templatePersistence
    ) {
        $this->view = $view;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->templateQuery = $templateQuery;
        $this->templatePersistence = $templatePersistence;
    }

    /**
     * @param array<string, mixed> $payload
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

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool, payload:array<string, string>}
     */
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

    public function index(Request $request, Response $response): Response
    {
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'newsletters/templates_index.twig', [
            'projects' => Project::query()->orderBy('name')->get(),
            'templates' => NewsletterTemplate::query()->orderBy('name')->get(),
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $validation = $this->validateTemplateInput($data);
        if (!$validation['ok']) {
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = 'Ungültige Vorlagendaten';
                return $response->withHeader('Location', '/newsletters/templates')->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => 'Ungültige Vorlagendaten'], 422);
        }

        $projectId = null;
        if (($data['project_id'] ?? '') !== '') {
            $projectId = (int) $data['project_id'];
        }

        if ($projectId !== null && !Project::query()->where('id', $projectId)->exists()) {
            $message = 'Das gewählte Projekt existiert nicht.';
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = $message;
                return $response->withHeader('Location', '/newsletters/templates')->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => $message], 422);
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

    public function edit(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $isModal = ((string) ($request->getQueryParams()['modal'] ?? '0')) === '1';

        return $this->view->render($response, 'newsletters/templates_edit.twig', [
            'template' => $template,
            'is_modal' => $isModal,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        $validation = $this->validateTemplateInput((array) $request->getParsedBody());
        if (!$validation['ok']) {
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = 'Ungültige Vorlagendaten';
                return $response
                    ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                    ->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => 'Ungültige Vorlagendaten'], 422);
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

    public function clone(Request $request, Response $response): Response
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

    public function show(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $template = $this->templateQuery->findById($id);

        if (!$template) {
            return $response->withStatus(404);
        }

        return $this->jsonResponse($response, [
            'id' => $template->id,
            'name' => $template->name,
            'content_html' => $template->content_html,
        ]);
    }

    public function storeFromNewsletter(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('id');
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $newsletter = Newsletter::find($id);

        if (!$newsletter) {
            return $response->withStatus(404);
        }

        if ($userId <= 0) {
            return $response->withStatus(403);
        }

        $data = (array) $request->getParsedBody();
        $templateName = trim((string) ($data['template_name'] ?? $newsletter->title));
        $templateDescription = trim((string) ($data['template_description'] ?? ''));
        $templateContentHtml = $this->htmlSanitizer->sanitizeNewsletterHtml($newsletter->content_html);

        if ($templateName === '' || mb_strlen($templateName) > 255 || trim(strip_tags($templateContentHtml)) === '') {
            if ($this->expectsJson($request)) {
                return $this->jsonResponse($response, ['error' => 'Ungültige Vorlagendaten.'], 422);
            }

            $_SESSION['error'] = 'Ungültige Vorlagendaten.';
            return $response->withHeader('Location', '/newsletters/' . $id . '/edit')->withStatus(302);
        }

        $template = $this->templatePersistence->createTemplate(
            [
                'name' => $templateName,
                'description' => $templateDescription,
                'content_html' => $templateContentHtml,
            ],
            $userId,
            $newsletter->project_id === null ? null : (int) $newsletter->project_id
        );

        if (!$this->expectsJson($request)) {
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
}
