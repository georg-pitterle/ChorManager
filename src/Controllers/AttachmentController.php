<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Attachment;
use App\Services\AttachmentAccessRegistry;
use App\Services\AttachmentResponseFactory;
use App\Services\EntityAttachmentService;
use App\Util\AttachmentPreview;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Die eine Stelle, die Anhänge ausliefert.
 *
 * Bewusst ohne RoleMiddleware in Routes.php: welches Recht nötig ist, hängt
 * am `entity_type` der Zeile, nicht am Pfad. Die Entscheidung kann deshalb erst
 * fallen, wenn der Anhang geladen ist - dafür ist die Registry da.
 */
class AttachmentController
{
    private AttachmentAccessRegistry $access;
    private AttachmentResponseFactory $responses;
    private LoggerInterface $logger;

    public function __construct(
        AttachmentAccessRegistry $access,
        AttachmentResponseFactory $responses,
        LoggerInterface $logger
    ) {
        $this->access = $access;
        $this->responses = $responses;
        $this->logger = $logger;
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $metadata = $this->authorize($args);
        if ($metadata === null) {
            return $this->notFound($response, (int) ($args['id'] ?? 0), 'unknown');
        }

        $attachment = $this->loadContent($metadata);
        if ($attachment === null) {
            return $this->notFound($response, (int) $metadata->id, (string) $metadata->entity_type);
        }

        return $this->responses->download($response, $attachment);
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        $metadata = $this->authorize($args);
        if ($metadata === null) {
            return $this->notFound($response, (int) ($args['id'] ?? 0), 'unknown');
        }

        // Die Typprüfung steht vor dem Nachladen des Inhalts, nicht danach.
        // Sonst zöge eine abgelehnte Vorschau erst den ganzen BLOB durch den
        // Speicher, um ihn mit 415 zu verwerfen - bei einem Word-Dokument von
        // zehn Megabyte genau das, was die Reihenfolge in authorize() vermeidet.
        if (!AttachmentPreview::isInlineServable((string) $metadata->mime_type)) {
            $response->getBody()->write('Dieser Dateityp wird nicht im Browser angezeigt.');

            return $response->withStatus(415)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $attachment = $this->loadContent($metadata);
        if ($attachment === null) {
            return $this->notFound($response, (int) $metadata->id, (string) $metadata->entity_type);
        }

        return $this->responses->inline($response, $attachment, $request->getHeaderLine('Range'));
    }

    /**
     * Liefert die **Metadaten** des Anhangs, wenn die anfragende Person ihn
     * sehen darf, sonst null. `file_content` ist bewusst nicht dabei: der BLOB
     * wird erst nachgeladen, wenn alle Entscheidungen gefallen sind - wer ihn
     * vorher liest, zieht bei einem Vertrags-PDF zweistellige Megabyte durch
     * den Speicher, um sie gleich darauf zu verwerfen.
     *
     * @param array<string, mixed> $args
     */
    private function authorize(array $args): ?Attachment
    {
        $attachmentId = (int) ($args['id'] ?? 0);
        if ($attachmentId <= 0) {
            return null;
        }

        $metadata = Attachment::query()
            ->select(EntityAttachmentService::METADATA_COLUMNS)
            ->find($attachmentId);

        if ($metadata === null) {
            return null;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$this->access->mayAccess($metadata, $userId)) {
            return null;
        }

        return $metadata;
    }

    /**
     * Holt denselben Anhang noch einmal, diesmal mit Inhalt. Zwischen der
     * Prüfung und hier kann er gelöscht worden sein - dann gibt es nichts
     * auszuliefern und die Antwort ist dieselbe 404.
     */
    private function loadContent(Attachment $metadata): ?Attachment
    {
        return Attachment::find((int) $metadata->id);
    }

    /**
     * Fehlendes Recht und fehlender Datensatz geben dieselbe Antwort. Ein 403
     * verriete, dass es den Anhang gibt.
     *
     * Die Protokollzeile trägt bewusst keinen Dateinamen: der stammt von der
     * hochladenden Person und hat in einem Protokoll nichts verloren. Seit die
     * fünf alten Auslieferungswege weg sind, ist das hier die einzige Stelle
     * der Anwendung, an der ein Anhangzugriff abgelehnt wird.
     */
    private function notFound(Response $response, int $attachmentId, string $entityType): Response
    {
        $this->logger->info('Attachment access denied.', [
            'event' => 'attachment.access.denied',
            'attachment_id' => $attachmentId,
            'entity_type' => $entityType,
        ]);

        $response->getBody()->write('Datei nicht gefunden oder kein Zugriff.');

        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
