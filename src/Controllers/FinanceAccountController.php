<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FlashMessages;
use App\Models\FinanceAccount;
use App\Services\FinanceAccountService;
use App\Services\FinanceAccountWriteService;
use App\Util\AmountNormalizer;
use App\Util\InputValidator;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Verwaltung der Zahlungskreise (Barkassa, Bankkonten). Jede Buchung hängt an
 * genau einem Konto; der Anfangsbestand macht den Kassastand prüfbar.
 */
class FinanceAccountController
{
    use FlashMessages;

    public function __construct(
        private readonly Twig $view,
        private readonly FinanceAccountService $accountService,
        private readonly FinanceAccountWriteService $writer
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $accounts = $this->accountService->allAccounts();
        $today = Carbon::now();

        // Beide Zahlen je Konto kommen aus je einer Abfrage für alle Konten.
        // Vorher stellte die Schleife hier zwei Abfragen pro Konto.
        $balances = $this->accountService->balancesFor($accounts, $today);
        $bookingCounts = $this->accountService->bookingCounts();

        $rows = [];
        foreach ($accounts as $account) {
            $rows[] = [
                'account' => $account,
                'balance' => $balances[(int) $account->id] ?? (float) $account->opening_balance,
                'booking_count' => $bookingCounts[(int) $account->id] ?? 0,
            ];
        }

        ['success' => $success, 'error' => $error] = $this->takeFlash();

        return $this->view->render($response, 'finances/accounts.twig', [
            'rows' => $rows,
            'today' => $today->format('d.m.Y'),
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $id = isset($data['id']) && $data['id'] ? (int) $data['id'] : null;

        $payload = [
            'name' => trim(InputValidator::asString($data['name'] ?? null)),
            'type' => InputValidator::asString($data['type'] ?? null),
            'iban' => FinanceAccount::normalizeIban(InputValidator::asString($data['iban'] ?? null)),
            'opening_balance' => AmountNormalizer::normalize(
                InputValidator::asString($data['opening_balance'] ?? '0')
            ),
            'opening_date' => InputValidator::asString($data['opening_date'] ?? null),
            'is_active' => isset($data['is_active']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        $result = $this->writer->save($id, $payload, self::actorId());

        if ($result['error'] !== null) {
            $_SESSION['error'] = $result['error'];
        } else {
            $_SESSION['success'] = $id !== null ? 'Konto aktualisiert.' : 'Konto angelegt.';
        }

        return $response->withHeader('Location', '/finances/accounts')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $error = $this->writer->delete((int) $args['id']);

        if ($error !== null) {
            $_SESSION['error'] = $error;
        } else {
            $_SESSION['success'] = 'Konto gelöscht.';
        }

        return $response->withHeader('Location', '/finances/accounts')->withStatus(302);
    }

    private static function actorId(): ?int
    {
        return is_numeric($_SESSION['user_id'] ?? null) ? (int) $_SESSION['user_id'] : null;
    }
}
