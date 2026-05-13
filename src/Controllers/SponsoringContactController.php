<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;

class SponsoringContactController
{
    private const ALLOWED_CONTACT_TYPES = ['call', 'email', 'meeting', 'letter', 'other'];
    private const MAX_SUMMARY_LENGTH = 2000;

    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function create(Request $request, Response $response): Response
    {
        $data      = (array) $request->getParsedBody();
        $sponsorId = (int) ($data['sponsor_id'] ?? 0);

        $contactDate = trim((string) ($data['contact_date'] ?? ''));
        $summary     = trim((string) ($data['summary'] ?? ''));
        $type        = (string) ($data['type'] ?? '');
        $followUpDate = trim((string) ($data['follow_up_date'] ?? ''));
        $sponsorshipId = $this->normalizeOptionalId($data['sponsorship_id'] ?? null);

        if (!$sponsorId || !$contactDate || !$summary || !$type) {
            $_SESSION['error'] = 'Sponsor, Datum, Art und Zusammenfassung sind Pflichtfelder.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (!in_array($type, self::ALLOWED_CONTACT_TYPES, true)) {
            $_SESSION['error'] = 'Ungültige Kontaktart.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (!$this->isValidDate($contactDate)) {
            $_SESSION['error'] = 'Ungültiges Kontaktdatum.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if ($followUpDate !== '' && !$this->isValidDate($followUpDate)) {
            $_SESSION['error'] = 'Ungültiges Wiedervorlage-Datum.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            $_SESSION['error'] = 'Die Zusammenfassung ist zu lang (max. 2000 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if ($sponsorshipId !== null && !$this->isSponsorshipLinkedToSponsor($sponsorshipId, $sponsorId)) {
            $_SESSION['error'] = 'Ungültige Vereinbarung für diesen Sponsor.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        try {
            SponsoringContact::create([
                'sponsor_id'     => $sponsorId,
                'sponsorship_id' => $sponsorshipId,
                'user_id'        => $this->normalizeOptionalId($_SESSION['user_id'] ?? null),
                'contact_date'   => $contactDate,
                'type'           => $type,
                'summary'        => $summary,
                'follow_up_date' => $followUpDate !== '' ? $followUpDate : null,
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
                $response->getBody()->write('Zugriff verweigert.');
                return $response->withStatus(403);
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

        $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        $contactDate = trim((string) ($data['contact_date'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));
        $type = (string) ($data['type'] ?? '');
        $followUpDate = trim((string) ($data['follow_up_date'] ?? ''));
        $sponsorshipId = $this->normalizeOptionalId($data['sponsorship_id'] ?? null);

        if (!$sponsorId || !$contactDate || !$summary || !$type) {
            $_SESSION['error'] = 'Sponsor, Datum, Art und Zusammenfassung sind Pflichtfelder.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (!in_array($type, self::ALLOWED_CONTACT_TYPES, true)) {
            $_SESSION['error'] = 'Ungültige Kontaktart.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (!$this->isValidDate($contactDate)) {
            $_SESSION['error'] = 'Ungültiges Kontaktdatum.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if ($followUpDate !== '' && !$this->isValidDate($followUpDate)) {
            $_SESSION['error'] = 'Ungültiges Wiedervorlage-Datum.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            $_SESSION['error'] = 'Die Zusammenfassung ist zu lang (max. 2000 Zeichen).';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if ($sponsorshipId !== null && !$this->isSponsorshipLinkedToSponsor($sponsorshipId, $sponsorId)) {
            $_SESSION['error'] = 'Ungültige Vereinbarung für diesen Sponsor.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        try {
            $contact = SponsoringContact::findOrFail($id);

            if ($contact->sponsor_id !== $sponsorId) {
                $response->getBody()->write('Zugriff verweigert.');
                return $response->withStatus(403);
            }

            $contact->update([
                'sponsorship_id' => $sponsorshipId,
                'contact_date' => $contactDate,
                'type' => $type,
                'summary' => $summary,
                'follow_up_date' => $followUpDate !== '' ? $followUpDate : null,
            ]);

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
                $response->getBody()->write('Zugriff verweigert.');
                return $response->withStatus(403);
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
