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

        try {
            $contact = SponsoringContact::findOrFail($id);
            $contact->update(['follow_up_done' => 1]);
            $sponsorId = $contact->sponsor_id;
            $_SESSION['success'] = 'Wiedervorlage als erledigt markiert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler: ' . $e->getMessage();
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        $redirectTo = $sponsorId
            ? '/sponsoring/sponsors/' . $sponsorId
            : '/sponsoring';

        return $response->withHeader('Location', $redirectTo)->withStatus(302);
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
}
