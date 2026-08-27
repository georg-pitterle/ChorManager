<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Event;
use App\Models\Newsletter;
use App\Models\NewsletterTemplate;
use App\Models\NewsletterTemplateRecipientSource;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Persistence\NewsletterTemplatePersistence;
use App\Queries\NewsletterTemplateQuery;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Services\NewsletterRecipientService;
use Illuminate\Database\Eloquent\Collection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Verwaltung der Newsletter-Vorlagen. Der Zugang hängt allein am Recht
 * can_manage_newsletters, das die Routengruppe absichert.
 *
 * Eine Vorlage hält neben dem Inhalt auch die Newsletter-Einstellungen fest:
 * Kontext (Projekt), vorgeschlagener Titel und Empfängerquellen.
 */
class NewsletterTemplateController
{
    /**
     * Formularfeld je Quellentyp. Die Mehrfachauswahl im Vorlagenformular
     * liefert je Typ eine flache Liste von Referenz-IDs.
     */
    private const SOURCE_FIELDS = [
        'source_project_members' => NewsletterTemplateRecipientSource::TYPE_PROJECT_MEMBERS,
        'source_event_attendees' => NewsletterTemplateRecipientSource::TYPE_EVENT_ATTENDEES,
        'source_role' => NewsletterTemplateRecipientSource::TYPE_ROLE,
        'source_user' => NewsletterTemplateRecipientSource::TYPE_USER,
    ];

    private Twig $view;
    private HtmlSanitizer $htmlSanitizer;
    private NewsletterTemplateQuery $templateQuery;
    private NewsletterTemplatePersistence $templatePersistence;
    private NewsletterRecipientService $recipientService;
    private NameFormatterService $nameFormatter;

    public function __construct(
        Twig $view,
        HtmlSanitizer $htmlSanitizer,
        NewsletterTemplateQuery $templateQuery,
        NewsletterTemplatePersistence $templatePersistence,
        NewsletterRecipientService $recipientService,
        NameFormatterService $nameFormatter
    ) {
        $this->view = $view;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->templateQuery = $templateQuery;
        $this->templatePersistence = $templatePersistence;
        $this->recipientService = $recipientService;
        $this->nameFormatter = $nameFormatter;
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
     * @return array{ok:bool, payload:array<string, string|null>}
     */
    private function validateTemplateInput(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $contentHtml = $this->htmlSanitizer->sanitizeNewsletterHtml($data['content_html'] ?? '');
        $description = trim((string) ($data['description'] ?? ''));
        $defaultTitle = trim((string) ($data['default_title'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255 || $contentHtml === '') {
            return ['ok' => false, 'payload' => []];
        }

        if (mb_strlen($defaultTitle) > 255) {
            return ['ok' => false, 'payload' => []];
        }

        return [
            'ok' => true,
            'payload' => [
                'name' => $name,
                'default_title' => $defaultTitle === '' ? null : $defaultTitle,
                'content_html' => $contentHtml,
                'description' => $description,
            ],
        ];
    }

    /**
     * Baut die Empfängerquellen aus dem Vorlagenformular. Ein leeres Formularfeld
     * heißt "keine Quelle dieses Typs" - deshalb ist das Fehlen eines Feldes kein
     * Grund, die gespeicherte Auswahl stehen zu lassen.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{type:string, reference_id:int}>
     */
    private function recipientSourcesFromInput(array $data): array
    {
        $raw = [];

        foreach (self::SOURCE_FIELDS as $field => $type) {
            $values = $data[$field] ?? [];
            if (!is_array($values)) {
                $values = [$values];
            }

            foreach ($values as $value) {
                $raw[] = ['type' => $type, 'reference_id' => (int) $value];
            }
        }

        return $this->recipientService->normalizeSources($raw);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function projectIdFromInput(array $data): ?int
    {
        if (($data['project_id'] ?? '') === '') {
            return null;
        }

        return (int) $data['project_id'];
    }

    /**
     * @return Collection<int, User>
     */
    private function activeUsersInNameOrder(): Collection
    {
        $query = User::query()->where('is_active', 1);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    public function index(Request $request, Response $response): Response
    {
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        // Dieselben Auswahllisten wie in edit(): Der Erstellen-Dialog bietet die
        // Newsletter-Einstellungen samt Empfängerquellen an, sonst müsste eine neue
        // Vorlage erst gespeichert und danach ein zweites Mal geöffnet werden.
        return $this->view->render($response, 'newsletters/templates_index.twig', [
            'projects' => Project::query()->orderBy('name')->get(),
            'templates' => NewsletterTemplate::query()->orderBy('name')->get(),
            'events' => Event::query()->orderBy('starts_at', 'desc')->get(),
            'roles' => Role::query()->orderBy('name')->get(),
            'users' => $this->activeUsersInNameOrder(),
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

        $projectId = $this->projectIdFromInput($data);

        if ($projectId !== null && !Project::query()->where('id', $projectId)->exists()) {
            $message = 'Das gewählte Projekt existiert nicht.';
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = $message;
                return $response->withHeader('Location', '/newsletters/templates')->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => $message], 422);
        }

        $template = $this->templatePersistence->createTemplate(
            $validation['payload'],
            $userId,
            $projectId,
            $this->recipientSourcesFromInput($data)
        );

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
            'projects' => Project::query()->orderBy('name')->get(),
            'events' => Event::query()->orderBy('starts_at', 'desc')->get(),
            'roles' => Role::query()->orderBy('name')->get(),
            'users' => $this->activeUsersInNameOrder(),
            'recipient_sources' => $this->templatePersistence->getRecipientSources($template),
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

        $data = (array) $request->getParsedBody();
        $validation = $this->validateTemplateInput($data);
        if (!$validation['ok']) {
            if (!$this->expectsJson($request)) {
                $_SESSION['error'] = 'Ungültige Vorlagendaten';
                return $response
                    ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                    ->withStatus(302);
            }

            return $this->jsonResponse($response, ['error' => 'Ungültige Vorlagendaten'], 422);
        }

        $payload = $validation['payload'];

        if (array_key_exists('project_id', $data)) {
            $projectId = $this->projectIdFromInput($data);

            if ($projectId !== null && !Project::query()->where('id', $projectId)->exists()) {
                $message = 'Das gewählte Projekt existiert nicht.';
                if (!$this->expectsJson($request)) {
                    $_SESSION['error'] = $message;
                    return $response
                        ->withHeader('Location', '/newsletters/templates/' . $template->id . '/edit')
                        ->withStatus(302);
                }

                return $this->jsonResponse($response, ['error' => $message], 422);
            }

            $payload['project_id'] = $projectId;
        }

        $this->templatePersistence->updateTemplate(
            $template,
            $payload,
            $this->recipientSourcesFromInput($data)
        );
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
            'default_title' => $template->default_title,
            'project_id' => $template->project_id === null ? null : (int) $template->project_id,
            'content_html' => $template->content_html,
            'recipient_sources' => $this->templatePersistence->getRecipientSources($template),
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

        $defaultTitle = trim((string) $newsletter->title);

        $template = $this->templatePersistence->createTemplate(
            [
                'name' => $templateName,
                'default_title' => $defaultTitle === '' ? null : mb_substr($defaultTitle, 0, 255),
                'description' => $templateDescription,
                'content_html' => $templateContentHtml,
            ],
            $userId,
            $newsletter->project_id === null ? null : (int) $newsletter->project_id,
            $this->recipientService->getSources($newsletter)
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
