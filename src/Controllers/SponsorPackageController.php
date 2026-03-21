<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\SponsorPackage;

class SponsorPackageController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $packages = SponsorPackage::orderBy('min_amount')->get();
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/packages/index.twig', [
            'packages' => $packages,
            'success'  => $success,
            'error'    => $error,
            'active_nav' => 'sponsoring',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
        }

        try {
            SponsorPackage::create([
                'name'        => $name,
                'description' => trim($data['description'] ?? '') ?: null,
                'min_amount'  => (float) str_replace(',', '.', $data['min_amount'] ?? '0'),
                'color'       => $data['color'] ?? 'info',
            ]);
            $_SESSION['success'] = 'Paket erfolgreich angelegt.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');

        if (!$name) {
            $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
        }

        try {
            $package = SponsorPackage::findOrFail($id);
            $package->update([
                'name'        => $name,
                'description' => trim($data['description'] ?? '') ?: null,
                'min_amount'  => (float) str_replace(',', '.', $data['min_amount'] ?? '0'),
                'color'       => $data['color'] ?? 'info',
            ]);
            $_SESSION['success'] = 'Paket erfolgreich aktualisiert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        try {
            $package = SponsorPackage::findOrFail($id);
            if ($package->sponsorships()->count() > 0) {
                $_SESSION['error'] = 'Das Paket kann nicht gelöscht werden, da noch Vereinbarungen damit verknüpft sind.';
                return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
            }
            $package->delete();
            $_SESSION['success'] = 'Paket erfolgreich gelöscht.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Löschen: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
    }
}
