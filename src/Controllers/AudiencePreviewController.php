<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\FileFolderShare;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\AudienceFormInput;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Trefferzahl einer Zielgruppen-Zeile, während sie bearbeitet wird. Nur die
 * Zahl, keine Namen; nur für Personen, die irgendwo Zielgruppen festlegen:
 * Termine bearbeiten, Newsletter bearbeiten oder Freigaben verwalten.
 */
final class AudiencePreviewController
{
    public function __construct(
        private readonly FileAccessService $access,
        private readonly AudienceFilterService $filters,
        private readonly AudienceFilterNormalizer $normalizer
    ) {
    }

    public function preview(Request $request, Response $response): Response
    {
        if (!$this->maySetAudiences()) {
            return $this->json($response, ['ok' => false, 'error' => 'Dafür fehlt die Berechtigung.'], 403);
        }

        $body = $request->getParsedBody();
        $row = AudienceFormInput::rows([is_array($body) ? $body : []])[0];
        try {
            $conditions = $this->normalizer->normalize($row);
        } catch (InvalidAudienceFilterException $exception) {
            return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        // Gezählt wird direkt aus den Bedingungen, gespeichert wird nichts.
        $count = $this->filters->membersQueryForSets([$conditions])->count();

        return $this->json($response, ['ok' => true, 'count' => $count]);
    }

    private function maySetAudiences(): bool
    {
        if ((bool) ($_SESSION['can_manage_events'] ?? false) || (bool) ($_SESSION['can_manage_newsletters'] ?? false)) {
            return true;
        }

        $actor = FileActor::fromSession($_SESSION ?? []);
        if ($actor === null) {
            return false;
        }

        return $actor->isFileAdmin
            || in_array(FileFolderShare::LEVEL_MANAGE, $this->access->folderLevels($actor), true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
