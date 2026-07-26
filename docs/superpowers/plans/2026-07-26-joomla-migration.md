# Joomla-Migration – Umsetzungsplan

> **Für agentische Bearbeiter:** Dieser Plan wird Aufgabe für Aufgabe abgearbeitet.
> Schritte nutzen Checkbox-Syntax (`- [ ]`) zur Nachverfolgung.

**Ziel:** Zwei CLI-Skripte, die den Joomla-Bestand und elf Projekt-Excels zu einer
prüfbaren Arbeitsmappe verdichten und diese nach Freigabe in die ChorManager-Datenbank
schreiben.

**Architektur:** Vier Dateien unter `bin/migration/`. Zwei Bibliotheksdateien
(`NameTools.php` für Namenslogik, `SourceReader.php` für das Lesen der Quellen) und zwei
ausführbare Skripte (`build_workbook.php` erzeugt die Mappe, `import_workbook.php`
schreibt in die Datenbank). Die Zuordnung Datei → Layout ist als Konstante hinterlegt und
wird nicht geraten: bei zwölf bekannten Dateien ist eine explizite Tabelle verlässlicher
als eine Heuristik.

**Tech-Stack:** PHP 8.5, PhpSpreadsheet, Eloquent über den bestehenden
`CliBootstrap`, Ausführung über DDEV.

## Globale Vorgaben

- Zugrunde liegende Spec: `docs/superpowers/specs/2026-07-26-joomla-migration-design.md`
- Quellverzeichnis ist immer `var/import/`, Ergebnis ist `var/import/migration.xlsx`
- PSR-12, 4 Leerzeichen, weiche Zeilengrenze 120 Zeichen
- Alle Dateien mit LF-Zeilenenden anlegen
- Deutsche Texte mit echten Umlauten, keine Umschreibungen wie `ae` oder `ss`
- Kein `error_log()`; Ausgabe der Skripte erfolgt über `CliBootstrap::logger()` und
  `fwrite(STDOUT, ...)` für die Berichte
- Keine PHPUnit-Tests – bewusste Entscheidung des Auftraggebers. Jede Aufgabe endet
  stattdessen mit einem Verifikationslauf, dessen erwartete Ausgabe hier genannt ist.
- Kein `git push`

---

### Task 1: Namenslogik

**Dateien:**
- Anlegen: `bin/migration/NameTools.php`
- Anlegen: `bin/migration/verify_nametools.php` (Verifikationsskript, bleibt im Repo)
- Ändern: `composer.json` (Entwicklungsabhängigkeit)

**Schnittstellen:**
- Liefert: `NameTools::normalize(string): string`,
  `NameTools::sortKey(string): string`,
  `NameTools::similarity(string $a, string $b): float`,
  `NameTools::splitName(string): array{0:string,1:string}`,
  `NameTools::looksLikePerson(string): bool`

- [ ] **Schritt 1: PhpSpreadsheet ergänzen**

```bash
ddev composer require --dev phpoffice/phpspreadsheet
```

Erwartung: `composer.json` enthält den Eintrag unter `require-dev`, `vendor/phpoffice`
existiert.

- [ ] **Schritt 2: `bin/migration/NameTools.php` anlegen**

```php
<?php

declare(strict_types=1);

/**
 * Namenslogik für die einmalige Joomla-Migration.
 *
 * Die Quelldaten sind über Jahre von Hand gepflegt worden und enthalten
 * Tippfehler, Klammerzusätze und uneinheitliche Umlautschreibung.
 */
class NameTools
{
    /** Partikel, die zum Nachnamen gehören. */
    private const PARTICLES = ['von', 'van', 'de', 'der', 'den', 'du', 'zu', 'zur', 'la', 'le'];

    /** Bezeichnungen, hinter denen keine Person steckt. */
    private const NOISE = [
        'gesamt', 'sänger', 'saenger', 'nachname', 'vorname', 'name', 'mail',
        'telefon', 'stimm', 'funktion', 'nr.', 'spalte', 'summe', 'anzahl',
    ];

    private const VOICES = ['sopran', 'alt', 'tenor', 'bass'];

    /** Entfernt Klammerzusätze wie "(Projektausstieg)". */
    public static function stripAnnotations(string $value): string
    {
        $value = (string) preg_replace('/\([^)]*\)/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Vergleichsform: klein, ohne Diakritika, ohne Sonderzeichen.
     * "Höck" und "Hoeck" werden dadurch vergleichbar.
     */
    public static function normalize(string $value): string
    {
        $value = self::stripAnnotations($value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(
            ['ß', 'ä', 'ö', 'ü', 'æ', 'ø', 'å'],
            ['ss', 'ae', 'oe', 'ue', 'ae', 'oe', 'aa'],
            $value
        );

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = (string) preg_replace('/[^a-z ]/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** Reihenfolgeunabhängige Form, damit "Nachname Vorname" ebenso trifft. */
    public static function sortKey(string $value): string
    {
        $parts = array_filter(explode(' ', self::normalize($value)));
        sort($parts);

        return implode(' ', $parts);
    }

    /** Ähnlichkeit zweier Namen zwischen 0.0 und 1.0. */
    public static function similarity(string $a, string $b): float
    {
        $left = self::sortKey($a);
        $right = self::sortKey($b);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $distance = levenshtein($left, $right);
        $length = max(strlen($left), strlen($right));

        return round(1.0 - ($distance / $length), 3);
    }

    /**
     * Teilt einen vollständigen Namen in Vor- und Nachname.
     * Adelspartikel bleiben beim Nachnamen: "Oliver von Luxburg".
     */
    public static function splitName(string $value): array
    {
        $parts = array_values(array_filter(explode(' ', self::stripAnnotations($value))));

        if ($parts === []) {
            return ['', ''];
        }

        if (count($parts) === 1) {
            return ['', $parts[0]];
        }

        $splitAt = count($parts) - 1;
        for ($i = count($parts) - 2; $i >= 1; $i--) {
            if (in_array(mb_strtolower($parts[$i], 'UTF-8'), self::PARTICLES, true)) {
                $splitAt = $i;
            }
        }

        return [
            implode(' ', array_slice($parts, 0, $splitAt)),
            implode(' ', array_slice($parts, $splitAt)),
        ];
    }

    /** Prüft, ob eine Zelle plausibel einen Personennamen enthält. */
    public static function looksLikePerson(string $value): bool
    {
        $value = trim($value);

        if (mb_strlen($value, 'UTF-8') < 4) {
            return false;
        }

        if (preg_match('/[A-Za-zÄÖÜäöüß]/u', $value) !== 1) {
            return false;
        }

        $lower = mb_strtolower($value, 'UTF-8');

        foreach (self::VOICES as $voice) {
            if (str_starts_with($lower, $voice)) {
                return false;
            }
        }

        foreach (self::NOISE as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }

        return count(array_filter(explode(' ', $value))) >= 2;
    }
}
```

- [ ] **Schritt 3: `bin/migration/verify_nametools.php` anlegen**

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/NameTools.php';

$cases = [
    ['normalize', 'Jacob Höck', 'jacob hoeck'],
    ['normalize', 'Kathrin Gietl (Projektausstieg)', 'kathrin gietl'],
    ['normalize', 'Elisabeth  Damisch', 'elisabeth damisch'],
    ['sortKey', 'Pitterle Georg', 'georg pitterle'],
];

$failures = 0;

foreach ($cases as [$method, $input, $expected]) {
    $actual = NameTools::$method($input);
    $ok = $actual === $expected;
    $failures += $ok ? 0 : 1;
    printf("%s  %s(%s) = %s%s", $ok ? 'OK  ' : 'FEHL', $method, $input, $actual, PHP_EOL);
}

$splits = [
    ['Oliver von Luxburg', 'Oliver', 'von Luxburg'],
    ['Anna Katharina Tonner', 'Anna Katharina', 'Tonner'],
    ['Matthias von Stackelberg', 'Matthias', 'von Stackelberg'],
];

foreach ($splits as [$input, $expectedFirst, $expectedLast]) {
    [$first, $last] = NameTools::splitName($input);
    $ok = $first === $expectedFirst && $last === $expectedLast;
    $failures += $ok ? 0 : 1;
    printf("%s  splitName(%s) = [%s][%s]%s", $ok ? 'OK  ' : 'FEHL', $input, $first, $last, PHP_EOL);
}

$similar = NameTools::similarity('Franzsika Noichl', 'Franziska Noichl');
$ok = $similar >= 0.86;
$failures += $ok ? 0 : 1;
printf("%s  similarity(Tippfehler) = %s%s", $ok ? 'OK  ' : 'FEHL', $similar, PHP_EOL);

$noise = NameTools::looksLikePerson('war schon mal dabei!');
$failures += $noise ? 1 : 0;
printf("%s  looksLikePerson(Störzeile) = %s%s", $noise ? 'FEHL' : 'OK  ', var_export($noise, true), PHP_EOL);

printf('%s%d Abweichung(en)%s', PHP_EOL, $failures, PHP_EOL);
exit($failures === 0 ? 0 : 1);
```

- [ ] **Schritt 4: Verifikation ausführen**

```bash
ddev php bin/migration/verify_nametools.php
```

Erwartung: jede Zeile beginnt mit `OK`, Abschluss `0 Abweichung(en)`, Exitcode 0.

- [ ] **Schritt 5: Committen**

```bash
git add composer.json composer.lock bin/migration/NameTools.php bin/migration/verify_nametools.php
git commit -m "feat(migration): Namenslogik für Joomla-Migration"
```

---

### Task 2: Quellen lesen

**Dateien:**
- Anlegen: `bin/migration/SourceReader.php`
- Anlegen: `bin/migration/verify_sources.php`

**Schnittstellen:**
- Nutzt: `NameTools` aus Task 1
- Liefert:
  - `SourceReader::PROJECT_FILES` – Konstante mit einem Eintrag je Projektdatei:
    `['datei' => string, 'projekt' => string, 'jahr' => int, 'layout' => string]`
  - `SourceReader::readJoomla(string $csvPath): array` – Liste von
    `['joomla_id','anzeigename','username','email','aktiv','stimmgruppe','gruppen']`
  - `SourceReader::readProject(array $config, string $dir): array` – Liste von
    `['name','stimmgruppe','unterstimme']`
  - `SourceReader::readDirectory(string $path): array` – Liste von
    `['vorname','nachname','stimmlage','eintritt','austritt']`

- [ ] **Schritt 1: `bin/migration/SourceReader.php` anlegen**

```php
<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__ . '/NameTools.php';

/**
 * Liest die Migrationsquellen aus var/import/.
 *
 * Die Zuordnung Datei -> Layout ist bewusst fest hinterlegt statt geraten:
 * es sind zwölf bekannte Dateien aus einem abgeschlossenen Bestand.
 */
class SourceReader
{
    /** Kopfzeile mit getrennten Namensspalten. */
    public const LAYOUT_COLUMNS = 'spalten';

    /** Gruppenblöcke, Label nur in der ersten Zeile des Blocks. */
    public const LAYOUT_BLOCKS = 'bloecke';

    /** Ein Blatt je Stimmgruppe. */
    public const LAYOUT_SHEETS = 'blaetter';

    public const PROJECT_FILES = [
        [
            'datei' => '2019 Projekt Radio_ World Winter Master Games_Mitglieder.xlsx',
            'projekt' => 'Radio / World Winter Master Games',
            'jahr' => 2019,
            'layout' => self::LAYOUT_COLUMNS,
        ],
        [
            'datei' => '2020 Projekt Zeit_Mitglieder_kein Konzert wegen Corona.xlsx',
            'projekt' => 'Zeit 2020 (ohne Konzert)',
            'jahr' => 2020,
            'layout' => self::LAYOUT_COLUMNS,
        ],
        [
            'datei' => '2021 Projekt Zeit_Mitglieder.xlsx',
            'projekt' => 'Zeit 2021',
            'jahr' => 2021,
            'layout' => self::LAYOUT_COLUMNS,
        ],
        [
            'datei' => '2022 F Projekt Chorkuma goes to hollywood_Mitglieder.xlsx',
            'projekt' => 'Chorkuma goes to Hollywood',
            'jahr' => 2022,
            'layout' => self::LAYOUT_COLUMNS,
        ],
        [
            'datei' => '2022 W Projekt A-capella challenges.xlsx',
            'projekt' => 'A-capella Challenges',
            'jahr' => 2022,
            'layout' => self::LAYOUT_COLUMNS,
        ],
        [
            'datei' => '2023 Strings attached.xlsx',
            'projekt' => 'Strings attached',
            'jahr' => 2023,
            'layout' => self::LAYOUT_BLOCKS,
        ],
        [
            'datei' => '2023 und 2024 Frankreich und 10 Jahre.xlsx',
            'projekt' => 'Frankreich und 10 Jahre',
            'jahr' => 2023,
            'layout' => self::LAYOUT_BLOCKS,
        ],
        [
            'datei' => '2024 W Projekt MundART Brass.xlsx',
            'projekt' => 'MundART Brass',
            'jahr' => 2024,
            'layout' => self::LAYOUT_BLOCKS,
        ],
        [
            'datei' => '2025 Same but different .xlsx',
            'projekt' => 'Same but different',
            'jahr' => 2025,
            'layout' => self::LAYOUT_BLOCKS,
        ],
        [
            'datei' => '2025 W Elemente.xlsx',
            'projekt' => 'Elemente',
            'jahr' => 2025,
            'layout' => self::LAYOUT_BLOCKS,
        ],
        [
            'datei' => '2026 Tag Nacht.xlsx',
            'projekt' => 'Tag Nacht',
            'jahr' => 2026,
            'layout' => self::LAYOUT_SHEETS,
        ],
    ];

    public const VOICES = ['Sopran', 'Alt', 'Tenor', 'Bass'];

    /** Blätter, die trotz passenden Namens ignoriert werden. */
    private const SKIP_SHEETS = ['gesamt', 'w25 elemente'];

    /** @return list<array<string,string>> */
    public static function readJoomla(string $csvPath): array
    {
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Joomla-Export nicht lesbar: ' . $csvPath);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('Joomla-Export ist leer: ' . $csvPath);
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $record = array_combine($header, array_pad($row, count($header), ''));
            $record['anzeigename'] = trim((string) $record['anzeigename']);
            $record['email'] = trim((string) $record['email']);

            if ($record['anzeigename'] === '') {
                continue;
            }

            $rows[] = $record;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Liest die Teilnehmer eines Projekts.
     *
     * @return list<array{name:string,stimmgruppe:string,unterstimme:?int}>
     */
    public static function readProject(array $config, string $dir): array
    {
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $config['datei'];
        $sheets = self::loadSheets($path);

        return match ($config['layout']) {
            self::LAYOUT_COLUMNS => self::parseColumns($sheets),
            self::LAYOUT_BLOCKS => self::parseBlocks($sheets),
            self::LAYOUT_SHEETS => self::parseSheets($sheets),
            default => throw new RuntimeException('Unbekanntes Layout: ' . $config['layout']),
        };
    }

    /** @return array<string, list<list<string>>> Blattname => Zeilen => Zellen */
    private static function loadSheets(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $sheets = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = [];
            foreach ($sheet->toArray(null, true, false, false) as $row) {
                $rows[] = array_map(
                    static fn ($cell) => trim((string) ($cell ?? '')),
                    $row
                );
            }
            $sheets[$sheet->getTitle()] = $rows;
        }

        return $sheets;
    }

    /** Erkennt "Sopran 33", "Sopran (31)", "SOPRAN 25" und liefert "Sopran". */
    public static function matchVoice(string $value): ?string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');

        foreach (self::VOICES as $voice) {
            if (str_starts_with($lower, mb_strtolower($voice, 'UTF-8'))) {
                return $voice;
            }
        }

        return null;
    }

    /** Layout A: Kopfzeile mit Vorname/Nachname/Stimmlage. */
    private static function parseColumns(array $sheets): array
    {
        $result = [];

        foreach ($sheets as $rows) {
            $lastIndex = null;
            $voiceIndex = null;

            foreach ($rows as $row) {
                if ($lastIndex === null) {
                    foreach ($row as $index => $cell) {
                        $lower = mb_strtolower($cell, 'UTF-8');
                        if ($lower === 'nachname') {
                            $lastIndex = $index;
                        }
                        if ($lower === 'stimmlage' || $lower === 'stimmgruppe') {
                            $voiceIndex = $index;
                        }
                    }
                    continue;
                }

                // Die Vornamenspalte steht links neben Nachname; in den 2022er
                // Dateien fehlt dort die Kopfzelle, deshalb über die Position.
                $firstIndex = $lastIndex - 1;
                $first = $row[$firstIndex] ?? '';
                $last = $row[$lastIndex] ?? '';
                $full = trim($first . ' ' . $last);

                if (!NameTools::looksLikePerson($full)) {
                    continue;
                }

                $rawVoice = $voiceIndex !== null ? ($row[$voiceIndex] ?? '') : '';
                foreach (self::splitVoices($rawVoice) as $voice) {
                    $result[] = ['name' => $full, 'stimmgruppe' => $voice, 'unterstimme' => null];
                }

                if (self::splitVoices($rawVoice) === []) {
                    $result[] = ['name' => $full, 'stimmgruppe' => '', 'unterstimme' => null];
                }
            }
        }

        return $result;
    }

    /** Zerlegt "Bass/Tenor" in zwei Stimmgruppen. */
    private static function splitVoices(string $value): array
    {
        $voices = [];

        foreach (preg_split('#[/,]#', $value) ?: [] as $part) {
            $voice = self::matchVoice(trim($part));
            if ($voice !== null && !in_array($voice, $voices, true)) {
                $voices[] = $voice;
            }
        }

        return $voices;
    }

    /** Layout B: Blockliste, Label nur in der ersten Zeile eines Blocks. */
    private static function parseBlocks(array $sheets): array
    {
        $result = [];

        foreach ($sheets as $title => $rows) {
            if (in_array(mb_strtolower($title, 'UTF-8'), self::SKIP_SHEETS, true)) {
                continue;
            }

            $currentVoice = '';

            foreach ($rows as $row) {
                $label = $row[1] ?? '';
                $voice = $label !== '' ? self::matchVoice($label) : null;
                if ($voice !== null) {
                    $currentVoice = $voice;
                }

                $name = $row[2] ?? '';
                if (!NameTools::looksLikePerson($name)) {
                    continue;
                }

                $sub = trim((string) ($row[3] ?? ''));
                $result[] = [
                    'name' => $name,
                    'stimmgruppe' => $currentVoice,
                    'unterstimme' => in_array($sub, ['1', '2'], true) ? (int) $sub : null,
                ];
            }
        }

        return $result;
    }

    /** Layout C: ein Blatt je Stimmgruppe, Name in der letzten belegten Spalte. */
    private static function parseSheets(array $sheets): array
    {
        $result = [];

        foreach ($sheets as $title => $rows) {
            if (in_array(mb_strtolower($title, 'UTF-8'), self::SKIP_SHEETS, true)) {
                continue;
            }

            $voice = self::matchVoice($title);
            if ($voice === null) {
                continue;
            }

            foreach ($rows as $row) {
                $filled = array_values(array_filter($row, static fn ($cell) => $cell !== ''));
                if ($filled === []) {
                    continue;
                }

                $name = (string) end($filled);
                if (!NameTools::looksLikePerson($name)) {
                    continue;
                }

                $result[] = ['name' => $name, 'stimmgruppe' => $voice, 'unterstimme' => null];
            }
        }

        return $result;
    }

    /**
     * Mitgliederverzeichnis als Stammdatenquelle.
     *
     * @return list<array{vorname:string,nachname:string,stimmlage:string}>
     */
    public static function readDirectory(string $path): array
    {
        $sheets = self::loadSheets($path);
        $result = [];

        foreach ($sheets as $rows) {
            $map = null;

            foreach ($rows as $row) {
                if ($map === null) {
                    $candidate = [];
                    foreach ($row as $index => $cell) {
                        $candidate[mb_strtolower($cell, 'UTF-8')] = $index;
                    }
                    if (isset($candidate['nachname'], $candidate['vorname'])) {
                        $map = $candidate;
                    }
                    continue;
                }

                $first = trim((string) ($row[$map['vorname']] ?? ''));
                $last = trim((string) ($row[$map['nachname']] ?? ''));

                if (!NameTools::looksLikePerson(trim($first . ' ' . $last))) {
                    continue;
                }

                $result[] = [
                    'vorname' => $first,
                    'nachname' => $last,
                    'stimmlage' => trim((string) ($row[$map['stimmlage'] ?? -1] ?? '')),
                ];
            }
        }

        return $result;
    }
}
```

- [ ] **Schritt 2: `bin/migration/verify_sources.php` anlegen**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap_cli.php';
require_once __DIR__ . '/SourceReader.php';

$dir = __DIR__ . '/../../var/import';

$joomla = SourceReader::readJoomla($dir . '/joomla_users.csv');
printf('Joomla-Datensätze: %d%s', count($joomla), PHP_EOL);

$directory = SourceReader::readDirectory($dir . '/Mitgliederverzeichnis.xlsx');
printf('Mitgliederverzeichnis: %d%s%s', count($directory), PHP_EOL, PHP_EOL);

$total = 0;
foreach (SourceReader::PROJECT_FILES as $config) {
    $rows = SourceReader::readProject($config, $dir);
    $names = array_unique(array_column($rows, 'name'));
    $withSub = count(array_filter($rows, static fn ($r) => $r['unterstimme'] !== null));
    $withoutVoice = count(array_filter($rows, static fn ($r) => $r['stimmgruppe'] === ''));
    $total += count($names);

    printf(
        "%-38s %3d Namen, %3d mit Unterstimme, %2d ohne Stimmgruppe%s",
        $config['projekt'],
        count($names),
        $withSub,
        $withoutVoice,
        PHP_EOL
    );
}

printf('%sSumme Teilnahmen: %d%s', PHP_EOL, $total, PHP_EOL);
```

- [ ] **Schritt 3: Verifikation ausführen**

```bash
ddev php bin/migration/verify_sources.php
```

Erwartung, abgeglichen mit den Kopfzeilen der Quelldateien:

- Joomla-Datensätze: 373
- `Strings attached` etwa 86 Namen, `Elemente` etwa 92, `Tag Nacht` etwa 80
- Die fünf Dateien mit Layout `bloecke` melden eine Zahl größer 0 bei
  „mit Unterstimme"; die Spaltenlayouts melden dort 0
- „ohne Stimmgruppe" bleibt einstellig

Weicht eine Zahl deutlich von der in der Datei genannten Gesamtzahl ab, ist der
Parser für dieses Layout zu korrigieren, bevor es weitergeht.

- [ ] **Schritt 4: Committen**

```bash
git add bin/migration/SourceReader.php bin/migration/verify_sources.php
git commit -m "feat(migration): Quellen aus Joomla-Export und Projekt-Excels lesen"
```

---

### Task 3: Arbeitsmappe erzeugen

**Dateien:**
- Anlegen: `bin/migration/build_workbook.php`

**Schnittstellen:**
- Nutzt: `NameTools`, `SourceReader` aus Task 1 und 2
- Liefert: `var/import/migration.xlsx` mit den Blättern `Projekte`, `Personen`,
  `Teilnahmen`, `Joomla`, `Verworfen`

- [ ] **Schritt 1: `bin/migration/build_workbook.php` anlegen**

Aufbau des Skripts:

1. Quellen lesen (`SourceReader`)
2. Alle Projektnamen einsammeln, je Person das jüngste Projekt merken
3. Gegen Joomla abgleichen: erst `sortKey`-Gleichheit, sonst bester
   `similarity`-Wert; Schwelle 0.86
4. Vor-/Nachname bevorzugt aus dem Mitgliederverzeichnis, sonst `splitName`
5. Fünf Blätter schreiben

```php
<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__ . '/../bootstrap_cli.php';
require_once __DIR__ . '/SourceReader.php';

const SCHWELLE = 0.86;

$dir = __DIR__ . '/../../var/import';
$ziel = $dir . '/migration.xlsx';

$joomla = SourceReader::readJoomla($dir . '/joomla_users.csv');
$directory = SourceReader::readDirectory($dir . '/Mitgliederverzeichnis.xlsx');

// Nachschlagewerk für die Namensauflösung aus dem Mitgliederverzeichnis.
$namesFromDirectory = [];
foreach ($directory as $entry) {
    $key = NameTools::sortKey($entry['vorname'] . ' ' . $entry['nachname']);
    $namesFromDirectory[$key] = [$entry['vorname'], $entry['nachname']];
}

$joomlaByKey = [];
foreach ($joomla as $record) {
    $joomlaByKey[NameTools::sortKey($record['anzeigename'])][] = $record;
}
$joomlaKeys = array_keys($joomlaByKey);

$teilnahmen = [];
$personen = [];
$verworfen = [];

foreach (SourceReader::PROJECT_FILES as $config) {
    foreach (SourceReader::readProject($config, $dir) as $row) {
        $name = NameTools::stripAnnotations($row['name']);

        if (!NameTools::looksLikePerson($name)) {
            $verworfen[] = [$config['projekt'], $row['name'], 'kein Personenname'];
            continue;
        }

        $key = NameTools::sortKey($name);
        $teilnahmen[] = [
            'projekt' => $config['projekt'],
            'jahr' => $config['jahr'],
            'name' => $name,
            'schluessel' => $key,
            'stimmgruppe' => $row['stimmgruppe'],
            'unterstimme' => $row['unterstimme'],
        ];

        // Jüngstes Projekt gewinnt für die spätere Stimmzuordnung.
        if (!isset($personen[$key]) || $personen[$key]['jahr'] <= $config['jahr']) {
            $personen[$key] = [
                'name' => $name,
                'jahr' => $config['jahr'],
                'stimmgruppe' => $row['stimmgruppe'] ?: ($personen[$key]['stimmgruppe'] ?? ''),
                'unterstimme' => $row['unterstimme'] ?? ($personen[$key]['unterstimme'] ?? null),
            ];
        }
    }
}

ksort($personen);

$zeilenPersonen = [];
foreach ($personen as $key => $person) {
    $treffer = null;
    $score = 1.0;
    $status = 'KEIN_TREFFER';

    if (isset($joomlaByKey[$key])) {
        $treffer = $joomlaByKey[$key][0];
        $status = 'OK';
    } else {
        $bestKey = null;
        foreach ($joomlaKeys as $candidate) {
            $value = NameTools::similarity($key, $candidate);
            if ($bestKey === null || $value > $score) {
                $bestKey = $candidate;
                $score = $value;
            }
        }

        if ($bestKey !== null && $score >= SCHWELLE) {
            $treffer = $joomlaByKey[$bestKey][0];
            $status = 'PRUEFEN';
        } else {
            $score = $bestKey === null ? 0.0 : $score;
        }
    }

    $anzeigename = $treffer['anzeigename'] ?? $person['name'];
    $splitKey = NameTools::sortKey($anzeigename);
    [$vorname, $nachname] = $namesFromDirectory[$splitKey]
        ?? $namesFromDirectory[$key]
        ?? NameTools::splitName($anzeigename);

    $gruppen = $treffer['gruppen'] ?? '';
    $aktiv = str_contains(mb_strtolower($gruppen, 'UTF-8'), 'aktive chormitglieder') ? 1 : 0;

    $zeilenPersonen[] = [
        $person['name'],
        $treffer['anzeigename'] ?? '',
        $status === 'OK' ? 1.0 : $score,
        $status,
        $treffer['email'] ?? '',
        $vorname,
        $nachname,
        $person['stimmgruppe'] ?: ($treffer['stimmgruppe'] ?? ''),
        $person['unterstimme'] ?? '',
        $aktiv,
        $status === 'KEIN_TREFFER' ? 'ueberspringen' : 'uebernehmen',
    ];
}

$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

$schreibe = static function (Spreadsheet $book, string $titel, array $kopf, array $zeilen): void {
    $sheet = $book->createSheet();
    $sheet->setTitle($titel);
    $sheet->fromArray($kopf, null, 'A1');
    if ($zeilen !== []) {
        $sheet->fromArray($zeilen, null, 'A2');
    }
    $sheet->freezePane('A2');
    foreach (range('A', chr(ord('A') + count($kopf) - 1)) as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
};

$projektZeilen = [];
foreach (SourceReader::PROJECT_FILES as $config) {
    $anzahl = count(array_filter(
        $teilnahmen,
        static fn ($t) => $t['projekt'] === $config['projekt']
    ));
    $projektZeilen[] = [
        $config['projekt'],
        $config['jahr'],
        sprintf('%d-01-01', $config['jahr']),
        sprintf('%d-12-31', $config['jahr']),
        $config['datei'],
        $anzahl,
    ];
}

$schreibe(
    $spreadsheet,
    'Projekte',
    ['Projekt', 'Jahr', 'Start', 'Ende', 'Quelldatei', 'Teilnehmer'],
    $projektZeilen
);

$schreibe(
    $spreadsheet,
    'Personen',
    [
        'Excel-Name', 'Joomla-Treffer', 'Score', 'Status', 'E-Mail',
        'Vorname', 'Nachname', 'Stimmgruppe', 'Unterstimme', 'Aktiv', 'Aktion',
    ],
    $zeilenPersonen
);

$schreibe(
    $spreadsheet,
    'Teilnahmen',
    ['Projekt', 'Jahr', 'Excel-Name', 'Stimmgruppe', 'Unterstimme'],
    array_map(
        static fn ($t) => [
            $t['projekt'], $t['jahr'], $t['name'], $t['stimmgruppe'], $t['unterstimme'] ?? '',
        ],
        $teilnahmen
    )
);

$schreibe(
    $spreadsheet,
    'Joomla',
    ['Joomla-ID', 'Anzeigename', 'Benutzername', 'E-Mail', 'Stimmgruppe', 'Gruppen'],
    array_map(
        static fn ($r) => [
            $r['joomla_id'], $r['anzeigename'], $r['username'],
            $r['email'], $r['stimmgruppe'], $r['gruppen'],
        ],
        $joomla
    )
);

$schreibe($spreadsheet, 'Verworfen', ['Projekt', 'Zelleninhalt', 'Grund'], $verworfen);

(new Xlsx($spreadsheet))->save($ziel);

$zaehler = array_count_values(array_column($zeilenPersonen, 3));
printf('Arbeitsmappe geschrieben: %s%s', $ziel, PHP_EOL);
printf('Personen gesamt : %d%s', count($zeilenPersonen), PHP_EOL);
printf('  OK            : %d%s', $zaehler['OK'] ?? 0, PHP_EOL);
printf('  PRUEFEN       : %d%s', $zaehler['PRUEFEN'] ?? 0, PHP_EOL);
printf('  KEIN_TREFFER  : %d%s', $zaehler['KEIN_TREFFER'] ?? 0, PHP_EOL);
printf('Teilnahmen      : %d%s', count($teilnahmen), PHP_EOL);
printf('Verworfen       : %d%s', count($verworfen), PHP_EOL);
```

- [ ] **Schritt 2: Ausführen**

```bash
ddev php bin/migration/build_workbook.php
```

Erwartung, abgeglichen mit der Vorabanalyse aus der Spec: rund 300 Zeilen `OK`,
etwa 40 `PRUEFEN`, etwa 15 `KEIN_TREFFER`, keine Ausnahme. `var/import/migration.xlsx`
existiert und lässt sich in Excel öffnen.

- [ ] **Schritt 3: Stichprobe prüfen**

Blatt `Personen` öffnen und nach Status `PRUEFEN` filtern. Die in der Spec genannten
Fälle müssen als Vorschlag erscheinen, etwa `Franzsika Noichl` gegen
`Franziska Noichl` und `Elias Krujen` gegen `Elias Kruijen`. Blatt `Verworfen`
muss die Zeile `war schon mal dabei!` enthalten.

- [ ] **Schritt 4: Committen**

```bash
git add bin/migration/build_workbook.php
git commit -m "feat(migration): Arbeitsmappe mit Abgleich und Freigabespalte erzeugen"
```

---

### Task 4: Import in die Datenbank

**Dateien:**
- Anlegen: `bin/migration/import_workbook.php`

**Schnittstellen:**
- Nutzt: `var/import/migration.xlsx` aus Task 3, Modelle `App\Models\User`,
  `VoiceGroup`, `SubVoice`, `Project`
- Liefert: befüllte Tabellen `users`, `voice_groups`, `sub_voices`,
  `user_voice_groups`, `projects`, `project_users`

- [ ] **Schritt 1: `bin/migration/import_workbook.php` anlegen**

```php
<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Util\CliBootstrap;
use Illuminate\Database\Capsule\Manager as Capsule;
use PhpOffice\PhpSpreadsheet\IOFactory;

require __DIR__ . '/../bootstrap_cli.php';
require_once __DIR__ . '/NameTools.php';

$commit = in_array('--commit', $argv, true);

$logger = CliBootstrap::logger();
$container = CliBootstrap::container();
$container->get(Capsule::class);

$pfad = __DIR__ . '/../../var/import/migration.xlsx';
if (!is_file($pfad)) {
    fwrite(STDERR, 'Arbeitsmappe fehlt: ' . $pfad . PHP_EOL);
    exit(1);
}

$reader = IOFactory::createReaderForFile($pfad);
$reader->setReadDataOnly(true);
$book = $reader->load($pfad);

/** Liest ein Blatt als Liste assoziativer Zeilen. */
$lies = static function (string $titel) use ($book): array {
    $sheet = $book->getSheetByName($titel);
    if ($sheet === null) {
        throw new RuntimeException('Blatt fehlt: ' . $titel);
    }

    $rows = $sheet->toArray(null, true, false, false);
    $kopf = array_map(static fn ($c) => trim((string) ($c ?? '')), array_shift($rows) ?? []);

    $ergebnis = [];
    foreach ($rows as $row) {
        $werte = array_map(static fn ($c) => trim((string) ($c ?? '')), $row);
        if (implode('', $werte) === '') {
            continue;
        }
        $ergebnis[] = array_combine($kopf, array_pad($werte, count($kopf), ''));
    }

    return $ergebnis;
};

$personen = $lies('Personen');
$projekte = $lies('Projekte');
$teilnahmen = $lies('Teilnahmen');

$bericht = [
    'benutzer_neu' => 0,
    'benutzer_aktualisiert' => 0,
    'benutzer_uebersprungen' => 0,
    'stimmgruppen' => 0,
    'unterstimmen' => 0,
    'projekte' => 0,
    'zuordnungen' => 0,
    'teilnahmen' => 0,
];

Capsule::connection()->beginTransaction();

try {
    // Stimmgruppen und Unterstimmen
    $voiceIds = [];
    $subIds = [];
    foreach (['Sopran', 'Alt', 'Tenor', 'Bass'] as $name) {
        $voice = VoiceGroup::where('name', $name)->first();
        if ($voice === null) {
            $voice = VoiceGroup::create(['name' => $name]);
            $bericht['stimmgruppen']++;
        }
        $voiceIds[$name] = (int) $voice->id;

        foreach ([1, 2] as $nummer) {
            $subName = $name . ' ' . $nummer;
            $sub = SubVoice::where('name', $subName)
                ->where('voice_group_id', $voiceIds[$name])
                ->first();
            if ($sub === null) {
                $sub = SubVoice::create(['name' => $subName, 'voice_group_id' => $voiceIds[$name]]);
                $bericht['unterstimmen']++;
            }
            $subIds[$subName] = (int) $sub->id;
        }
    }

    // Benutzer
    $userIdByKey = [];
    foreach ($personen as $zeile) {
        if ($zeile['Aktion'] !== 'uebernehmen') {
            $bericht['benutzer_uebersprungen']++;
            continue;
        }

        $email = mb_strtolower($zeile['E-Mail'], 'UTF-8');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $bericht['benutzer_uebersprungen']++;
            continue;
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $user = User::create([
                'email' => $email,
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'first_name' => $zeile['Vorname'],
                'last_name' => $zeile['Nachname'],
                'is_active' => (int) ($zeile['Aktiv'] ?: 0),
            ]);
            $bericht['benutzer_neu']++;
        } else {
            $user->first_name = $zeile['Vorname'];
            $user->last_name = $zeile['Nachname'];
            $user->is_active = (int) ($zeile['Aktiv'] ?: 0);
            $user->save();
            $bericht['benutzer_aktualisiert']++;
        }

        $userIdByKey[NameTools::sortKey($zeile['Excel-Name'])] = (int) $user->id;

        // Stimmzuordnung aus dem jüngsten Projekt
        $voice = $zeile['Stimmgruppe'];
        if (isset($voiceIds[$voice])) {
            $subId = null;
            if (in_array($zeile['Unterstimme'], ['1', '2'], true)) {
                $subId = $subIds[$voice . ' ' . $zeile['Unterstimme']];
            }

            Capsule::table('user_voice_groups')->updateOrInsert(
                ['user_id' => (int) $user->id, 'voice_group_id' => $voiceIds[$voice]],
                ['sub_voice_id' => $subId]
            );
            $bericht['zuordnungen']++;
        }
    }

    // Projekte
    $projectIds = [];
    foreach ($projekte as $zeile) {
        $projekt = Project::where('name', $zeile['Projekt'])->first();
        if ($projekt === null) {
            $projekt = Project::create([
                'name' => $zeile['Projekt'],
                'description' => null,
                'start_date' => $zeile['Start'] ?: null,
                'end_date' => $zeile['Ende'] ?: null,
            ]);
            $bericht['projekte']++;
        }
        $projectIds[$zeile['Projekt']] = (int) $projekt->id;
    }

    // Teilnahmen
    $gesehen = [];
    foreach ($teilnahmen as $zeile) {
        $projectId = $projectIds[$zeile['Projekt']] ?? null;
        $userId = $userIdByKey[NameTools::sortKey($zeile['Excel-Name'])] ?? null;

        if ($projectId === null || $userId === null) {
            continue;
        }

        $paar = $projectId . ':' . $userId;
        if (isset($gesehen[$paar])) {
            continue;
        }
        $gesehen[$paar] = true;

        Capsule::table('project_users')->updateOrInsert(
            ['project_id' => $projectId, 'user_id' => $userId],
            []
        );
        $bericht['teilnahmen']++;
    }

    if ($commit) {
        Capsule::connection()->commit();
        $logger->info('Migration übernommen.', ['event' => 'migration.committed', 'report' => $bericht]);
    } else {
        Capsule::connection()->rollBack();
        $logger->info('Probelauf beendet.', ['event' => 'migration.dry_run', 'report' => $bericht]);
    }
} catch (Throwable $e) {
    Capsule::connection()->rollBack();
    $logger->error('Migration fehlgeschlagen.', ['event' => 'migration.failed', 'exception' => $e]);
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, ($commit ? 'ÜBERNOMMEN' : 'PROBELAUF (nichts geschrieben)') . PHP_EOL);
foreach ($bericht as $schluessel => $wert) {
    fwrite(STDOUT, sprintf('  %-24s %d%s', $schluessel, $wert, PHP_EOL));
}
```

- [ ] **Schritt 2: Probelauf**

```bash
ddev php bin/migration/import_workbook.php
```

Erwartung: Ausgabe beginnt mit `PROBELAUF (nichts geschrieben)`, `benutzer_neu`
liegt bei rund 340, `projekte` bei 11, `teilnahmen` bei über 800. Danach prüfen,
dass die Datenbank unverändert ist:

```bash
ddev exec mysql -e "SELECT COUNT(*) FROM users;" db
```

- [ ] **Schritt 3: Übernahme**

```bash
ddev php bin/migration/import_workbook.php --commit
```

Erwartung: Ausgabe beginnt mit `ÜBERNOMMEN`, dieselben Zahlen wie im Probelauf.

- [ ] **Schritt 4: Zweiter Lauf zur Prüfung der Wiederholbarkeit**

```bash
ddev php bin/migration/import_workbook.php --commit
```

Erwartung: `benutzer_neu` und `projekte` sind 0, `benutzer_aktualisiert` entspricht
der Zahl aus dem ersten Lauf. Entstehen erneut neue Datensätze, ist die
Wiedererkennung fehlerhaft und muss korrigiert werden.

- [ ] **Schritt 5: Ergebnis stichprobenartig ansehen**

```bash
ddev exec mysql -e "SELECT p.name, COUNT(pu.user_id) FROM projects p LEFT JOIN project_users pu ON pu.project_id = p.id GROUP BY p.id, p.name ORDER BY p.name;" db
```

Erwartung: elf Projekte mit Teilnehmerzahlen in der Größenordnung der Kopfzeilen
der Quelldateien (rund 58 bis 92).

- [ ] **Schritt 6: Committen**

```bash
git add bin/migration/import_workbook.php
git commit -m "feat(migration): Arbeitsmappe in die ChorManager-Datenbank übernehmen"
```

---

## Hinweis vor dem Produktivlauf

`import_workbook.php` schreibt in die Datenbank, auf die der aktuelle
`CliBootstrap` zeigt. Vor einem Lauf gegen einen echten Bestand ist eine
Sicherung anzulegen; das Projekt bringt dafür `bin/create_backup.php` mit.
