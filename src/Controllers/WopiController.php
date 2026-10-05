<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\FileFolderShare;
use App\Models\User;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileActor;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\NameFormatterService;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use App\Services\Office\WopiAccess;
use App\Services\Office\WopiTimestamp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;

/**
 * WOPI-Host für Collabora Online: Dateiinfo, Inhalt lesen, Inhalt speichern.
 *
 * Collabora hat keine Sitzung bei uns. Ausgewiesen wird sich mit dem Token aus
 * der Editor-Seite (Query-Parameter `access_token`), und jeder Aufruf prüft die
 * aktuellen Rechte neu - ein entzogenes Recht wirkt beim nächsten Speichern.
 * Das Token steht in keiner Logzeile.
 */
final class WopiController
{
    /** Statuscodes aus FileManagementException, die Collabora unverändert bekommt. */
    private const PASSED_THROUGH = [400, 403, 404, 409, 413, 422];

    public function __construct(
        private readonly OfficeTokenService $tokens,
        private readonly FileService $files,
        private readonly FileAccessService $access,
        private readonly FileStorageRegistry $storages,
        private readonly OfficeDiscovery $discovery,
        private readonly OfficeSettings $settings,
        private readonly NameFormatterService $names,
        private readonly LoggerInterface $logger
    ) {
    }

    public function checkFileInfo(Request $request, Response $response, array $args): Response
    {
        return $this->guarded($request, $response, (int) $args['id'], FileFolderShare::LEVEL_READ, function (
            WopiAccess $access
        ) use ($response): Response {
            $file = $access->file;

            return $this->json($response, [
                'BaseFileName' => (string) $file->name,
                'Size' => (int) $file->size,
                'Version' => (string) $file->current_version_id,
                'LastModifiedTime' => WopiTimestamp::of($file->currentVersion),
                'OwnerId' => (string) ($file->created_by ?? ''),
                'UserId' => (string) $access->user->id,
                'UserFriendlyName' => $this->names->formatPerson($access->user),
                'UserCanWrite' => $access->canWrite,
                'UserCanNotWriteRelative' => true,
                'PostMessageOrigin' => $this->settings->appOrigin(),
            ]);
        });
    }

    public function getFile(Request $request, Response $response, array $args): Response
    {
        return $this->guarded($request, $response, (int) $args['id'], FileFolderShare::LEVEL_READ, function (
            WopiAccess $access
        ) use ($response): Response {
            $version = $access->file->currentVersion;
            if ($version === null) {
                return $response->withStatus(404);
            }
            $stream = $this->storages->for((string) $version->storage_driver)
                ->readStream((string) $version->storage_path);

            return $response
                ->withBody(new Stream($stream))
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('X-WOPI-ItemVersion', (string) $version->id);
        });
    }

    public function putFile(Request $request, Response $response, array $args): Response
    {
        return $this->guarded($request, $response, (int) $args['id'], FileFolderShare::LEVEL_EDIT, function (
            WopiAccess $access
        ) use (
            $request,
            $response
): Response {
            $file = $access->file;
            if (!$access->canWrite) {
                return $response->withStatus(403);
            }

            // Den Zeitstempel prüft FileService unter der Sperre der Dateizeile.
            $known = $request->getHeaderLine('X-COOL-WOPI-Timestamp');
            $body = $request->getBody();

            // Über post_max_size verwirft PHP den Rumpf: Angekündigt ist etwas,
            // angekommen nichts. Das ist "zu groß", nicht "leer" - Collabora soll
            // die passende Meldung zeigen.
            if ((int) $request->getHeaderLine('Content-Length') > 0 && $body->getSize() === 0) {
                $this->logger->notice('Office save rejected.', [
                    'event' => 'office.save_rejected',
                    'file_id' => (int) $file->id,
                    'user_id' => $access->actor->userId,
                    'status' => 413,
                ]);

                return $response->withStatus(413);
            }
            $upload = new UploadedFile($body, (string) $file->name, 'application/octet-stream', $body->getSize());
            $endsSession = strtolower($request->getHeaderLine('X-COOL-WOPI-IsExitSave')) === 'true';

            try {
                $version = $this->files->saveFromOffice(
                    $access->actor,
                    (int) $file->id,
                    $upload,
                    $endsSession,
                    $known !== '' ? $known : null
                );
            } catch (FileManagementException $exception) {
                if ($exception->status === 409) {
                    $this->logger->notice('Office save conflict.', [
                        'event' => 'office.save_conflict',
                        'file_id' => (int) $file->id,
                        'user_id' => $access->actor->userId,
                    ]);

                    return $this->json($response->withStatus(409), ['COOLStatusCode' => 1010]);
                }
                $status = in_array($exception->status, self::PASSED_THROUGH, true) ? $exception->status : 500;
                $this->logger->notice('Office save rejected.', [
                    'event' => 'office.save_rejected',
                    'file_id' => (int) $file->id,
                    'user_id' => $access->actor->userId,
                    'status' => $status,
                ]);

                return $response->withStatus($status);
            }

            return $this->json($response, ['LastModifiedTime' => WopiTimestamp::of($version)]);
        });
    }

    /**
     * Token auflösen, Person und Rechte prüfen, dann die Aktion ausführen.
     * 401: Token unbrauchbar oder Person deaktiviert. 403/404: Rechte, wie in
     * der Dateiablage - wer die Datei gar nicht mehr sieht, bekommt 404.
     *
     * @param \Closure(WopiAccess): Response $action
     */
    private function guarded(
        Request $request,
        Response $response,
        int $fileId,
        int $requiredLevel,
        \Closure $action
    ): Response {
        $plain = (string) ($request->getQueryParams()['access_token'] ?? '');
        $token = $this->tokens->resolve($plain, $fileId);
        $user = $token === null ? null : User::find($token->user_id);
        $actor = $user === null ? null : FileActor::forUser($user);
        if ($user === null || $actor === null) {
            return $response->withStatus(401);
        }

        try {
            $file = $this->files->findWithFileLevel($actor, $fileId, $requiredLevel);
        } catch (FileManagementException $exception) {
            return $response->withStatus($exception->status === 403 ? 403 : 404);
        }

        $level = $this->access->fileLevelFor($actor, $file);
        $canWrite = $level >= FileFolderShare::LEVEL_EDIT
            && ($this->discovery->actionFor((string) $file->name)?->canEdit ?? false);

        return $action(new WopiAccess($user, $actor, $file, $canWrite));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
