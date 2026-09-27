<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Hält die `$hidden`-Listen der Modelle mit dem Schema zusammen.
 *
 * `SecretAttributesAreHiddenTest` und `BlobAttributesAreHiddenTest` prüfen je eine
 * von Hand geführte Aufzählung. Beide sind hinter dem Bestand zurückgeblieben: Die
 * Geheimnisse der OIDC-Modelle, `WebdavAccessToken::$token_hash` und
 * `MailQueue::$body_html` stehen in keiner der beiden, obwohl die Modelle sie
 * verbergen. Fällt dort ein `$hidden` weg, meldet das niemand.
 *
 * Dieser Wächter dreht die Richtung: Er geht vom Schema aus und verlangt für jede
 * Spalte, deren Name nach einem Geheimnis klingt, entweder einen Eintrag in
 * `$hidden` oder einen begründeten Eintrag in der Ausnahmeliste unten. Eine neue
 * Spalte mit `token`, `password` oder `_hash` im Namen ist damit von selbst
 * erfasst - sie fällt auf, bevor sie in einer Logzeile landet.
 *
 * Die Ausnahmeliste bleibt bewusst klein und wird einzeln begründet: Sie ist der
 * Ort, an dem jemand bewusst sagt "das sieht nach Geheimnis aus, ist aber keins".
 */
final class SecretColumnsStayHiddenTest extends TestCase
{
    /**
     * Spaltennamen, die auf ein Geheimnis oder einen zu großen Inhalt hindeuten.
     */
    private const SECRET_PATTERN = '/(password|secret|token|_hash$|private_key|_enc$|'
        . 'credential|binary_content|file_content|raw_payload|payload_json|body_html|code_challenge)/i';

    /**
     * Spalten, die auf das Muster passen, aber kein Geheimnis sind.
     *
     * @var array<string, string> "tabelle.spalte" => Begründung
     */
    private const NOT_A_SECRET = [
        // Fingerabdruck einer importierten CSV-Zeile, damit derselbe Kontoauszug
        // nicht zweimal einläuft. Er öffnet nichts und wird in der Oberfläche
        // sogar angezeigt, um einen Doppelimport zu erklären.
        'finances.import_hash' => 'Abgleichswert gegen Doppelimport, kein Geheimnis.',
        // Der Name des Verfahrens ("S256"), nicht die Prüfsumme selbst. Die steht
        // in `code_challenge` und ist verborgen.
        'oidc_auth_codes.code_challenge_method' => 'Verfahrensname, im Protokoll ohnehin öffentlich.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    public function testSpaltenMitGeheimnisImNamenStehenInHidden(): void
    {
        $problems = [];
        $checked = 0;

        foreach ($this->models() as $class) {
            $model = new $class();
            $hidden = $model->getHidden();

            foreach ($this->columnsOf($model->getTable()) as $column) {
                if (preg_match(self::SECRET_PATTERN, $column) !== 1) {
                    continue;
                }

                $checked++;

                if (array_key_exists($model->getTable() . '.' . $column, self::NOT_A_SECRET)) {
                    continue;
                }

                if (in_array($column, $hidden, true)) {
                    continue;
                }

                $problems[] = sprintf(
                    '%s::$hidden nennt "%s" nicht - die Spalte %s.%s klingt nach einem Geheimnis. '
                    . 'Entweder in $hidden aufnehmen oder in SecretColumnsStayHiddenTest::NOT_A_SECRET begründen.',
                    $class,
                    $column,
                    $model->getTable(),
                    $column
                );
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(
            10,
            $checked,
            'Es wurden kaum Spalten geprüft - der Wächter liefe sonst leer durch.'
        );
    }

    /**
     * Ein verborgenes Attribut bleibt über den Eigenschaftszugriff erreichbar.
     * Ohne das wäre `$hidden` keine Grenze für die Ausgabe, sondern ein Verlust.
     */
    public function testVerborgeneAttributeBleibenImCodeLesbar(): void
    {
        $model = new \App\Models\MailDeliveryEvent();
        $model->setRawAttributes(['id' => 1, 'raw_payload' => '{"event":"delivered"}']);

        self::assertArrayNotHasKey('raw_payload', $model->toArray());
        self::assertSame('{"event":"delivered"}', $model->getAttribute('raw_payload'));
    }

    /**
     * Jede Ausnahme muss eine Spalte benennen, die es wirklich gibt. Sonst bleibt
     * eine Begründung stehen, nachdem die Spalte längst umbenannt wurde - und
     * deckt womöglich die neue nicht mehr ab.
     */
    public function testAusnahmenZeigenAufVorhandeneSpalten(): void
    {
        foreach (array_keys(self::NOT_A_SECRET) as $qualified) {
            [$table, $column] = explode('.', $qualified, 2);

            self::assertContains(
                $column,
                $this->columnsOf($table),
                sprintf('NOT_A_SECRET nennt "%s" - diese Spalte gibt es nicht (mehr).', $qualified)
            );
        }
    }

    /**
     * @return list<class-string<Model>>
     */
    private function models(): array
    {
        $classes = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        $columns = [];

        foreach (Capsule::connection()->select('SHOW COLUMNS FROM `' . $table . '`') as $column) {
            $columns[] = (string) ((array) $column)['Field'];
        }

        return $columns;
    }
}
