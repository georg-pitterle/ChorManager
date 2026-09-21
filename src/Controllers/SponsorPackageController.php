<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Slim\Views\Twig;
use App\Models\SponsorPackage;
use App\Policies\SponsoringPolicy;

class SponsorPackageController
{
    private const AMOUNT_ERROR = 'Ungültiger Mindestbetrag. Bitte eine Zahl ab 0 eingeben.';

    private Twig $view;
    private SponsoringPolicy $policy;
    private LoggerInterface $logger;

    public function __construct(Twig $view, SponsoringPolicy $policy, ?LoggerInterface $logger = null)
    {
        $this->view = $view;
        $this->policy = $policy;
        $this->logger = $logger ?? new NullLogger();
    }

    public function index(Request $request, Response $response): Response
    {
        $packages = SponsorPackage::orderBy('min_amount')->get();
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/packages/index.twig', [
            'packages' => $packages,
            'can_manage_all' => $this->policy->canManageAll(),
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

        // Das Feld ist optional; leer heißt "kein Mindestbetrag".
        $minAmountInput = trim((string) ($data['min_amount'] ?? ''));
        $minAmount = SponsorshipController::validateAmount($minAmountInput === '' ? '0' : $minAmountInput);
        if ($minAmount === null) {
            $_SESSION['error'] = self::AMOUNT_ERROR;
            return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
        }

        try {
            SponsorPackage::create([
                'name'        => $name,
                'description' => trim($data['description'] ?? '') ?: null,
                'min_amount'  => $minAmount,
                'color'       => $data['color'] ?? 'info',
            ]);
            $_SESSION['success'] = 'Paket erfolgreich angelegt.';
        } catch (\Exception $e) {
            // Wie in SponsorshipController: Der Treibertext gehört ins Protokoll,
            // der Doppelpunkt dahinter war der Rest, den sein Ausbau hinterließ.
            $this->logger->error('Creating a sponsor package failed.', [
                'event' => 'sponsor_package.create.failed',
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Das Paket konnte nicht angelegt werden.';
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

        // Das Feld ist optional; leer heißt "kein Mindestbetrag".
        $minAmountInput = trim((string) ($data['min_amount'] ?? ''));
        $minAmount = SponsorshipController::validateAmount($minAmountInput === '' ? '0' : $minAmountInput);
        if ($minAmount === null) {
            $_SESSION['error'] = self::AMOUNT_ERROR;
            return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
        }

        try {
            $package = SponsorPackage::findOrFail($id);
            $package->update([
                'name'        => $name,
                'description' => trim($data['description'] ?? '') ?: null,
                'min_amount'  => $minAmount,
                'color'       => $data['color'] ?? 'info',
            ]);
            $_SESSION['success'] = 'Paket erfolgreich aktualisiert.';
        } catch (ModelNotFoundException $e) {
            // Der häufigste Weg hierher: Die Seite lag offen, während jemand
            // anderes dasselbe Paket entfernt hat. Kein Betriebsfehler.
            $_SESSION['error'] = 'Das Paket wurde nicht gefunden. '
                . 'Möglicherweise wurde es bereits gelöscht.';
        } catch (\Exception $e) {
            $this->logger->error('Updating a sponsor package failed.', [
                'event' => 'sponsor_package.update.failed',
                'sponsor_package_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Das Paket konnte nicht aktualisiert werden.';
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
        } catch (ModelNotFoundException $e) {
            $_SESSION['error'] = 'Das Paket wurde nicht gefunden. '
                . 'Möglicherweise wurde es bereits gelöscht.';
        } catch (\Exception $e) {
            $this->logger->error('Deleting a sponsor package failed.', [
                'event' => 'sponsor_package.delete.failed',
                'sponsor_package_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Das Paket konnte nicht gelöscht werden.';
        }

        return $response->withHeader('Location', '/sponsoring/packages')->withStatus(302);
    }
}
