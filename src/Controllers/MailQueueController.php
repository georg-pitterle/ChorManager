<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MailQueueAdminService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Exception;

class MailQueueController
{
    private MailQueueAdminService $adminService;
    private Twig $view;
    private LoggerInterface $logger;

    public function __construct(Twig $view, MailQueueAdminService $adminService, ?LoggerInterface $logger = null)
    {
        $this->view = $view;
        $this->adminService = $adminService;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * List queue entries.
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $filters = [
            'status' => $params['status'] ?? null,
            'mail_type' => $params['mail_type'] ?? null,
            'search' => $params['search'] ?? null,
            'from_date' => $params['from_date'] ?? null,
            'to_date' => $params['to_date'] ?? null,
        ];

        $perPage = MailQueueAdminService::normalizePerPage($params['per_page'] ?? null);
        $pageCount = $this->adminService->pageCount($filters + ['per_page' => $perPage]);
        // Eine Seitenzahl jenseits des Bestands zeigte eine leere Liste ohne
        // erkennbaren Grund - etwa nach dem Zurückblättern mit engerem Filter.
        $page = min(MailQueueAdminService::normalizePage($params['page'] ?? null), $pageCount);

        $pagedFilters = $filters + ['per_page' => $perPage, 'page' => $page];
        $entries = $this->adminService->listEntries($pagedFilters);
        $stats = $this->adminService->getStats();

        return $this->view->render(
            $response,
            'admin/mail_queue/index.twig',
            [
                'entries' => $entries,
                'filters' => $filters,
                'stats' => $stats,
                'total_entries' => $this->adminService->countEntries($filters),
                'page' => $page,
                'page_count' => $pageCount,
                'per_page' => $perPage,
            ]
        );
    }

    /**
     * Show single entry details.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $entry = $this->adminService->getEntry((int)$args['id']);

        if (!$entry) {
            $response->getBody()->write('Not Found');
            return $response->withStatus(404);
        }

        return $this->view->render(
            $response,
            'admin/mail_queue/show.twig',
            ['entry' => $entry]
        );
    }

    /**
     * Retry single entry (POST).
     */
    public function retrySingle(Request $request, Response $response, array $args): Response
    {
        $entryId = (int) $args['id'];

        try {
            $this->adminService->retrySingle($entryId);
            $_SESSION['success'] = 'Eintrag wurde erneut in die Warteschlange gestellt.';
        } catch (Exception $e) {
            // Vorher endete dieser Zweig auf einer nackten Textseite mit Status 400:
            // ohne Navigation, ohne Protokollzeile und mit dem rohen Treibertext als
            // einzigem Inhalt. Die übrige Warteschlangen-Verwaltung leitet zurück und
            // meldet über die Flash-Nachricht - dieser Zweig tut das jetzt auch.
            $this->logger->error('Retrying a mail queue entry failed.', [
                'event' => 'mail_queue.retry.failed',
                'mail_queue_id' => $entryId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Der Eintrag konnte nicht erneut zugestellt werden.';
        }

        return $response
            ->withHeader('Location', '/admin/mail-queue')
            ->withStatus(302);
    }

    /**
     * Retry all dead entries (POST).
     */
    public function retryAllDead(Request $request, Response $response): Response
    {
        $count = $this->adminService->retryAllDead();

        // Redirect with success message
        return $response
            ->withHeader('Location', '/admin/mail-queue?retried=' . $count)
            ->withStatus(302);
    }
}
