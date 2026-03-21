<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\SponsorPackage;
use App\Models\Project;
use App\Models\User;

class SponsorController
{
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
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        try {
            Sponsor::create([
                'type'           => in_array($data['type'] ?? '', ['organization', 'person']) ? $data['type'] : 'organization',
                'name'           => $name,
                'contact_person' => trim($data['contact_person'] ?? '') ?: null,
                'email'          => trim($data['email'] ?? '') ?: null,
                'phone'          => trim($data['phone'] ?? '') ?: null,
                'address'        => trim($data['address'] ?? '') ?: null,
                'website'        => trim($data['website'] ?? '') ?: null,
                'notes'          => trim($data['notes'] ?? '') ?: null,
                'status'         => in_array($data['status'] ?? '', self::STATUSES) ? $data['status'] : 'prospect',
            ]);
            $_SESSION['success'] = 'Sponsor erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen: ' . $e->getMessage();
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
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
        }

        try {
            $sponsor = Sponsor::findOrFail($id);
            $sponsor->update([
                'type'           => in_array($data['type'] ?? '', ['organization', 'person']) ? $data['type'] : 'organization',
                'name'           => $name,
                'contact_person' => trim($data['contact_person'] ?? '') ?: null,
                'email'          => trim($data['email'] ?? '') ?: null,
                'phone'          => trim($data['phone'] ?? '') ?: null,
                'address'        => trim($data['address'] ?? '') ?: null,
                'website'        => trim($data['website'] ?? '') ?: null,
                'notes'          => trim($data['notes'] ?? '') ?: null,
                'status'         => in_array($data['status'] ?? '', self::STATUSES) ? $data['status'] : 'prospect',
            ]);
            $_SESSION['success'] = 'Sponsor erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        try {
            Sponsor::findOrFail($id)->delete();
            $_SESSION['success'] = 'Sponsor erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
    }
}
