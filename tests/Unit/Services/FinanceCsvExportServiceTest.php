<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Services\FinanceCsvExportService;
use PHPUnit\Framework\TestCase;

/**
 * Der Kassabuch-Export muss dieselbe Sprache schreiben, die der Import liest:
 * CSV nach RFC 4180, in dem das Anführungszeichen verdoppelt und nicht mit
 * einem Rückwärtsschrägstrich geflüchtet wird.
 */
final class FinanceCsvExportServiceTest extends TestCase
{
    private FinanceCsvExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinanceCsvExportService();
    }

    /**
     * Ein Rückwärtsschrägstrich vor einem Anführungszeichen darf die Zelle
     * nicht zerreißen.
     *
     * `fputcsv()` kannte als Fluchtzeichen den Rückwärtsschrägstrich: Stand er
     * vor einem Anführungszeichen, blieb das Anführungszeichen unverdoppelt in
     * der Datei stehen. Ein Leser nach RFC 4180 - und das ist seit Lauf 71 auch
     * der eigene Import - hält es dann für das Ende des Feldes und liest den
     * Rest der Zelle als Nachspann.
     */
    public function testABackslashBeforeAQuoteSurvivesTheRoundTrip(): void
    {
        $description = 'Spende \\" Verein';

        $rows = $this->readBack($this->service->build([$this->booking($description)]));

        $this->assertCount(2, $rows, 'Kopfzeile und genau eine Buchung.');
        $this->assertCount(11, $rows[1], 'Die Buchung muss alle elf Spalten behalten.');
        $this->assertSame($description, $rows[1][3]);
    }

    /**
     * Dieselbe Prüfung für den Fall aus Lauf 71: ein Feld, das auf einen
     * Rückwärtsschrägstrich endet. Hier ging es schon vorher gut, der Fall
     * gehört aber zur selben Regel und soll sie mitbewachen.
     */
    public function testAFieldEndingInABackslashKeepsTheFollowingColumns(): void
    {
        $rows = $this->readBack($this->service->build([$this->booking('Kiosk\\')]));

        $this->assertSame('Kiosk\\', $rows[1][3]);
        $this->assertSame('Konzert', $rows[1][4]);
    }

    /**
     * Ein Anführungszeichen ohne Rückwärtsschrägstrich davor war nie das
     * Problem - der Wächter hält fest, dass die Umstellung daran nichts ändert.
     */
    public function testAPlainQuoteIsDoubledAsBefore(): void
    {
        $rows = $this->readBack($this->service->build([$this->booking('Spende "Verein"')]));

        $this->assertSame('Spende "Verein"', $rows[1][3]);
    }

    private function booking(string $description): Finance
    {
        $account = new FinanceAccount();
        $account->setRawAttributes([
            'id' => 1,
            'name' => 'Bankkonto',
            'type' => FinanceAccount::TYPE_BANK,
        ], true);

        $finance = new Finance();
        $finance->setRawAttributes([
            'id' => 1,
            'running_number' => 7,
            'invoice_date' => '2026-02-08',
            'payment_date' => '2026-02-10',
            'description' => $description,
            'group_name' => 'Konzert',
            'finance_group_id' => null,
            'type' => Finance::TYPE_EXPENSE,
            'amount' => '12.00',
            'payment_method' => Finance::PAYMENT_METHOD_BANK_TRANSFER,
            'finance_account_id' => 1,
            'reversal_of_id' => null,
            'attachments_count' => 0,
        ], true);
        $finance->setRelation('financeAccount', $account);
        $finance->setRelation('reversalOf', null);

        return $finance;
    }

    /**
     * Liest den Export so, wie der Bankauszug-Import ihn liest: ohne
     * Fluchtzeichen, also genau nach RFC 4180.
     *
     * @return list<list<string|null>>
     */
    private function readBack(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        $this->assertIsResource($handle);

        // Das BOM gehört nicht zum ersten Feld; es steht nur für Excel davor.
        fwrite($handle, (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($handle);

        $rows = [];
        while (($fields = fgetcsv($handle, 0, FinanceCsvExportService::DELIMITER, '"', '')) !== false) {
            $rows[] = $fields;
        }
        fclose($handle);

        return $rows;
    }
}
