<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Storage\StorageUsageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Übersicht über den belegten Speicherplatz. Misst bei jedem Aufruf neu; die
 * Messung frischt nebenbei die Kurzfassung der Dashboard-Kachel auf.
 */
final class StorageController
{
    public function __construct(
        private readonly Twig $view,
        private readonly StorageUsageService $usage
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'storage/index.twig', [
            'report' => $this->usage->collect(),
        ]);
    }
}
