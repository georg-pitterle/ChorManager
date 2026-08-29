<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Project;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;
use App\Services\EntityAttachmentService;
use App\Util\AmountNormalizer;
use App\Util\SponsorshipStatus;

class SponsorshipController
{
    public const AMOUNT_ERROR = 'Ungültiger Betrag. Bitte eine Zahl ab 0 eingeben.';
    public const STATUS_ERROR = 'Ungültiger Status für die Vereinbarung.';
    public const PROJECT_ERROR = 'Vereinbarungen lassen sich nur zu einem laufenden Projekt erfassen.';
    public const BLOCKED_ERROR = 'Dieser Sponsor hat sich weitere Anfragen verbeten.';

    /** Anhänge, die an einer Vereinbarung hängen: Verträge, Angebote. */
    public const ENTITY_TYPE = 'sponsorship';

    private SponsoringPolicy $policy;
    private EntityAttachmentService $attachments;

    public function __construct(SponsoringPolicy $policy, EntityAttachmentService $attachments)
    {
        $this->policy = $policy;
        $this->attachments = $attachments;
    }

    /**
     * Anhänge der Vereinbarung speichern. Eine abgelehnte Datei meldet sich als
     * Fehlermeldung, hält die übrigen aber nicht auf.
     */
    private function handleAttachments(Request $request, int $sponsorshipId): void
    {
        $result = $this->attachments->storeUploads(
            $request->getUploadedFiles()['attachments'] ?? null,
            self::ENTITY_TYPE,
            $sponsorshipId
        );

        if ($result['error'] !== null) {
            $_SESSION['error'] = $result['error'];
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

        // Die Generalabsage ist kein Anzeigehinweis, sondern eine Zusage an den
        // Sponsor: wer sich weitere Anfragen verbeten hat, bekommt keine neue
        // Vereinbarung angelegt.
        if ($this->isBlocked($sponsorId)) {
            $_SESSION['error'] = self::BLOCKED_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $projectId = !empty($data['project_id']) ? (int) $data['project_id'] : null;
        if (!$this->policy->canUseProject($projectId)) {
            $_SESSION['error'] = self::PROJECT_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $period = $this->prefillPeriodFromProject($data, $projectId);

        try {
            $sponsorship = Sponsorship::create([
                'sponsor_id'       => $sponsorId,
                'project_id'       => $projectId,
                'package_id'       => !empty($data['package_id']) ? (int) $data['package_id'] : null,
                'assigned_user_id' => !empty($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null,
                'created_by_user_id' => $this->policy->currentUserId(),
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

            // Beim Ändern wird der Zeitraum NICHT aus dem Projekt nachgefüllt:
            // ein leeres Feld ist hier eine bewusste Eingabe ("unbefristet"),
            // und eine Vorbelegung machte das Leeren unmöglich.
            $sponsorship->update([
                'project_id'       => $projectId,
                'package_id'       => !empty($data['package_id']) ? (int) $data['package_id'] : null,
                'assigned_user_id' => !empty($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : null,
                'amount'           => $normalizedAmount,
                'status'           => $status,
                'start_date'       => !empty($data['start_date']) ? (string) $data['start_date'] : null,
                'end_date'         => !empty($data['end_date']) ? (string) $data['end_date'] : null,
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

            $this->attachments->deleteAllForEntities(self::ENTITY_TYPE, [$id]);
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

        // Die Zugehörigkeit steckt in der Abfrage: ein fremder Anhang wird gar
        // nicht erst gelesen. Vorher lud der Server den kompletten Datei-Inhalt
        // und verwarf ihn danach mit einem 403.
        $attachment = $this->attachments->findWithContent(self::ENTITY_TYPE, $sponsorshipId, $attachmentId);
        if ($attachment === null) {
            return $this->deny($response);
        }

        return $this->attachments->buildDownloadResponse($response, $attachment);
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $sponsorshipId = (int) $args['id'];
        $attachmentId  = (int) $args['attachment_id'];
        $data          = (array) $request->getParsedBody();

        $sponsorship = Sponsorship::find($sponsorshipId);
        if ($sponsorship === null) {
            $_SESSION['error'] = 'Vereinbarung nicht gefunden.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (!$this->policy->canEditSponsorship($sponsorship)) {
            return $this->deny($response);
        }

        if ($this->attachments->deleteForEntity(self::ENTITY_TYPE, $sponsorshipId, $attachmentId)) {
            $_SESSION['success'] = 'Anhang erfolgreich gelöscht.';
        } else {
            $_SESSION['error'] = 'Anhang nicht gefunden.';
        }

        return $response
            ->withHeader('Location', '/sponsoring/sponsors/' . $sponsorship->sponsor_id)
            ->withStatus(302);
    }

    private function deny(Response $response): Response
    {
        $response->getBody()->write('Zugriff verweigert.');
        return $response->withStatus(403);
    }

    /**
     * Ob der Sponsor sich weitere Anfragen verbeten hat.
     */
    private function isBlocked(int $sponsorId): bool
    {
        return Sponsor::whereKey($sponsorId)->where('requests_blocked', true)->exists();
    }

    /**
     * Zeitraum einer NEUEN Vereinbarung. Bleibt ein Feld leer und hängt die
     * Vereinbarung an einem Projekt, wird der Projektzeitraum übernommen -
     * ausgehandelt wird ohnehin je Projekt, und bisher tippte ihn jede Person
     * von Hand ab. Das Formular schlägt denselben Wert schon im Browser vor;
     * diese Ergänzung greift auch ohne JavaScript.
     *
     * Bewusst nur beim Anlegen: beim Ändern ist ein geleertes Feld eine
     * Entscheidung, die eine Vorbelegung still überschriebe.
     *
     * @param array<string, mixed> $data
     * @return array{start_date: ?string, end_date: ?string}
     */
    private function prefillPeriodFromProject(array $data, ?int $projectId): array
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
