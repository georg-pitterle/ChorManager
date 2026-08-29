<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Util\DownloadFileName;
use App\Util\SponsorEngagementState;
use App\Util\SponsorshipStatus;
use App\Util\UploadValidator;
use Psr\Log\LoggerInterface;

class SponsorController
{
    private const MAX_NAME_LENGTH = 255;
    private const MAX_CONTACT_PERSON_LENGTH = 255;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_PHONE_LENGTH = 80;
    private const MAX_WEBSITE_LENGTH = 2048;

    private const MAX_BLOCK_NOTE_LENGTH = 2000;

    private Twig $view;
    private SponsoringPolicy $policy;
    private LoggerInterface $logger;

    public function __construct(Twig $view, SponsoringPolicy $policy, LoggerInterface $logger)
    {
        $this->view = $view;
        $this->policy = $policy;
        $this->logger = $logger;
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q      = trim($params['q'] ?? '');
        $state  = (string) ($params['state'] ?? '');

        $query = Sponsor::with('sponsorships')->orderBy('name');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%' . $q . '%')
                    ->orWhere('contact_person', 'like', '%' . $q . '%')
                    ->orWhere('email', 'like', '%' . $q . '%');
            });
        }

        $sponsors = $query->get();

        // Der Zustand steckt in den Vereinbarungen, nicht in einer Spalte auf
        // sponsors - gefiltert wird deshalb nach dem Laden.
        if ($state !== '' && SponsorEngagementState::isValid($state)) {
            $sponsors = $sponsors
                ->filter(static fn (Sponsor $sponsor): bool => SponsorEngagementState::forSponsor($sponsor) === $state)
                ->values();
        }

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/sponsors/index.twig', [
            'sponsors'       => $sponsors,
            'can_manage_all' => $this->policy->canManageAll(),
            'sponsor_states' => $this->buildSponsorStates($sponsors),
            'state_options'  => SponsorEngagementState::options(),
            'state_labels'   => $this->stateLabels(),
            'state_colors'   => $this->stateColors(),
            'q'              => $q,
            'state'          => $state,
            'success'        => $success,
            'error'          => $error,
            'active_nav'     => 'sponsoring',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->policy->canCreateSponsor()) {
            return $this->deny($response);
        }

        $data = (array) $request->getParsedBody();
        $name = trim((string) ($data['name'] ?? ''));

        if (!$name) {
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $_SESSION['error'] = 'Der Name ist zu lang (max. 255 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        $contactPerson = $this->normalizeOptionalText($data['contact_person'] ?? null);
        $email = $this->normalizeOptionalText($data['email'] ?? null);
        $phone = $this->normalizeOptionalText($data['phone'] ?? null);
        $address = $this->normalizeOptionalText($data['address'] ?? null);
        $website = $this->normalizeOptionalText($data['website'] ?? null);
        $notes = $this->normalizeOptionalText($data['notes'] ?? null);
        $requestsBlocked = !empty($data['requests_blocked']);
        $blockNote = $this->normalizeOptionalText($data['requests_blocked_note'] ?? null);

        if ($contactPerson !== null && mb_strlen($contactPerson) > self::MAX_CONTACT_PERSON_LENGTH) {
            $_SESSION['error'] = 'Die Kontaktperson ist zu lang (max. 255 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if ($email !== null) {
            if (mb_strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $_SESSION['error'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
                return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
            }
        }

        if ($phone !== null && mb_strlen($phone) > self::MAX_PHONE_LENGTH) {
            $_SESSION['error'] = 'Die Telefonnummer ist zu lang (max. 80 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if ($website !== null) {
            if (mb_strlen($website) > self::MAX_WEBSITE_LENGTH || filter_var($website, FILTER_VALIDATE_URL) === false) {
                $_SESSION['error'] = 'Bitte eine gültige Website-URL angeben.';
                return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
            }
        }

        if ($blockNote !== null && mb_strlen($blockNote) > self::MAX_BLOCK_NOTE_LENGTH) {
            $_SESSION['error'] = 'Die Begründung zur Absage ist zu lang (max. 2000 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        try {
            Sponsor::create([
                'type'           => in_array((string) ($data['type'] ?? ''), ['organization', 'person'], true)
                    ? (string) $data['type']
                    : 'organization',
                'name'           => $name,
                'contact_person' => $contactPerson,
                'email'          => $email,
                'phone'          => $phone,
                'address'        => $address,
                'website'        => $website,
                'notes'          => $notes,
                'requests_blocked' => $requestsBlocked,
                'requests_blocked_note' => $requestsBlocked ? $blockNote : null,
                'created_by_user_id' => $this->currentUserId(),
            ]);
            $_SESSION['success'] = 'Sponsor erfolgreich angelegt.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen des Sponsors.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $sponsor = Sponsor::with([
            'sponsorships.package',
            'sponsorships.assignedUser',
            'sponsorships.attachments',
            'sponsorships.contacts.user',
            'attachments',
            'contacts.user',
            'contacts.sponsorship.package',
            'contacts.sponsorship.project',
        ])->findOrFail((int) $args['id']);

        $users    = User::where('is_active', 1)->orderBy('last_name')->get();
        $projects = Project::orderBy('name')->get();
        $packages = SponsorPackage::orderBy('min_amount')->get();

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/sponsors/detail.twig', [
            'sponsor'        => $sponsor,
            'can_manage_all' => $this->policy->canManageAll(),
            'can_edit_sponsor' => $this->policy->canEditSponsor($sponsor),
            'current_user_id' => $this->currentUserId(),
            'sponsor_state'  => SponsorEngagementState::forSponsor($sponsor),
            'state_labels'   => $this->stateLabels(),
            'state_colors'   => $this->stateColors(),
            'users'          => $users,
            'projects'       => $projects,
            'packages'       => $packages,
            'status_options' => SponsorshipStatus::options(),
            'status_labels'  => SponsorshipStatus::labels(),
            'status_colors'  => SponsorshipStatus::colors(),
            'success'        => $success,
            'error'          => $error,
            'active_nav'     => 'sponsoring',
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];

        $sponsor = Sponsor::find($id);
        if ($sponsor === null) {
            $_SESSION['error'] = 'Sponsor nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        // Stammdaten fremder Sponsoren pflegt nur das Sponsoring-Team; wer den
        // Eintrag selbst angelegt hat, darf ihn nachbessern.
        if (!$this->policy->canEditSponsor($sponsor)) {
            return $this->deny($response);
        }

        $data = (array) $request->getParsedBody();
        $name = trim((string) ($data['name'] ?? ''));

        if (!$name) {
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $_SESSION['error'] = 'Der Name ist zu lang (max. 255 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
        }

        $contactPerson = $this->normalizeOptionalText($data['contact_person'] ?? null);
        $email = $this->normalizeOptionalText($data['email'] ?? null);
        $phone = $this->normalizeOptionalText($data['phone'] ?? null);
        $address = $this->normalizeOptionalText($data['address'] ?? null);
        $website = $this->normalizeOptionalText($data['website'] ?? null);
        $notes = $this->normalizeOptionalText($data['notes'] ?? null);
        $requestsBlocked = !empty($data['requests_blocked']);
        $blockNote = $this->normalizeOptionalText($data['requests_blocked_note'] ?? null);

        if ($contactPerson !== null && mb_strlen($contactPerson) > self::MAX_CONTACT_PERSON_LENGTH) {
            $_SESSION['error'] = 'Die Kontaktperson ist zu lang (max. 255 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
        }

        if ($email !== null) {
            if (mb_strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $_SESSION['error'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
            }
        }

        if ($phone !== null && mb_strlen($phone) > self::MAX_PHONE_LENGTH) {
            $_SESSION['error'] = 'Die Telefonnummer ist zu lang (max. 80 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
        }

        if ($website !== null) {
            if (mb_strlen($website) > self::MAX_WEBSITE_LENGTH || filter_var($website, FILTER_VALIDATE_URL) === false) {
                $_SESSION['error'] = 'Bitte eine gültige Website-URL angeben.';
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
            }
        }

        if ($blockNote !== null && mb_strlen($blockNote) > self::MAX_BLOCK_NOTE_LENGTH) {
            $_SESSION['error'] = 'Die Begründung zur Absage ist zu lang (max. 2000 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
        }

        try {
            $sponsor->update([
                'type'           => in_array((string) ($data['type'] ?? ''), ['organization', 'person'], true)
                    ? (string) $data['type']
                    : 'organization',
                'name'           => $name,
                'contact_person' => $contactPerson,
                'email'          => $email,
                'phone'          => $phone,
                'address'        => $address,
                'website'        => $website,
                'notes'          => $notes,
                'requests_blocked' => $requestsBlocked,
                'requests_blocked_note' => $requestsBlocked ? $blockNote : null,
            ]);
            $_SESSION['success'] = 'Sponsor erfolgreich aktualisiert.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren des Sponsors.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        if (!$this->policy->canManageAll()) {
            return $this->deny($response);
        }

        try {
            Sponsor::findOrFail($id)->delete();
            $_SESSION['success'] = 'Sponsor erfolgreich gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Löschen des Sponsors.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
    }

    /**
     * Anhänge am Sponsor selbst: Logo, Mediadaten, Rahmenvereinbarung. Vorher
     * liessen sich Dateien nur an einer einzelnen Vereinbarung ablegen - ein
     * Logo gehört aber zu keiner davon.
     */
    public function uploadAttachment(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $sponsor = Sponsor::find($id);
        if ($sponsor === null) {
            $_SESSION['error'] = 'Sponsor nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (!$this->policy->canEditSponsor($sponsor)) {
            return $this->deny($response);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $files = $uploadedFiles['attachments'] ?? [];
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $uploadError = UploadValidator::getUploadErrorMessage($file->getError(), 'Anhang');
            if ($uploadError !== null) {
                $_SESSION['error'] = $uploadError;
                continue;
            }

            if ($file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $mimeType = UploadValidator::detectMimeType($file);
            $contents = $file->getStream()->getContents();
            $size = strlen($contents);

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
                'entity_type'   => 'sponsor',
                'entity_id'     => $sponsor->id,
                'filename'      => bin2hex(random_bytes(16)) . '_' . $file->getClientFilename(),
                'original_name' => $file->getClientFilename(),
                'mime_type'     => UploadValidator::normalizeMimeType($mimeType),
                'file_size'     => $size,
                'file_content'  => $contents,
            ]);

            $_SESSION['success'] = 'Anhang erfolgreich hochgeladen.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
    }

    public function downloadAttachment(Request $request, Response $response, array $args): Response
    {
        $sponsorId    = (int) $args['id'];
        $attachmentId = (int) $args['attachment_id'];

        $attachment = Attachment::where('entity_type', 'sponsor')->findOrFail($attachmentId);

        // IDOR-Schutz: Anhang muss zum angeforderten Sponsor gehören
        if ($attachment->entity_id !== $sponsorId) {
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
        $sponsorId    = (int) $args['id'];
        $attachmentId = (int) $args['attachment_id'];

        try {
            $attachment = Attachment::where('entity_type', 'sponsor')->findOrFail($attachmentId);

            // IDOR-Schutz
            if ($attachment->entity_id !== $sponsorId) {
                return $this->deny($response);
            }

            $sponsor = Sponsor::findOrFail($sponsorId);
            if (!$this->policy->canEditSponsor($sponsor)) {
                return $this->deny($response);
            }

            $attachment->delete();
            $_SESSION['success'] = 'Anhang erfolgreich gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Löschen des Anhangs.';
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

    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Zustand je Sponsor-Id, damit das Template ihn nicht pro Zeile neu
     * berechnen muss.
     *
     * @param iterable<Sponsor> $sponsors
     * @return array<int, string>
     */
    private function buildSponsorStates(iterable $sponsors): array
    {
        $states = [];
        foreach ($sponsors as $sponsor) {
            $states[(int) $sponsor->id] = SponsorEngagementState::forSponsor($sponsor);
        }

        return $states;
    }

    /**
     * @return array<string, string>
     */
    private function stateLabels(): array
    {
        $labels = [];
        foreach (SponsorEngagementState::options() as $option) {
            $labels[$option['value']] = $option['label'];
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function stateColors(): array
    {
        $colors = [];
        foreach (SponsorEngagementState::options() as $option) {
            $colors[$option['value']] = $option['color'];
        }

        return $colors;
    }
}
