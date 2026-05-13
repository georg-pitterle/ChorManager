<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\Project;
use App\Models\User;

class SponsorController
{
    private const MAX_NAME_LENGTH = 255;
    private const MAX_CONTACT_PERSON_LENGTH = 255;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_PHONE_LENGTH = 80;
    private const MAX_WEBSITE_LENGTH = 2048;

    private Twig $view;

    private const STATUSES = [
        'prospect',
        'contacted',
        'negotiating',
        'active',
        'paused',
        'closed',
    ];

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q      = trim($params['q'] ?? '');
        $status = $params['status'] ?? '';

        $query = Sponsor::with('sponsorships')->orderBy('name');

        if ($status && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%' . $q . '%')
                    ->orWhere('contact_person', 'like', '%' . $q . '%')
                    ->orWhere('email', 'like', '%' . $q . '%');
            });
        }

        $sponsors = $query->get();

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/sponsors/index.twig', [
            'sponsors'   => $sponsors,
            'statuses'   => self::STATUSES,
            'q'          => $q,
            'status'     => $status,
            'success'    => $success,
            'error'      => $error,
            'active_nav' => 'sponsoring',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
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
                'status'         => in_array((string) ($data['status'] ?? ''), self::STATUSES, true)
                    ? (string) $data['status']
                    : 'prospect',
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
            'contacts.user',
            'contacts.sponsorship',
        ])->findOrFail((int) $args['id']);

        $users    = User::where('is_active', 1)->orderBy('last_name')->get();
        $projects = Project::orderBy('name')->get();
        $packages = SponsorPackage::orderBy('min_amount')->get();

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/sponsors/detail.twig', [
            'sponsor'    => $sponsor,
            'users'      => $users,
            'projects'   => $projects,
            'packages'   => $packages,
            'statuses'   => self::STATUSES,
            'success'    => $success,
            'error'      => $error,
            'active_nav' => 'sponsoring',
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
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

        try {
            $sponsor = Sponsor::findOrFail($id);
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
                'status'         => in_array((string) ($data['status'] ?? ''), self::STATUSES, true)
                    ? (string) $data['status']
                    : 'prospect',
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

        try {
            Sponsor::findOrFail($id)->delete();
            $_SESSION['success'] = 'Sponsor erfolgreich gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Löschen des Sponsors.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        return $normalized !== '' ? $normalized : null;
    }
}
