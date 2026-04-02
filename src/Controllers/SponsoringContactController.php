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

    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function create(Request $request, Response $response): Response
    {
        $data      = (array) $request->getParsedBody();
        $sponsorId = (int) ($data['sponsor_id'] ?? 0);

        $contactDate = trim($data['contact_date'] ?? '');
        $summary     = trim($data['summary'] ?? '');
        $type        = $data['type'] ?? '';

        if (!$sponsorId || !$contactDate || !$summary || !$type) {
            $_SESSION['error'] = 'Sponsor, Datum, Art und Zusammenfassung sind Pflichtfelder.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        try {
            SponsoringContact::create([
                'sponsor_id'     => $sponsorId,
                'sponsorship_id' => !empty($data['sponsorship_id']) ? (int) $data['sponsorship_id'] : null,
                'user_id'        => $_SESSION['user_id'] ?? null,
                'contact_date'   => $contactDate,
                'type'           => $type,
                'summary'        => $summary,
                'follow_up_date' => !empty($data['follow_up_date']) ? $data['follow_up_date'] : null,
                'follow_up_done' => 0,
            ]);
            $_SESSION['success'] = 'Kontakt erfolgreich protokolliert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Speichern: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function markDone(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $queryParams = $request->getQueryParams();
        $redirectTo = (string) ($data['redirect_to'] ?? $queryParams['redirect_to'] ?? '');

        try {
            $contact = SponsoringContact::findOrFail($id);
            $contact->update(['follow_up_done' => 1]);
            $sponsorId = $contact->sponsor_id;
            $_SESSION['success'] = 'Wiedervorlage als erledigt markiert.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler: ' . $e->getMessage();
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

        if (!$sponsorId || !$contactDate || !$summary || !$type) {
            $_SESSION['error'] = 'Sponsor, Datum, Art und Zusammenfassung sind Pflichtfelder.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (!in_array($type, self::ALLOWED_CONTACT_TYPES, true)) {
            $_SESSION['error'] = 'Ungueltige Kontaktart.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (!$this->isValidDate($contactDate)) {
            $_SESSION['error'] = 'Ungueltiges Kontaktdatum.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if ($followUpDate !== '' && !$this->isValidDate($followUpDate)) {
            $_SESSION['error'] = 'Ungueltiges Wiedervorlage-Datum.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        try {
            $contact = SponsoringContact::findOrFail($id);

            if ($contact->sponsor_id !== $sponsorId) {
                $response->getBody()->write('Zugriff verweigert.');
                return $response->withStatus(403);
            }

            $contact->update([
                'sponsorship_id' => !empty($data['sponsorship_id']) ? (int) $data['sponsorship_id'] : null,
                'contact_date' => $contactDate,
                'type' => $type,
                'summary' => $summary,
                'follow_up_date' => $followUpDate !== '' ? $followUpDate : null,
            ]);

            $_SESSION['success'] = 'Kontakt erfolgreich aktualisiert.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        try {
            $contact   = SponsoringContact::findOrFail($id);
            $sponsorId = $contact->sponsor_id;
            $contact->delete();
            $_SESSION['success'] = 'Kontakt erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ' . $e->getMessage();
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
