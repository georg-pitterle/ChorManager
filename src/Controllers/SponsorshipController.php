<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsorship;
use App\Models\Attachment;
use App\Util\UploadValidator;

class SponsorshipController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    private function handleAttachments(Request $request, int $sponsorshipId): void
    {
        $uploadedFiles = $request->getUploadedFiles();
        if (!isset($uploadedFiles['attachments'])) {
            return;
        }

        $files = $uploadedFiles['attachments'];
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $uploadError = UploadValidator::getUploadErrorMessage($file->getError(), 'Anhang');
            if ($uploadError !== null) {
                $_SESSION['error'] = $uploadError;
                continue;
            }

            if ($file->getError() === UPLOAD_ERR_OK) {
                $mimeType = UploadValidator::detectMimeType($file);
                $contents = $file->getStream()->getContents();
                $size = strlen($contents);

                // Validate file size and type
                $validation = UploadValidator::validateFileSize($size, $mimeType);
                if (!$validation['valid']) {
                    $_SESSION['error'] = $validation['error'];
                    continue;
                }

                Attachment::create([
                    'entity_type'    => 'sponsorship',
                    'entity_id'      => $sponsorshipId,
                    'filename'       => bin2hex(random_bytes(16)) . '_' . $file->getClientFilename(),
                    'original_name'  => $file->getClientFilename(),
                    'mime_type'      => UploadValidator::normalizeMimeType($mimeType),
                    'file_size'      => $size,
                    'file_content'   => $contents,
                ]);
            }
        }
    }

    public function create(Request $request, Response $response): Response
    {
        $data      = (array) $request->getParsedBody();
        $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        $amount    = trim($data['amount'] ?? '');

        if (!$sponsorId || $amount === '') {
            $_SESSION['error'] = 'Sponsor und Betrag sind Pflichtfelder.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        try {
            $sponsorship = Sponsorship::create([
                'sponsor_id'       => $sponsorId,
                'project_id'       => !empty($data['project_id']) ? (int) $data['project_id'] : null,
                'package_id'       => !empty($data['package_id']) ? (int) $data['package_id'] : null,
                'assigned_user_id' => !empty($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null,
                'amount'           => (float) str_replace(',', '.', $amount),
                'status'           => $data['status'] ?? 'prospect',
                'start_date'       => !empty($data['start_date']) ? $data['start_date'] : null,
                'end_date'         => !empty($data['end_date']) ? $data['end_date'] : null,
                'notes'            => trim($data['notes'] ?? '') ?: null,
            ]);

            $this->handleAttachments($request, $sponsorship->id);

            $_SESSION['success'] = 'Vereinbarung erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen: ';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        try {
            $sponsorship = Sponsorship::findOrFail($id);
            $sponsorId   = $sponsorship->sponsor_id;
            $providedSponsorId = (int) ($data['sponsor_id'] ?? 0);

            if ($providedSponsorId > 0 && $providedSponsorId !== (int) $sponsorship->sponsor_id) {
                $response->getBody()->write('Zugriff verweigert.');
                return $response->withStatus(403);
            }

            $sponsorship->update([
                'project_id'       => !empty($data['project_id']) ? (int) $data['project_id'] : null,
                'package_id'       => !empty($data['package_id']) ? (int) $data['package_id'] : null,
                'assigned_user_id' => !empty($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null,
                'amount'           => (float) str_replace(',', '.', $data['amount'] ?? '0'),
                'status'           => $data['status'] ?? $sponsorship->status,
                'start_date'       => !empty($data['start_date']) ? $data['start_date'] : null,
                'end_date'         => !empty($data['end_date']) ? $data['end_date'] : null,
                'notes'            => trim($data['notes'] ?? '') ?: null,
            ]);

            $this->handleAttachments($request, $sponsorship->id);

            $_SESSION['success'] = 'Vereinbarung erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        try {
            $sponsorship = Sponsorship::findOrFail($id);
            $sponsorId   = $sponsorship->sponsor_id;
            $providedSponsorId = (int) ($data['sponsor_id'] ?? 0);

            if ($providedSponsorId > 0 && $providedSponsorId !== (int) $sponsorship->sponsor_id) {
                $response->getBody()->write('Zugriff verweigert.');
                return $response->withStatus(403);
            }

            Attachment::where('entity_type', 'sponsorship')
                ->where('entity_id', $id)
                ->delete();
            $sponsorship->delete();
            $_SESSION['success'] = 'Vereinbarung erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function downloadAttachment(Request $request, Response $response, array $args): Response
    {
        $sponsorshipId = (int) $args['id'];
        $attachmentId  = (int) $args['attachment_id'];

        $attachment = Attachment::where('entity_type', 'sponsorship')->findOrFail($attachmentId);

        // IDOR-Schutz: Anhang muss zur angeforderten Vereinbarung gehören
        if ($attachment->entity_id !== $sponsorshipId) {
            $response->getBody()->write('Zugriff verweigert.');
            return $response->withStatus(403);
        }

        $response->getBody()->write($attachment->file_content);

        return $response
            ->withHeader('Content-Type', $attachment->mime_type)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . self::normalizeFileName((string) $attachment->original_name)
                    . '"; filename*=UTF-8\'\''
                    . rawurlencode(self::normalizeFileName((string) $attachment->original_name))
            );
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $sponsorshipId = (int) $args['id'];
        $attachmentId  = (int) $args['attachment_id'];
        $data          = (array) $request->getParsedBody();

        try {
            $attachment = Attachment::where('entity_type', 'sponsorship')->findOrFail($attachmentId);

            // IDOR-Schutz
            if ($attachment->entity_id !== $sponsorshipId) {
                $response->getBody()->write('Zugriff verweigert.');
                return $response->withStatus(403);
            }

            $sponsorId = Sponsorship::findOrFail($sponsorshipId)->sponsor_id;
            $attachment->delete();
            $_SESSION['success'] = 'Anhang erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    private static function normalizeFileName(string $name): string
    {
        $safe = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        $trimmed = trim($safe);
        return $trimmed !== '' ? $trimmed : 'download';
    }
}
