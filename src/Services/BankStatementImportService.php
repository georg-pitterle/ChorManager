<?php

declare(strict_types=1);

namespace App\Services;

use App\Util\AmountNormalizer;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Parses a bank statement CSV export ("Umsatzübersicht") into rows that can be
 * turned into Finance bookings. Pure parsing - no database access - so the whole
 * mapping stays unit testable.
 */
class BankStatementImportService
{
    public const MAX_FILE_SIZE = 2 * 1024 * 1024;
    private const DESCRIPTION_MAX_LENGTH = 255;
    private const DELIMITER = ';';

    /** MIME types a browser realistically reports for a .csv file. */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream',
    ];

    private const COLUMN_BOOKING_DATE = 'buchungsdatum';
    private const COLUMN_VALUE_DATE = 'valutadatum';
    private const COLUMN_AMOUNT = 'betrag';
    private const COLUMN_CURRENCY = 'währung';
    private const COLUMN_SENDER_NAME = 'auftraggebername';
    private const COLUMN_SENDER_IBAN = 'auftraggeber iban/kto.nr.';
    private const COLUMN_RECEIVER_NAME = 'empfängername';
    private const COLUMN_RECEIVER_IBAN = 'empfänger iban/kto.nr.';
    private const COLUMN_TEXT = 'text';
    private const COLUMN_PURPOSE = 'verwendungszweck';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Validates the uploaded file before its content is read.
     * Returns a German error message, or null when the upload is acceptable.
     *
     * The global UploadValidator allow-list is deliberately not reused here: it does
     * not contain text/csv, and widening it would loosen every attachment upload.
     */
    public static function validateUpload(string $filename, int $sizeBytes, string $mimeType): ?string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            return 'Es werden nur CSV-Dateien akzeptiert.';
        }

        if ($sizeBytes <= 0) {
            return 'Die Datei ist leer.';
        }

        if ($sizeBytes > self::MAX_FILE_SIZE) {
            return sprintf(
                'Die Datei ist zu groß: %s MB. Maximal erlaubt: 2 MB.',
                round($sizeBytes / (1024 * 1024), 1)
            );
        }

        $normalizedMime = strtolower(trim(explode(';', $mimeType)[0]));
        if ($normalizedMime !== '' && !in_array($normalizedMime, self::ALLOWED_MIME_TYPES, true)) {
            return sprintf('Der Dateityp "%s" wird nicht als Kontoauszug akzeptiert.', $normalizedMime);
        }

        return null;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, own_iban: string|null}
     */
    public function parse(string $rawContent): array
    {
        $content = $this->normalizeEncoding($rawContent);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $header = null;
        $records = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = str_getcsv($line, self::DELIMITER, '"', '\\');
            if ($header === null) {
                $header = $this->mapHeader($fields);
                continue;
            }

            $records[] = $fields;
        }

        if ($header === null) {
            return ['rows' => [], 'errors' => ['Die Datei enthält keine Kopfzeile.'], 'own_iban' => null];
        }

        $missing = $this->missingColumns($header);
        if ($missing !== []) {
            return [
                'rows' => [],
                'errors' => [
                    'Die Datei hat nicht das erwartete Format. Fehlende Spalten: ' . implode(', ', $missing) . '.',
                ],
                'own_iban' => null,
            ];
        }

        if ($records === []) {
            return ['rows' => [], 'errors' => ['Die Datei enthält keine Buchungszeilen.'], 'own_iban' => null];
        }

        $ownIban = $this->detectOwnIban($header, $records);
        $rows = $this->buildRows($header, $records, $ownIban);

        $this->logger->info('Bank statement parsed.', [
            'event' => 'finance.import.parsed',
            'rows_total' => count($rows),
            'rows_invalid' => count(array_filter(array_column($rows, 'error'))),
        ]);

        return ['rows' => $rows, 'errors' => [], 'own_iban' => $ownIban];
    }

    /**
     * @param array<string, int> $header
     * @param list<list<string|null>> $records
     * @return list<array<string, mixed>>
     */
    private function buildRows(array $header, array $records, ?string $ownIban): array
    {
        $rows = [];
        $occurrences = [];
        foreach ($records as $record) {
            $row = $this->buildRow($header, $record, $ownIban);

            if ($row['error'] === null) {
                $baseKey = $row['hash_base'];
                $occurrence = $occurrences[$baseKey] ?? 0;
                $occurrences[$baseKey] = $occurrence + 1;
                $row['import_hash'] = hash('sha256', $baseKey . '|' . $occurrence);
            }

            unset($row['hash_base']);
            $rows[] = $row;
        }

        // Chronologisch sortieren, damit die laufenden Nummern beim Import in der
        // Reihenfolge der Buchungen vergeben werden. Fehlerzeilen hängen hinten an.
        usort($rows, static function (array $a, array $b): int {
            return [$a['invoice_date'] ?? '9999-12-31', $a['description']]
                <=> [$b['invoice_date'] ?? '9999-12-31', $b['description']];
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['index'] = $index;
        }

        return $rows;
    }

    /**
     * @param array<string, int> $header
     * @param list<string|null> $record
     * @return array<string, mixed>
     */
    private function buildRow(array $header, array $record, ?string $ownIban): array
    {
        $senderName = $this->field($header, $record, self::COLUMN_SENDER_NAME);
        $senderIban = $this->field($header, $record, self::COLUMN_SENDER_IBAN);
        $receiverName = $this->field($header, $record, self::COLUMN_RECEIVER_NAME);
        $receiverIban = $this->field($header, $record, self::COLUMN_RECEIVER_IBAN);
        $purpose = $this->field($header, $record, self::COLUMN_PURPOSE);
        $bankText = $this->field($header, $record, self::COLUMN_TEXT);
        $currency = $this->field($header, $record, self::COLUMN_CURRENCY);

        $row = [
            'index' => 0,
            'invoice_date' => null,
            'payment_date' => null,
            'amount' => null,
            'type' => null,
            'description' => '',
            'counterparty' => '',
            'counterparty_iban' => '',
            'purpose' => $purpose,
            'import_hash' => null,
            'error' => null,
            'hash_base' => '',
        ];

        $bookingDate = $this->parseDate($this->field($header, $record, self::COLUMN_BOOKING_DATE));
        if ($bookingDate === null) {
            $row['error'] = 'Ungültiges Buchungsdatum.';
            $row['description'] = $this->buildDescription($senderName, $purpose);
            return $row;
        }
        $valueDate = $this->parseDate($this->field($header, $record, self::COLUMN_VALUE_DATE));

        $rawAmount = AmountNormalizer::normalize($this->field($header, $record, self::COLUMN_AMOUNT));
        if (!is_numeric($rawAmount) || abs((float) $rawAmount) < 0.005) {
            $row['invoice_date'] = $bookingDate;
            $row['payment_date'] = $valueDate;
            $row['error'] = 'Ungültiger Betrag.';
            $row['description'] = $this->buildDescription($senderName, $purpose);
            return $row;
        }

        $signedAmount = (float) $rawAmount;
        $type = $signedAmount < 0 ? 'expense' : 'income';

        // Gegenpartei ist immer die Seite, die nicht das eigene Konto ist. Bei
        // Lastschriften ist der Auftraggeber die Gegenpartei, obwohl der Betrag
        // negativ ist - die reine Vorzeichenregel würde dort danebengreifen.
        $senderIsOwn = $ownIban !== null && $senderIban === $ownIban;
        $receiverIsOwn = $ownIban !== null && $receiverIban === $ownIban;
        if ($senderIsOwn && !$receiverIsOwn) {
            $counterparty = $receiverName;
            $counterpartyIban = $receiverIban;
        } elseif ($receiverIsOwn && !$senderIsOwn) {
            $counterparty = $senderName;
            $counterpartyIban = $senderIban;
        } else {
            $counterparty = $type === 'expense' ? $receiverName : $senderName;
            $counterpartyIban = $type === 'expense' ? $receiverIban : $senderIban;
        }

        $row['invoice_date'] = $bookingDate;
        $row['payment_date'] = $valueDate;
        $row['amount'] = number_format(abs($signedAmount), 2, '.', '');
        $row['type'] = $type;
        $row['counterparty'] = $counterparty;
        $row['counterparty_iban'] = $counterpartyIban;
        $row['description'] = $this->buildDescription($counterparty, $purpose);

        if ($currency !== '' && strtoupper($currency) !== 'EUR') {
            $row['error'] = sprintf('Fremdwährung %s - es werden nur EUR-Buchungen importiert.', $currency);
            return $row;
        }

        $row['hash_base'] = implode('|', [
            $bookingDate,
            $valueDate ?? '',
            number_format($signedAmount, 2, '.', ''),
            $counterpartyIban,
            $purpose,
            $bankText,
        ]);

        return $row;
    }

    /**
     * Determines the statement's own account IBAN: the one that appears on every
     * single booking. Returns null when the statement is ambiguous.
     *
     * @param array<string, int> $header
     * @param list<list<string|null>> $records
     */
    private function detectOwnIban(array $header, array $records): ?string
    {
        $candidates = null;
        foreach ($records as $record) {
            $ibans = array_filter([
                $this->field($header, $record, self::COLUMN_SENDER_IBAN),
                $this->field($header, $record, self::COLUMN_RECEIVER_IBAN),
            ], static fn(string $iban): bool => $iban !== '');

            $candidates = $candidates === null ? $ibans : array_intersect($candidates, $ibans);
            if ($candidates === []) {
                return null;
            }
        }

        if ($candidates === null || count($candidates) !== 1) {
            return null;
        }

        return (string) reset($candidates);
    }

    private function buildDescription(string $counterparty, string $purpose): string
    {
        $parts = array_filter([trim($counterparty), trim($purpose)], static fn(string $p): bool => $p !== '');
        $description = implode(' - ', $parts);

        return mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH);
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        if ($date === false || $date->format('d.m.Y') !== $value) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param list<string|null> $fields
     * @return array<string, int>
     */
    private function mapHeader(array $fields): array
    {
        $header = [];
        foreach ($fields as $index => $name) {
            $key = mb_strtolower(trim((string) $name));
            if ($key === '') {
                continue;
            }
            $header[$key] = $index;
        }

        return $header;
    }

    /**
     * @param array<string, int> $header
     * @return list<string>
     */
    private function missingColumns(array $header): array
    {
        $required = [
            self::COLUMN_BOOKING_DATE => 'Buchungsdatum',
            self::COLUMN_AMOUNT => 'Betrag',
        ];

        $missing = [];
        foreach ($required as $key => $label) {
            if (!array_key_exists($key, $header)) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, int> $header
     * @param list<string|null> $record
     */
    private function field(array $header, array $record, string $column): string
    {
        $index = $header[$column] ?? null;
        if ($index === null || !array_key_exists($index, $record)) {
            return '';
        }

        return trim((string) $record[$index]);
    }

    private function normalizeEncoding(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            // Viele Bank-Exporte kommen als Windows-1252 statt UTF-8.
            $converted = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
            if (is_string($converted)) {
                $content = $converted;
            }
        }

        return $content;
    }
}
