<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finance;
use App\Models\FinanceAccount;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Schreibt Zahlungskreise: Anlegen, Ändern, Löschen.
 *
 * Die Transaktion und ihre Regeln standen im FinanceAccountController - der
 * letzte von fünf Controllern aus der Schichtprüfung vom 16.11.2026, der die
 * Datenbankverbindung noch selbst öffnete. Gebaut wie FinanceBookingService:
 * Jede Methode hält zusammen, was zusammen gelten muss, und antwortet dem
 * Aufrufer, statt ihn verzweigen zu lassen.
 *
 * Die Meldungen entstehen hier und nicht im Controller, weil zwei von ihnen
 * Zahlen aus der Datenbank tragen (Stichtag des Abschlusses, Zahl der Buchungen
 * davor). Dieselbe Aufteilung wie bei UploadValidator und BenefitList.
 */
class FinanceAccountWriteService
{
    public const DELETE_HAS_BOOKINGS_ERROR = 'Das Konto hat Buchungen und kann nicht gelöscht werden. '
        . 'Setze es stattdessen auf inaktiv.';
    public const SAVE_FAILED_ERROR = 'Fehler beim Speichern des Kontos.';
    public const DELETE_FAILED_ERROR = 'Fehler beim Löschen des Kontos.';

    public function __construct(
        private readonly FinanceJournalService $journal,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Legt ein Konto an ($id === null) oder ändert es.
     *
     * Beim Ändern hängen Kontoart und die gespiegelte Zahlungsart der Buchungen
     * in derselben Transaktion: Scheitert das Nachziehen, bleibt auch die
     * Kontoart stehen - sonst zeigten Kassabericht und Zahlungsarten-Auswertung
     * widersprüchliche Werte.
     *
     * @param array<string, mixed> $payload Spaltenwerte, vom Aufrufer schon normalisiert
     * @return array{account: FinanceAccount|null, error: string|null}
     */
    public function save(?int $id, array $payload, ?int $actorId): array
    {
        $openingDate = (string) ($payload['opening_date'] ?? '');
        $openingBalance = (string) ($payload['opening_balance'] ?? '');

        $error = $this->validate(
            (string) ($payload['name'] ?? ''),
            (string) ($payload['type'] ?? ''),
            $openingDate,
            $openingBalance,
            $id
        );
        if ($error !== null) {
            return ['account' => null, 'error' => $error];
        }

        try {
            if ($id === null) {
                $account = FinanceAccount::create($payload);
            } else {
                $account = FinanceAccount::findOrFail($id);

                $error = $this->openingLockError($account, $openingDate, $openingBalance)
                    ?? $this->orphanedBookingsError($account, $openingDate);
                if ($error !== null) {
                    return ['account' => null, 'error' => $error];
                }

                $before = self::openingSnapshot($account);

                Capsule::connection()->transaction(function () use ($account, $payload, $before): void {
                    $account->update($payload);
                    $this->syncPaymentMethod($account, $before['type']);
                });
                $this->recordAccountChange($account, $before, $actorId);
            }

            $this->logger->info('Finance account saved.', [
                'event' => 'finance.account.saved',
                'account_id' => $account->id,
            ]);

            return ['account' => $account, 'error' => null];
        } catch (\Exception $e) {
            $this->logger->error('Finance account save failed.', [
                'event' => 'finance.account.save_failed',
                'account_id' => $id,
                'exception' => $e,
            ]);

            return ['account' => null, 'error' => self::SAVE_FAILED_ERROR];
        }
    }

    /**
     * Löscht ein Konto. `null` heißt: gelöscht, sonst steht der Grund im Text.
     *
     * Konten mit Buchungen dürfen nicht verschwinden, sonst verlieren die
     * Buchungen ihren Zahlungskreis und der Kassastand wird unprüfbar.
     */
    public function delete(int $accountId): ?string
    {
        if (Finance::where('finance_account_id', $accountId)->exists()) {
            return self::DELETE_HAS_BOOKINGS_ERROR;
        }

        try {
            FinanceAccount::findOrFail($accountId)->delete();
        } catch (\Exception $e) {
            $this->logger->error('Finance account delete failed.', [
                'event' => 'finance.account.delete_failed',
                'account_id' => $accountId,
                'exception' => $e,
            ]);

            return self::DELETE_FAILED_ERROR;
        }

        $this->logger->info('Finance account deleted.', [
            'event' => 'finance.account.deleted',
            'account_id' => $accountId,
        ]);

        return null;
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
     *
     * `protected`, damit eine Unterklasse im Test scheitern kann und die
     * Transaktionsklammer dadurch prüfbar wird - dieselbe Bauart wie
     * ProjectPersistence in UserPersistenceAtomicFeatureTest.
     */
    protected function syncPaymentMethod(FinanceAccount $account, string $previousType): void
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
    private function recordAccountChange(FinanceAccount $account, array $before, ?int $actorId): void
    {
        $changes = [];
        foreach (self::openingSnapshot($account) as $field => $value) {
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
            'user_id' => $actorId,
            'changes' => $changes,
        ]);
    }

    /**
     * Die bestandswirksamen Felder eines Kontos als Vergleichswerte.
     *
     * @return array<string, string>
     */
    private static function openingSnapshot(FinanceAccount $account): array
    {
        return [
            'type' => (string) $account->type,
            'opening_balance' => (string) $account->opening_balance,
            'opening_date' => FinanceAccountService::openingDate($account),
        ];
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
