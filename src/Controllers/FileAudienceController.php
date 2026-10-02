<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Models\FileFolderShare;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileManagementException;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Trefferzahl einer Freigabe-Zeile, während sie bearbeitet wird. Nur die Zahl,
 * keine Namen; nur für Personen, die irgendwo Freigaben verwalten dürfen.
 */
final class FileAudienceController
{
    use FileControllerSupport;

    public function __construct(
        private readonly FileAccessService $access,
        private readonly AudienceFilterService $filters,
        private readonly AudienceFilterNormalizer $normalizer
    ) {
    }

    public function preview(Request $request, Response $response): Response
    {
        try {
            $actor = $this->actor();
        } catch (FileManagementException $exception) {
            return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], 403);
        }

        $managesSomething = $actor->isFileAdmin || in_array(
            FileFolderShare::LEVEL_MANAGE,
            $this->access->folderLevels($actor),
            true
        );
        if (!$managesSomething) {
            return $this->json($response, ['ok' => false, 'error' => 'Dafür fehlt die Berechtigung.'], 403);
        }

        $body = $request->getParsedBody();
        $row = self::parseShareRows([is_array($body) ? $body : []])[0];
        try {
            $conditions = $this->normalizer->normalize($row);
        } catch (InvalidAudienceFilterException $exception) {
            return $this->json($response, ['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        // Der Filter existiert nur für diese Zählung: anlegen, zählen, zurückrollen.
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            $filter = $this->filters->create($conditions);
            $count = $this->filters->membersQuery((int) $filter->id)->count();
        } finally {
            $connection->rollBack();
        }

        return $this->json($response, ['ok' => true, 'count' => $count]);
    }
}
