<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Antwort der Anhang-Endpunkte an die Ablegefläche (public/js/upload-dropzone.js).
 *
 * Die Fläche schickt jede Datei einzeln mit `Accept: application/json` und zeigt
 * die Rückmeldung an der Datei. Eine Flash-Meldung wäre dort falsch: Sie stünde
 * nach dem Neuladen oben auf der Seite, losgelöst von der Datei, und bei 24
 * Dateien bliebe ohnehin nur die letzte übrig.
 */
trait AttachmentUploadJson
{
    /**
     * @param array{stored: int, error: ?string, ids: list<int>} $result Ergebnis von EntityAttachmentService::storeUploads()
     */
    private function attachmentUploadJson(Response $response, array $result): Response
    {
        if ($result['stored'] > 0) {
            return $this->attachmentJson($response, [
                'ok' => true,
                'stored' => $result['stored'],
                'error' => $result['error'],
            ], 200);
        }

        if ($result['error'] !== null) {
            return $this->attachmentUploadJsonError($response, $result['error'], 422);
        }

        // Über post_max_size verwirft PHP den ganzen Rumpf; dann kommt gar nichts an.
        return $this->attachmentUploadJsonError($response, 'Es ist keine Datei angekommen. Ist sie zu groß?', 413);
    }

    private function attachmentUploadJsonError(Response $response, string $error, int $status): Response
    {
        return $this->attachmentJson($response, ['ok' => false, 'error' => $error], $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function attachmentJson(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
