<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;

class SponsoringContactController
{
    private const ALLOWED_CONTACT_TYPES = ['call', 'email', 'meeting', 'letter', 'other'];
    private const MAX_SUMMARY_LENGTH = 2000;

    public const BLOCKED_ERROR = 'Dieser Sponsor hat sich weitere Anfragen verbeten.';

    private Twig $view;
    private SponsoringPolicy $policy;

    public function __construct(Twig $view, SponsoringPolicy $policy)
    {
        $this->view = $view;
        $this->policy = $policy;
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->policy->canContribute()) {
            return $this->deny($response);
        }

        $data      = (array) $request->getParsedBody();
        $sponsorId = (int) ($data['sponsor_id'] ?? 0);

        $validation = $this->validateContactInput($data);
        if ($validation['error'] !== null) {
            $_SESSION['error'] = $validation['error'];
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        // Ein neuer Kontakt zu einem Sponsor, der sich Anfragen verbeten hat,
        // ist genau das, was die Generalabsage verhindern soll.
        if ($this->isBlocked($sponsorId)) {
            $_SESSION['error'] = self::BLOCKED_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        try {
            SponsoringContact::create($validation['values'] + [
                'sponsor_id'     => $sponsorId,
                'user_id'        => $this->policy->currentUserId(),
                'follow_up_done' => 0,
            ]);
            $_SESSION['success'] = 'Kontakt erfolgreich protokolliert.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Speichern des Kontakts.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function markDone(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $queryParams = $request->getQueryParams();
        $redirectTo = (string) ($data['redirect_to'] ?? $queryParams['redirect_to'] ?? '');
        $providedSponsorId = (int) ($data['sponsor_id'] ?? $queryParams['sponsor_id'] ?? 0);

        try {
            $contact = SponsoringContact::findOrFail($id);

            if ($providedSponsorId > 0 && $providedSponsorId !== (int) $contact->sponsor_id) {
                return $this->deny($response);
            }

            // Abhaken darf, wem die Wiedervorlage gehört.
            if (!$this->policy->canCompleteFollowUp($contact)) {
                return $this->deny($response);
            }

            $contact->update(['follow_up_done' => 1]);
            $sponsorId = (int) $contact->sponsor_id;
            $_SESSION['success'] = 'Wiedervorlage als erledigt markiert.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren der Wiedervorlage.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        if ($redirectTo === 'dashboard') {
            $redirectPath = '/sponsoring';
        } elseif ($sponsorId) {
            $redirectPath = '/sponsoring/sponsors/' . $sponsorId;
        } else {
            $redirectPath = '/sponsoring';
        }

        return $response->withHeader('Location', $redirectPath)->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();

        if (!$this->policy->canContribute()) {
            return $this->deny($response);
        }

        $sponsorId = (int) ($data['sponsor_id'] ?? 0);

        $validation = $this->validateContactInput($data);
        if ($validation['error'] !== null) {
            $_SESSION['error'] = $validation['error'];
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        try {
            $contact = SponsoringContact::findOrFail($id);

            if ($contact->sponsor_id !== $sponsorId) {
                return $this->deny($response);
            }

            // Einen fremden Protokolleintrag ändert nur das Sponsoring-Team.
            if (!$this->policy->canEditContact($contact)) {
                return $this->deny($response);
            }

            $contact->update($validation['values']);

            $_SESSION['success'] = 'Kontakt erfolgreich aktualisiert.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren des Kontakts.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $providedSponsorId = (int) ($data['sponsor_id'] ?? 0);

        try {
            $contact   = SponsoringContact::findOrFail($id);

            if ($providedSponsorId > 0 && $providedSponsorId !== (int) $contact->sponsor_id) {
                return $this->deny($response);
            }

            if (!$this->policy->canEditContact($contact)) {
                return $this->deny($response);
            }

            $sponsorId = (int) $contact->sponsor_id;
            $contact->delete();
            $_SESSION['success'] = 'Kontakt erfolgreich gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Löschen des Kontakts.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    private function deny(Response $response): Response
    {
        $response->getBody()->write('Zugriff verweigert.');
        return $response->withStatus(403);
    }

    private function isBlocked(int $sponsorId): bool
    {
        return Sponsor::whereKey($sponsorId)->where('requests_blocked', true)->exists();
    }

    /**
     * Prüft einen Protokolleintrag und gibt entweder die erste Beanstandung
     * oder die fertigen Spaltenwerte zurück. Anlegen und Ändern hatten dieselbe
     * Kette vorher doppelt; eine zusätzliche Regel wäre nur in einem der beiden
     * Wege gelandet.
     *
     * @param array<string, mixed> $data
     * @return array{error: ?string, values: array<string, mixed>}
     */
    private function validateContactInput(array $data): array
    {
        $fail = static fn (string $message): array => ['error' => $message, 'values' => []];

        $sponsorId     = (int) ($data['sponsor_id'] ?? 0);
        $contactDate   = trim((string) ($data['contact_date'] ?? ''));
        $summary       = trim((string) ($data['summary'] ?? ''));
        $type          = (string) ($data['type'] ?? '');
        $followUpDate  = trim((string) ($data['follow_up_date'] ?? ''));
        $sponsorshipId = $this->normalizeOptionalId($data['sponsorship_id'] ?? null);

        if (!$sponsorId || !$contactDate || !$summary || !$type) {
            return $fail('Sponsor, Datum, Art und Zusammenfassung sind Pflichtfelder.');
        }

        if (!in_array($type, self::ALLOWED_CONTACT_TYPES, true)) {
            return $fail('Ungültige Kontaktart.');
        }

        if (!$this->isValidDate($contactDate)) {
            return $fail('Ungültiges Kontaktdatum.');
        }

        if ($followUpDate !== '' && !$this->isValidDate($followUpDate)) {
            return $fail('Ungültiges Wiedervorlage-Datum.');
        }

        if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            return $fail('Die Zusammenfassung ist zu lang (max. 2000 Zeichen).');
        }

        if ($sponsorshipId !== null && !$this->isSponsorshipLinkedToSponsor($sponsorshipId, $sponsorId)) {
            return $fail('Ungültige Vereinbarung für diesen Sponsor.');
        }

        return [
            'error' => null,
            'values' => [
                'sponsorship_id' => $sponsorshipId,
                'contact_date'   => $contactDate,
                'type'           => $type,
                'summary'        => $summary,
                'follow_up_date' => $followUpDate !== '' ? $followUpDate : null,
            ],
        ];
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function normalizeOptionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function isSponsorshipLinkedToSponsor(int $sponsorshipId, int $sponsorId): bool
    {
        return Sponsorship::where('id', $sponsorshipId)
            ->where('sponsor_id', $sponsorId)
            ->exists();
    }
}
