<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Project;
use App\Models\Sponsorship;
use App\Models\Attachment;
use App\Policies\SponsoringPolicy;
use App\Util\AmountNormalizer;
use App\Util\SponsorshipStatus;
use App\Util\UploadValidator;
use Psr\Log\LoggerInterface;
use App\Util\DownloadFileName;

class SponsorshipController
{
    public const AMOUNT_ERROR = 'Ungültiger Betrag. Bitte eine Zahl ab 0 eingeben.';
    public const STATUS_ERROR = 'Ungültiger Status für die Vereinbarung.';
    public const PROJECT_ERROR = 'Vereinbarungen lassen sich nur zu einem laufenden Projekt erfassen.';

    private Twig $view;
    private LoggerInterface $logger;
    private SponsoringPolicy $policy;

    public function __construct(Twig $view, LoggerInterface $logger, SponsoringPolicy $policy)
    {
        $this->view = $view;
        $this->logger = $logger;
        $this->policy = $policy;
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
                    $this->logger->warning('File upload rejected.', [
                        'event' => 'security.upload.rejected',
                        'reason' => $validation['reason'],
                    ]);
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
        if (!$this->policy->canCreateSponsorship()) {
            return $this->deny($response);
        }

        $data      = (array) $request->getParsedBody();
        $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        $amount    = trim($data['amount'] ?? '');

        if (!$sponsorId || $amount === '') {
            $_SESSION['error'] = 'Sponsor und Betrag sind Pflichtfelder.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $normalizedAmount = self::validateAmount($amount);
        if ($normalizedAmount === null) {
            $_SESSION['error'] = self::AMOUNT_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        // Ohne diese Prüfung landet ein unbekannter Wert direkt im Enum der
        // Spalte: MySQL weist ihn ab, und der Fehler kam bisher als
        // nichtssagendes "Fehler beim Anlegen" zurück.
        $status = (string) ($data['status'] ?? SponsorshipStatus::DEFAULT);
        if (!SponsorshipStatus::isValid($status)) {
            $_SESSION['error'] = self::STATUS_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $projectId = !empty($data['project_id']) ? (int) $data['project_id'] : null;
        if (!$this->policy->canUseProject($projectId)) {
            $_SESSION['error'] = self::PROJECT_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $period = $this->resolvePeriod($data, $projectId);

        try {
            $sponsorship = Sponsorship::create([
                'sponsor_id'       => $sponsorId,
                'project_id'       => $projectId,
                'package_id'       => !empty($data['package_id']) ? (int) $data['package_id'] : null,
                'assigned_user_id' => !empty($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null,
                'created_by_user_id' => $this->currentUserId(),
                'amount'           => $normalizedAmount,
                'status'           => $status,
                'start_date'       => $period['start_date'],
                'end_date'         => $period['end_date'],
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
                return $this->deny($response);
            }

            // Fremde Vereinbarungen ändert nur das Sponsoring-Team.
            if (!$this->policy->canEditSponsorship($sponsorship)) {
                return $this->deny($response);
            }

            $normalizedAmount = self::validateAmount(trim((string) ($data['amount'] ?? '')));
            if ($normalizedAmount === null) {
                $_SESSION['error'] = self::AMOUNT_ERROR;
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            $status = (string) ($data['status'] ?? $sponsorship->status);
            if (!SponsorshipStatus::isValid($status)) {
                $_SESSION['error'] = self::STATUS_ERROR;
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            $projectId = !empty($data['project_id']) ? (int) $data['project_id'] : null;
            if ($projectId !== (int) $sponsorship->project_id && !$this->policy->canUseProject($projectId)) {
                $_SESSION['error'] = self::PROJECT_ERROR;
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            $period = $this->resolvePeriod($data, $projectId);

            $sponsorship->update([
                'project_id'       => $projectId,
                'package_id'       => !empty($data['package_id']) ? (int) $data['package_id'] : null,
                'assigned_user_id' => !empty($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null,
                'amount'           => $normalizedAmount,
                'status'           => $status,
                'start_date'       => $period['start_date'],
                'end_date'         => $period['end_date'],
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
                return $this->deny($response);
            }

            if (!$this->policy->canDeleteSponsorship($sponsorship)) {
                return $this->deny($response);
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
            return $this->deny($response);
        }

        $response->getBody()->write($attachment->file_content);

        return $response
            ->withHeader('Content-Type', $attachment->mime_type)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . DownloadFileName::sanitize((string) $attachment->original_name)
                    . '"; filename*=UTF-8\'\''
                    . rawurlencode(DownloadFileName::sanitize((string) $attachment->original_name))
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
                return $this->deny($response);
            }

            $sponsorship = Sponsorship::findOrFail($sponsorshipId);
            if (!$this->policy->canEditSponsorship($sponsorship)) {
                return $this->deny($response);
            }

            $sponsorId = $sponsorship->sponsor_id;
            $attachment->delete();
            $_SESSION['success'] = 'Anhang erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    private function deny(Response $response): Response
    {
        $response->getBody()->write('Zugriff verweigert.');
        return $response->withStatus(403);
    }

    private function currentUserId(): ?int
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        return $userId > 0 ? $userId : null;
    }

    /**
     * Zeitraum der Vereinbarung. Bleibt ein Feld leer und hängt die
     * Vereinbarung an einem Projekt, wird der Projektzeitraum übernommen -
     * ausgehandelt wird ohnehin je Projekt, und bisher tippte ihn jede Person
     * von Hand ab. Das Formular schlägt denselben Wert schon im Browser vor;
     * diese Ergänzung greift auch ohne JavaScript.
     *
     * @param array<string, mixed> $data
     * @return array{start_date: ?string, end_date: ?string}
     */
    private function resolvePeriod(array $data, ?int $projectId): array
    {
        $startDate = !empty($data['start_date']) ? (string) $data['start_date'] : null;
        $endDate   = !empty($data['end_date']) ? (string) $data['end_date'] : null;

        if (($startDate !== null && $endDate !== null) || $projectId === null) {
            return ['start_date' => $startDate, 'end_date' => $endDate];
        }

        $project = Project::find($projectId);
        if ($project === null) {
            return ['start_date' => $startDate, 'end_date' => $endDate];
        }

        return [
            'start_date' => $startDate ?? $project->start_date?->format('Y-m-d'),
            'end_date'   => $endDate ?? $project->end_date?->format('Y-m-d'),
        ];
    }

    /**
     * Wie im Finanzmodul: erst das Eingabeformat normalisieren ("1.500,00" =>
     * "1500.00"), dann prüfen. Ohne die Prüfung wird "abc" still zu 0,00 und ein
     * negativer Betrag kommentarlos gespeichert.
     */
    public static function validateAmount(string $amount): ?string
    {
        $normalized = AmountNormalizer::normalize($amount);
        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return null;
        }

        return $normalized;
    }
}
