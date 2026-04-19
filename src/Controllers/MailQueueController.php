<?php

namespace App\Controllers;

use App\Services\MailQueueAdminService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Exception;

class MailQueueController
{
    private MailQueueAdminService $adminService;
    private Twig $view;

    public function __construct(Twig $view, MailQueueAdminService $adminService)
    {
        $this->view = $view;
        $this->adminService = $adminService;
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

        $entries = $this->adminService->listEntries($filters);
        $stats = $this->adminService->getStats();

        return $this->view->render(
            $response,
            'admin/mail_queue/index.twig',
            [
                'entries' => $entries,
                'filters' => $filters,
                'stats' => $stats,
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
        try {
            $this->adminService->retrySingle((int)$args['id']);

            // Redirect with success message
            return $response
                ->withHeader('Location', '/admin/mail-queue')
                ->withStatus(302);
        } catch (Exception $e) {
            $response->getBody()->write('Error: ' . $e->getMessage());
            return $response->withStatus(400);
        }
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
