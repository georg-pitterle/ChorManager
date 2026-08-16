<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BankStatementImportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BankStatementImportServiceTest extends TestCase
{
    private const HEADER = 'Buchungsdatum;Valutadatum;Betrag;Währung;Auftraggebername;'
        . 'Auftraggeber IBAN/Kto.Nr.;Auftraggeber BIC/BLZ;Empfängername;Empfänger IBAN/Kto.Nr.;'
        . 'Empfänger BIC/BLZ;Text;Verwendungszweck';

    private BankStatementImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BankStatementImportService(new NullLogger());
    }

    private function fixture(): string
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/bank_statement_sample.csv';
        $content = file_get_contents($path);
        $this->assertIsString($content);

        return $content;
    }

    private function csv(string ...$rows): string
    {
        return self::HEADER . "\n" . implode("\n", $rows) . "\n";
    }

    public function testParsesAllRowsOfTheSampleStatement(): void
    {
        $result = $this->service->parse($this->fixture());

        $this->assertSame([], $result['errors']);
        $this->assertCount(4, $result['rows']);
    }

    public function testStripsByteOrderMarkFromTheHeader(): void
    {
        $raw = $this->fixture();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $raw, 'Fixture muss das BOM behalten.');

        $result = $this->service->parse($raw);

        $this->assertSame([], $result['errors']);
        $this->assertNotSame([], $result['rows']);
    }

    public function testSortsRowsChronologicallyAndMapsBothDates(): void
    {
        $rows = $this->service->parse($this->fixture())['rows'];

        $this->assertSame('2026-07-13', $rows[0]['invoice_date']);
        $this->assertSame('2026-08-04', $rows[3]['invoice_date']);
        $this->assertSame('2026-08-04', $rows[3]['payment_date']);
    }

    public function testFallsBackToTheBookingDateWhenTheValueDateIsMissing(): void
    {
        $result = $this->service->parse($this->csv(
            '08.02.2026;;-12,00;EUR;Chorkuma;AT91;BTV;Kiosk;AT42;HYP;Text;Ohne Valutadatum'
        ));

        $row = $result['rows'][0];
        // Ohne Fallback bliebe payment_date leer und die Buchung würde still zum
        // offenen Posten, der in keinem Kontostand auftaucht.
        $this->assertSame('2026-02-08', $row['payment_date']);
        $this->assertTrue($row['payment_date_estimated']);
        $this->assertNull($row['error']);
    }

    public function testKeepsTheValueDateWhenItIsPresent(): void
    {
        $result = $this->service->parse($this->csv(
            '08.02.2026;10.02.2026;-12,00;EUR;Chorkuma;AT91;BTV;Kiosk;AT42;HYP;Text;Mit Valutadatum'
        ));

        $this->assertSame('2026-02-10', $result['rows'][0]['payment_date']);
        $this->assertFalse($result['rows'][0]['payment_date_estimated']);
    }

    public function testImportHashStaysStableWhenTheValueDateIsMissing(): void
    {
        $line = '09.02.2026;;-7,00;EUR;Chorkuma;AT91;BTV;Kiosk;AT42;HYP;Text;Stabil';

        $first = $this->service->parse($this->csv($line))['rows'][0]['import_hash'];
        $second = $this->service->parse($this->csv($line))['rows'][0]['import_hash'];

        $this->assertSame($first, $second);
    }

    public function testDerivesTypeFromSignAndStoresAmountWithoutSign(): void
    {
        $rows = $this->service->parse($this->fixture())['rows'];
        $byAmount = [];
        foreach ($rows as $row) {
            $byAmount[$row['amount']] = $row;
        }

        $this->assertSame('income', $byAmount['32.96']['type']);
        $this->assertSame('expense', $byAmount['3605.70']['type']);
        $this->assertSame('expense', $byAmount['161.85']['type']);
        $this->assertSame('expense', $byAmount['10.30']['type']);
    }

    public function testBuildsDescriptionFromCounterpartyAndPurpose(): void
    {
        $rows = $this->service->parse($this->fixture())['rows'];
        $descriptions = array_column($rows, 'description');

        $this->assertContains('STRIPE - KUPF SERVICES GMBH', $descriptions);
        $this->assertContains('Tiroler Landestheater und Orchester - SR.260107, HDM.600023', $descriptions);
        $this->assertContains('Keno Hübner - Geschenke - DANKE :)', $descriptions);
    }

    public function testUsesTheRemoteSideAsCounterpartyEvenForDirectDebits(): void
    {
        // Bei der Hetzner-Lastschrift ist der Auftraggeber die Gegenpartei und der
        // Empfänger das eigene Konto - die reine Vorzeichenregel würde hier danebengreifen.
        $rows = $this->service->parse($this->fixture())['rows'];
        $hetzner = null;
        foreach ($rows as $row) {
            if ($row['amount'] === '10.30') {
                $hetzner = $row;
            }
        }

        $this->assertNotNull($hetzner);
        $this->assertSame('Hetzner Online GmbH', $hetzner['counterparty']);
        $this->assertStringStartsWith('Hetzner Online GmbH - Kundennummer', $hetzner['description']);
    }

    public function testTruncatesOverlongDescriptionsToColumnWidth(): void
    {
        $purpose = str_repeat('Ä', 400);
        $result = $this->service->parse($this->csv(
            '01.02.2026;01.02.2026;-10,00;EUR;Chorkuma;AT91;BTV;Lang;AT42;HYP;Text;' . $purpose
        ));

        $this->assertSame(255, mb_strlen($result['rows'][0]['description']));
    }

    public function testConvertsWindows1252ContentToUtf8(): void
    {
        $utf8 = $this->csv('13.07.2026;13.07.2026;-161,85;EUR;Chorkuma;AT91;BTV;Keno Hübner;AT03;SPI;Text;Grüße');
        $cp1252 = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        $this->assertIsString($cp1252);

        $rows = $this->service->parse($cp1252)['rows'];

        $this->assertSame('Keno Hübner - Grüße', $rows[0]['description']);
    }

    public function testFlagsRowsWithUnparsableDateOrAmountWithoutDroppingTheOthers(): void
    {
        $result = $this->service->parse($this->csv(
            '31.02.2026;31.02.2026;-10,00;EUR;Chorkuma;AT91;BTV;Falsch;AT42;HYP;Text;Datum kaputt',
            '02.02.2026;02.02.2026;keine Zahl;EUR;Chorkuma;AT91;BTV;Falsch;AT42;HYP;Text;Betrag kaputt',
            '03.02.2026;03.02.2026;0,00;EUR;Chorkuma;AT91;BTV;Falsch;AT42;HYP;Text;Nullbetrag',
            '04.02.2026;04.02.2026;-25,00;EUR;Chorkuma;AT91;BTV;Gut;AT42;HYP;Text;Alles gut'
        ));

        $this->assertSame([], $result['errors']);
        $this->assertCount(4, $result['rows']);

        $errors = array_column($result['rows'], 'error');
        $this->assertCount(3, array_filter($errors));

        $valid = array_values(array_filter($result['rows'], static fn(array $r): bool => $r['error'] === null));
        $this->assertCount(1, $valid);
        $this->assertSame('25.00', $valid[0]['amount']);
        $this->assertNotNull($valid[0]['import_hash']);
    }

    public function testFlagsForeignCurrencyRows(): void
    {
        $result = $this->service->parse($this->csv(
            '05.02.2026;05.02.2026;-25,00;USD;Chorkuma;AT91;BTV;Dollar;US12;CIT;Text;Fremdwährung'
        ));

        $this->assertNotNull($result['rows'][0]['error']);
        $this->assertStringContainsString('EUR', (string) $result['rows'][0]['error']);
        $this->assertNull($result['rows'][0]['import_hash']);
    }

    public function testReportsMissingMandatoryColumnsAsGlobalError(): void
    {
        $result = $this->service->parse("Datum;Wert\n01.02.2026;5\n");

        $this->assertSame([], $result['rows']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('Buchungsdatum', $result['errors'][0]);
    }

    public function testReportsEmptyStatementAsGlobalError(): void
    {
        $result = $this->service->parse($this->csv());

        $this->assertSame([], $result['rows']);
        $this->assertNotSame([], $result['errors']);
    }

    public function testProducesStableHashesAcrossRepeatedParses(): void
    {
        $first = array_column($this->service->parse($this->fixture())['rows'], 'import_hash');
        $second = array_column($this->service->parse($this->fixture())['rows'], 'import_hash');

        $this->assertSame($first, $second);
        $this->assertCount(4, array_unique($first));
    }

    public function testDistinguishesTwoIdenticalBookingsOnTheSameDay(): void
    {
        $line = '06.02.2026;06.02.2026;-5,00;EUR;Chorkuma;AT91;BTV;Kiosk;AT42;HYP;Text;Zweimal gleich';
        $result = $this->service->parse($this->csv($line, $line));

        $hashes = array_column($result['rows'], 'import_hash');
        $this->assertCount(2, $hashes);
        $this->assertNotSame($hashes[0], $hashes[1]);

        // Derselbe Auszug erneut eingelesen muss exakt dieselben beiden Hashes liefern.
        $again = array_column($this->service->parse($this->csv($line, $line))['rows'], 'import_hash');
        $this->assertSame($hashes, $again);
    }

    public function testIgnoresBlankLines(): void
    {
        $result = $this->service->parse(self::HEADER . "\n"
            . "\n"
            . "07.02.2026;07.02.2026;-5,00;EUR;Chorkuma;AT91;BTV  ;Kiosk;AT42;HYP;Text;Sauber\n"
            . "   \n");

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Kiosk - Sauber', $result['rows'][0]['description']);
    }

    public function testRejectsUploadsThatAreNotCsvFiles(): void
    {
        $this->assertNotNull(BankStatementImportService::validateUpload('auszug.txt', 1024, 'text/plain'));
        $this->assertNotNull(BankStatementImportService::validateUpload('auszug.pdf', 1024, 'application/pdf'));
    }

    public function testRejectsEmptyAndOversizedUploads(): void
    {
        $this->assertNotNull(BankStatementImportService::validateUpload('auszug.csv', 0, 'text/csv'));
        $tooBig = BankStatementImportService::MAX_FILE_SIZE + 1;
        $this->assertNotNull(BankStatementImportService::validateUpload('auszug.csv', $tooBig, 'text/csv'));
    }

    public function testAcceptsCsvUploadsWithTheUsualMimeVariants(): void
    {
        foreach (['text/csv', 'text/plain', 'application/csv', 'application/octet-stream'] as $mime) {
            $this->assertNull(
                BankStatementImportService::validateUpload('Umsatzübersicht.csv', 2048, $mime),
                'MIME-Typ ' . $mime . ' muss akzeptiert werden.'
            );
        }
    }
}
