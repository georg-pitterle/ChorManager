<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Services\FinanceAccountService;
use App\Services\FinanceJournalService;
use App\Util\AmountNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Verwaltung der Zahlungskreise (Barkassa, Bankkonten). Jede Buchung hängt an
 * genau einem Konto; der Anfangsbestand macht den Kassastand prüfbar.
 */
class FinanceAccountController
{
    public function __construct(
        private readonly Twig $view,
        private readonly FinanceAccountService $accountService,
        private readonly FinanceJournalService $journal,
        private readonly LoggerInterface $logger
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $accounts = $this->accountService->allAccounts();
        $today = Carbon::now();

        $rows = [];
        foreach ($accounts as $account) {
            $rows[] = [
                'account' => $account,
                'balance' => $this->accountService->balanceAt($account, $today),
                'booking_count' => Finance::where('finance_account_id', $account->id)->count(),
            ];
        }

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

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

        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? '');
        $openingDate = (string) ($data['opening_date'] ?? '');
        $openingBalance = AmountNormalizer::normalize((string) ($data['opening_balance'] ?? '0'));

        $validationError = $this->validate($name, $type, $openingDate, $openingBalance, $id);
        if ($validationError !== null) {
            $_SESSION['error'] = $validationError;
            return $response->withHeader('Location', '/finances/accounts')->withStatus(302);
        }

        $payload = [
            'name' => $name,
            'type' => $type,
            'iban' => FinanceAccount::normalizeIban((string) ($data['iban'] ?? '')),
            'opening_balance' => $openingBalance,
            'opening_date' => $openingDate,
            'is_active' => isset($data['is_active']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        try {
            if ($id !== null) {
                $account = FinanceAccount::findOrFail($id);

                $lockError = $this->openingLockError($account, $openingDate, $openingBalance);
                if ($lockError !== null) {
                    $_SESSION['error'] = $lockError;
                    return $response->withHeader('Location', '/finances/accounts')->withStatus(302);
                }

                $orphanError = $this->orphanedBookingsError($account, $openingDate);
                if ($orphanError !== null) {
                    $_SESSION['error'] = $orphanError;
                    return $response->withHeader('Location', '/finances/accounts')->withStatus(302);
                }

                $before = [
                    'type' => (string) $account->type,
                    'opening_balance' => (string) $account->opening_balance,
                    'opening_date' => FinanceAccountService::openingDate($account),
                ];

                // Kontoart und gespiegelte Zahlungsart dürfen nicht auseinanderlaufen,
                // wenn das Nachziehen der Buchungen fehlschlägt.
                Capsule::connection()->transaction(function () use ($account, $payload, $before): void {
                    $account->update($payload);
                    $this->syncPaymentMethod($account, $before['type']);
                });
                $this->recordAccountChange($account, $before);
            } else {
                $account = FinanceAccount::create($payload);
            }

            $this->logger->info('Finance account saved.', [
                'event' => 'finance.account.saved',
                'account_id' => $account->id,
            ]);
            $_SESSION['success'] = $id !== null ? 'Konto aktualisiert.' : 'Konto angelegt.';
        } catch (\Exception $e) {
            $this->logger->error('Finance account save failed.', [
                'event' => 'finance.account.save_failed',
                'account_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Fehler beim Speichern des Kontos.';
        }

        return $response->withHeader('Location', '/finances/accounts')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];

        // Konten mit Buchungen dürfen nicht verschwinden, sonst verlieren die
        // Buchungen ihren Zahlungskreis und der Kassastand wird unprüfbar.
        if (Finance::where('finance_account_id', $accountId)->exists()) {
            $_SESSION['error'] = 'Das Konto hat Buchungen und kann nicht gelöscht werden. '
                . 'Setze es stattdessen auf inaktiv.';
            return $response->withHeader('Location', '/finances/accounts')->withStatus(302);
        }

        try {
            FinanceAccount::findOrFail($accountId)->delete();
            $this->logger->info('Finance account deleted.', [
                'event' => 'finance.account.deleted',
                'account_id' => $accountId,
            ]);
            $_SESSION['success'] = 'Konto gelöscht.';
        } catch (\Exception $e) {
            $this->logger->error('Finance account delete failed.', [
                'event' => 'finance.account.delete_failed',
                'account_id' => $accountId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Fehler beim Löschen des Kontos.';
        }

        return $response->withHeader('Location', '/finances/accounts')->withStatus(302);
    }

    /**
     * Anfangsbestand und Stichtag verschieben jeden Kontostand ab dem Stichtag und
     * damit auch bereits geprüfte Zahlen. Liegt der bisherige oder der neue Stichtag
     * in einem abgeschlossenen Zeitraum, ist die Änderung deshalb gesperrt.
     */
    private function openingLockError(
        FinanceAccount $account,
        string $openingDate,
        string $openingBalance
    ): ?string {
        $currentDate = FinanceAccountService::openingDate($account);
        $unchanged = $currentDate === $openingDate
            && (float) $account->opening_balance === (float) $openingBalance;
        if ($unchanged) {
            return null;
        }

        if (!$this->journal->isLocked($currentDate) && !$this->journal->isLocked($openingDate)) {
            return null;
        }

        return sprintf(
            'Der Zeitraum bis %s ist abgeschlossen. Anfangsbestand und Stichtag dieses Kontos '
                . 'lassen sich nicht mehr ändern.',
            $this->journal->closedUntil()?->format('d.m.Y') ?? '-'
        );
    }

    /**
     * Der Anfangsbestand deckt alles vor dem Stichtag ab, deshalb zählt die
     * Bewegungssumme erst ab dem Stichtag. Ein Stichtag hinter bestehenden
     * Buchungen ließe diese aus dem Kontostand fallen, während sie in der
     * Buchungsliste stehen bleiben - der Kontostand spränge ohne Buchungsänderung.
     */
    private function orphanedBookingsError(FinanceAccount $account, string $openingDate): ?string
    {
        $orphaned = Finance::where('finance_account_id', $account->id)
            ->whereNotNull('payment_date')
            ->whereDate('payment_date', '<', $openingDate)
            ->count();

        if ($orphaned === 0) {
            return null;
        }

        return sprintf(
            'Das Konto hat %d Buchung(en) vor dem %s. Der Stichtag kann nicht dahinter gelegt werden, '
                . 'weil diese Buchungen sonst aus dem Kontostand fallen.',
            $orphaned,
            Carbon::parse($openingDate)->format('d.m.Y')
        );
    }

    /**
     * `payment_method` ist nur ein Spiegelfeld des Kontotyps. Nach einem Typwechsel
     * muss es auf den bestehenden Buchungen mitziehen, sonst zeigen Kassabericht und
     * Zahlungsarten-Auswertung widersprüchliche Werte.
     */
    private function syncPaymentMethod(FinanceAccount $account, string $previousType): void
    {
        if ($previousType === $account->type) {
            return;
        }

        $updated = Finance::where('finance_account_id', $account->id)
            ->update(['payment_method' => $account->paymentMethod()]);

        if ($updated > 0) {
            $this->logger->info('Finance account type changed, payment methods synced.', [
                'event' => 'finance.account.payment_method_synced',
                'account_id' => $account->id,
                'payment_method' => $account->paymentMethod(),
                'bookings' => $updated,
            ]);
        }
    }

    /**
     * Kontostammdaten sind keine Buchungen und passen deshalb nicht in das
     * Buchungsjournal (`finance_revisions` hängt an einer `finance_id`). Damit die
     * bestandswirksamen Änderungen trotzdem nachvollziehbar bleiben, wandern sie mit
     * Vorher-/Nachher-Wert ins strukturierte Log.
     *
     * @param array<string, string> $before
     */
    private function recordAccountChange(FinanceAccount $account, array $before): void
    {
        $after = [
            'type' => (string) $account->type,
            'opening_balance' => (string) $account->opening_balance,
            'opening_date' => FinanceAccountService::openingDate($account),
        ];

        $changes = [];
        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['from' => $before[$field], 'to' => $value];
            }
        }

        if ($changes === []) {
            return;
        }

        $this->logger->info('Finance account opening data changed.', [
            'event' => 'finance.account.opening_changed',
            'account_id' => $account->id,
            'user_id' => is_numeric($_SESSION['user_id'] ?? null) ? (int) $_SESSION['user_id'] : null,
            'changes' => $changes,
        ]);
    }

    private function validate(
        string $name,
        string $type,
        string $openingDate,
        string $openingBalance,
        ?int $id
    ): ?string {
        if ($name === '') {
            return 'Bitte einen Kontonamen angeben.';
        }

        if (!in_array($type, [FinanceAccount::TYPE_CASH, FinanceAccount::TYPE_BANK], true)) {
            return 'Ungültige Kontoart.';
        }

        if (!is_numeric($openingBalance)) {
            return 'Ungültiger Anfangsbestand.';
        }

        $parsedDate = Carbon::canBeCreatedFromFormat($openingDate, 'Y-m-d');
        if ($openingDate === '' || !$parsedDate) {
            return 'Bitte einen gültigen Stichtag für den Anfangsbestand angeben.';
        }

        $duplicate = FinanceAccount::where('name', $name)
            ->when($id !== null, static fn($query) => $query->where('id', '!=', $id))
            ->exists();
        if ($duplicate) {
            return 'Ein Konto mit diesem Namen existiert bereits.';
        }

        return null;
    }
}
