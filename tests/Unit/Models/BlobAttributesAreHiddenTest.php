<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AppSetting;
use App\Models\Attachment;
use PHPUnit\Framework\TestCase;

/**
 * BLOB-Spalten dürfen nicht über die Serialisierung eines Modells nach draußen
 * gelangen.
 *
 * Die Aufrufer halten den Inhalt heute schon aus ihren Abfragen heraus und
 * begründen das an Ort und Stelle - `EntityAttachmentService::LIST_COLUMNS`,
 * `FinanceController`, `SponsorController`, `FinanceCsvExportService`. Diese
 * Grenze hängt aber an jeder einzelnen Abfrage: Wird ein Anhang doch einmal mit
 * allen Spalten geladen und danach serialisiert - in einer JSON-Antwort, in
 * einer Logzeile, in einer Fehlerausgabe, die ein Modell mitschreibt - liegt der
 * ganze Dateiinhalt in der Ausgabe.
 *
 * Anhänge sind rechtegeprüfte Inhalte (`AttachmentController`); ein Inhalt, der
 * über die Serialisierung entweicht, umgeht diese Prüfung. Dazu kommt die
 * Größe: Ein BLOB in einer Logzeile sprengt jede Zeile im Container-Log.
 *
 * `$hidden` ist dieselbe zweite Absicherung wie bei `User::$password` und
 * `UserMailAccount::$imap_password_enc` - der Code selbst kommt über den
 * Eigenschaftszugriff unverändert heran.
 */
final class BlobAttributesAreHiddenTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string}>
     */
    public static function blobProvider(): array
    {
        return [
            'Anhang' => [Attachment::class, 'file_content'],
            'Logo in den Einstellungen' => [AppSetting::class, 'binary_content'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blobProvider')]
    public function testBlobBleibtAusDerSerialisierung(string $class, string $attribute): void
    {
        $binary = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64);

        $model = new $class();
        $model->setRawAttributes(['id' => 1, $attribute => $binary]);

        self::assertArrayNotHasKey($attribute, $model->toArray());
        self::assertSame(
            $binary,
            $model->getAttribute($attribute),
            'Auslieferung und Mail-Branding lesen die Eigenschaft direkt und müssen weiter herankommen.'
        );
    }
}
