<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Wächter gegen einen Rückfall: Ein Formularfeld darf nicht ungeprüft in eine
 * Funktion mit Typangabe laufen.
 *
 * `trim($data['name'] ?? '')` stand an 51 Stellen in 13 Controllern. Kommt das
 * Feld als `name[]=x` herein - und der Browser darf das -, liegt in
 * `getParsedBody()` ein Array, und `trim()` wirft unter `strict_types` einen
 * TypeError: Statusseite 500 statt einer Formularmeldung. Jede dieser Masken war
 * damit von jedem angemeldeten Mitglied lahmzulegen.
 *
 * `InputValidator::asString()` gibt es für genau diesen Fall und begründet ihn in
 * seinem Kommentar. Der Wächter liest nur Dateien, wie
 * Tests\Unit\TestSuite\NoHandBuiltSchemaTest.
 */
final class FormFieldsGoThroughInputValidatorTest extends TestCase
{
    /**
     * Auch die Unterordner (`Concerns/`, `Oidc/`).
     *
     * Zuvor stand hier nur `src/Controllers/*.php`. Die beiden Unterordner blieben
     * dadurch ungesehen, und in `Concerns/FileControllerSupport` lag genau der Guss,
     * den dieser Wächter verbieten soll - er versorgt rund fünfzehn Aufrufstellen
     * der Dateiverwaltung mit ihren Formularwerten.
     *
     * @return list<string>
     */
    private function controllerFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Controllers';
        $files = array_merge(glob($root . '/*.php') ?: [], glob($root . '/*/*.php') ?: []);
        sort($files);

        return $files;
    }

    public function testNoControllerTrimsARequestFieldDirectly(): void
    {
        $offenders = $this->linesMatching('/trim\(\$data\[/');

        $this->assertSame(
            [],
            $offenders,
            "Diese Stellen geben ein Formularfeld ungeprüft an trim() weiter. Ein Feld-Array\n"
                . "ergibt dort einen TypeError. Nutze InputValidator::asString():\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * Die mildere Schwester des Falls oben: `(string) $data['x']` wirft keinen
     * TypeError, liefert aus einem Array aber die Zeichenkette "Array" - ein Wert,
     * den nie jemand eingegeben hat und der jede Längen- und Leerprüfung dahinter
     * besteht. An ein paar Stellen wurde er dadurch gespeichert statt abgewiesen.
     *
     * Die Fassung aus Lauf 35 suchte wörtlich nach `(string) ($data[`. Sie übersah
     * damit drei Schreibweisen desselben Fehlers und ließ 33 Stellen stehen:
     * `(string)($data[` ohne Leerzeichen (ProfileController), `(string) $data[`
     * ohne Klammer (SponsorshipController, TaskController) und jeden anderen
     * Variablennamen - `$notes`, `$body`, `$postedGroups`. Welche Variablen die
     * Anfrage tragen, wird deshalb nicht mehr aufgezählt, sondern je Datei an ihrer
     * Zuweisung erkannt: Was aus `getParsedBody()` oder `getQueryParams()` kommt
     * und was daraus weitergegeben wird, zählt dazu. Ein neuer Name fällt damit
     * von selbst unter die Regel.
     */
    public function testNoControllerCastsARequestFieldToStringByHand(): void
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $path) {
            $code = (string) file_get_contents($path);
            $patterns = array_map(
                static fn (string $variable): string => '/\(string\)\s*\(?\s*\$' . $variable . '\s*\[/',
                self::requestVariables($code)
            );
            // Ohne Zwischenvariable gelesen: `(string) ($request->getQueryParams()['q'] ?? '')`.
            $patterns[] = '/\(string\)\s*\(?\s*\$\w+->get(?:ParsedBody|QueryParams)\(\)\s*\[/';

            foreach ($patterns as $pattern) {
                $offenders = array_merge($offenders, $this->linesOf($path, $pattern));
            }
        }

        sort($offenders);
        $offenders = array_values(array_unique($offenders));

        $this->assertSame(
            [],
            $offenders,
            "Diese Stellen wandeln ein Formularfeld mit (string) um. Aus einem Feld-Array\n"
                . "wird dabei \"Array\". Nutze InputValidator::asString():\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * Dieselbe Umwandlung, eine Ebene tiefer: Die Formularwerte dieses Projekts
     * laufen durch kleine Normalisierer der Controller, und die nehmen den Wert als
     * `mixed` oder ganz ohne Typangabe entgegen - `parseSortOrder()`,
     * `normalizeOptionalText()`, `normalizeRecurrenceInterval()`. Ein `(string)`
     * dort trifft denselben Array-Fall wie am Lesepunkt, nur unsichtbarer.
     *
     * Ausgenommen ist ein Guss, dessen Zeile den Marker `input:checked` trägt - in
     * der Bauart des `naming:ascii` beim Umlaut-Hook. Er sagt: Der Typ ist auf dem
     * Weg hierher ausdrücklich geprüft, ein Feld-Array kommt hier nicht an. Zwei
     * Stellen brauchen ihn, beide mit demselben Grund: Ein Provider schickt seine
     * Zahlen als Zahlen, und `asString()` würde sie verwerfen statt sie zu lesen
     * (Concerns\MailDeliveryIngest prüft mit `is_scalar()`,
     * SheetArchiveController::optionalText() mit `is_int()` und `is_float()`).
     */
    public function testNoControllerCastsAnUntypedParameterToString(): void
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $path) {
            $code = (string) file_get_contents($path);

            foreach (self::looselyTypedParameters($code) as $variable) {
                $offenders = array_merge($offenders, $this->linesOf(
                    $path,
                    '/\(string\)\s*\(?\s*\$' . $variable . '\b/',
                    static fn (string $line): bool => !str_contains($line, 'input:checked')
                ));
            }
        }

        sort($offenders);
        $offenders = array_values(array_unique($offenders));

        $this->assertSame(
            [],
            $offenders,
            "Diese Normalisierer wandeln ihren Wert mit (string) um, obwohl er als mixed\n"
                . "oder ohne Typangabe hereinkommt - aus einem Feld-Array wird \"Array\".\n"
                . "Nutze InputValidator::asString(), oder prüfe den Typ und setze den Marker\n"
                . "input:checked in dieselbe Zeile:\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * Die dritte Gestalt desselben Fehlers: Das Feld wird gar nicht angefasst,
     * sondern roh in eine Variable gelegt - und erst ein paar Zeilen weiter an
     * etwas mit Typangabe weitergegeben.
     *
     * Gefunden im Review-Lauf 35 an fünf Stellen. Die härteste war
     * `ProfileController::updatePassword()`: `$newPassword = $data['new_password'] ?? ''`
     * lief über drei Prüfungen hinweg bis in
     * `PasswordPolicyService::validate(string)`. Mit `new_password[]=x` warf das
     * dort einen TypeError - eine 500-Seite, die jedes angemeldete Mitglied
     * auslösen konnte. `ProjectController` gab `start_date` so an den
     * Eloquent-Cast `date` weiter, wo schon ein Tippfehler im Datum als
     * `InvalidFormatException` endete.
     *
     * Erkannt wird nur die Vorgabe `?? ''` und `?? null`: Sie sagt, dass die
     * Stelle einen einzelnen Textwert erwartet. `?? []` bleibt zulässig - dort
     * wird ein Array wirklich erwartet (Rollen, Stimmgruppen, Zielgruppen-Zeilen)
     * und die Auswertung filtert es selbst.
     */
    public function testNoControllerAssignsARequestFieldUnchecked(): void
    {
        $offenders = $this->linesMatching(
            '/=\s*\$(?:data|queryParams|params)\[[^\]]+\]\s*\?\?\s*(?:\'\'|""|null)\s*;/'
        );

        $this->assertSame(
            [],
            $offenders,
            "Diese Stellen legen ein Formularfeld ungeprüft in eine Variable. Ein Feld-Array\n"
                . "läuft von dort in die erste Funktion mit Typangabe. Nutze InputValidator::asString():\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * Die Variablen einer Datei, die Formular- oder Abfragewerte tragen.
     *
     * Erst die unmittelbaren (`$data = (array) $request->getParsedBody()`), dann
     * alles, was aus einer solchen weitergegeben wird (`$notes = (array) ($data['note'] ?? [])`)
     * - bis sich nichts mehr ändert. `getServerParams()` gehört nicht dazu: Dort
     * stehen die Kopfzeilen des Servers, immer als Text, nie als Feld-Array.
     *
     * @return list<string>
     */
    private static function requestVariables(string $code): array
    {
        $names = [];

        preg_match_all('/\$(\w+)\s*=[^;]*->get(?:ParsedBody|QueryParams)\(\)/', $code, $matches);
        foreach ($matches[1] as $name) {
            $names[$name] = true;
        }

        do {
            $before = count($names);
            foreach (array_keys($names) as $known) {
                preg_match_all('/\$(\w+)\s*=[^;]*\$' . $known . '\s*\[/', $code, $derived);
                foreach ($derived[1] as $name) {
                    $names[$name] = true;
                }
            }
        } while (count($names) > $before);

        return array_keys($names);
    }

    /**
     * Die Parameter einer Datei, die alles annehmen: `mixed $value` und solche
     * ganz ohne Typangabe. Genau dort kann ein Feld-Array ankommen.
     *
     * @return list<string>
     */
    private static function looselyTypedParameters(string $code): array
    {
        $names = [];

        preg_match_all('/function\s+\w+\s*\(([^)]*)\)/s', $code, $signatures);
        foreach ($signatures[1] as $parameterList) {
            foreach (explode(',', $parameterList) as $parameter) {
                if (preg_match('/^\s*(?:mixed\s+)?\$(\w+)/', $parameter, $found) === 1) {
                    $names[$found[1]] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @param ?callable(string): bool $onlyWhen
     * @return list<string>
     */
    private function linesOf(string $path, string $pattern, ?callable $onlyWhen = null): array
    {
        $offenders = [];

        foreach (file($path) ?: [] as $number => $line) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }

            if ($onlyWhen !== null && !$onlyWhen($line)) {
                continue;
            }

            $offenders[] = basename($path) . ':' . ($number + 1) . ' ' . trim($line);
        }

        return $offenders;
    }

    /**
     * @return list<string>
     */
    private function linesMatching(string $pattern): array
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $path) {
            $offenders = array_merge($offenders, $this->linesOf($path, $pattern));
        }

        return $offenders;
    }

    /**
     * Gegenprobe: Ohne sie bliebe der Wächter auch dann grün, wenn er nach einer
     * Umbenennung des Verzeichnisses gar keine Datei mehr fände.
     */
    public function testTheGuardScansTheControllers(): void
    {
        $files = $this->controllerFiles();
        $this->assertGreaterThan(30, count($files));

        // Die Unterordner sind dabei - sonst wäre die Erweiterung oben wirkungslos.
        $names = array_map('basename', $files);
        $this->assertContains('FileControllerSupport.php', $names);
        $this->assertContains('TokenController.php', $names);
    }

    public function testTheGuardRecognisesTheOldPatterns(): void
    {
        $this->assertSame(
            1,
            preg_match('/trim\(\$data\[/', "        \$name = trim(\$data['name'] ?? '');")
        );

        $uncheckedPattern = '/=\s*\$(?:data|queryParams|params)\[[^\]]+\]\s*\?\?\s*(?:\'\'|""|null)\s*;/';
        $this->assertSame(
            1,
            preg_match($uncheckedPattern, "        \$newPassword = \$data['new_password'] ?? '';")
        );
        $this->assertSame(
            1,
            preg_match($uncheckedPattern, "        \$raw = \$data[\$key] ?? null;")
        );

        // Ein erwartetes Array darf der Wächter nicht melden, sonst stünde er
        // jeder Mehrfachauswahl im Weg.
        $this->assertSame(
            0,
            preg_match($uncheckedPattern, "        \$roleIds = \$data['roles'] ?? [];")
        );
        $this->assertSame(
            0,
            preg_match($uncheckedPattern, "        \$name = InputValidator::asString(\$data['name'] ?? null);")
        );
    }

    /**
     * Die vier Schreibweisen, die der Wächter aus Lauf 35 übersah, und die
     * Erkennung der Variablen, die die Anfrage tragen.
     */
    public function testTheGuardRecognisesEveryCastSpelling(): void
    {
        $code = <<<'PHP'
            $data = (array) $request->getParsedBody();
            $notes = (array) ($data['note'] ?? []);
        PHP;

        $variables = self::requestVariables($code);
        $this->assertContains('data', $variables);
        $this->assertContains('notes', $variables, 'Was aus $data kommt, trägt die Anfrage weiter.');

        $patterns = array_map(
            static fn (string $variable): string => '/\(string\)\s*\(?\s*\$' . $variable . '\s*\[/',
            $variables
        );

        $spellings = [
            "        \$host = trim((string)(\$data['imap_host'] ?? ''));",
            "        \$type = (string) \$data['type'];",
            "        \$note = trim((string) (\$notes[\$userId] ?? ''));",
            "        \$date = !empty(\$data['start_date']) ? (string) \$data['start_date'] : null;",
        ];

        foreach ($spellings as $line) {
            $matched = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue($matched, 'Nicht erkannt: ' . trim($line));
        }

        // Die richtige Fassung darf nicht melden.
        foreach ($patterns as $pattern) {
            $this->assertSame(
                0,
                preg_match($pattern, "        \$host = trim(InputValidator::asString(\$data['imap_host'] ?? null));")
            );
        }

        // Ein Routen-Argument ist keine Formulareingabe: Slim füllt es aus dem Pfad,
        // und das Routen-Muster lässt nur Text durch. Es trägt die Anfrage nicht im
        // Sinne dieser Regel und darf nicht gemeldet werden.
        foreach ($patterns as $pattern) {
            $this->assertSame(0, preg_match($pattern, "        \$id = (string) \$args['id'];"));
        }
    }

    public function testTheGuardRecognisesALooselyTypedParameter(): void
    {
        $code = <<<'PHP'
            private function normalizeNote(mixed $value): ?string
            private static function field($body, string $key): string
            private function render(string $template, int $count): string
        PHP;

        $parameters = self::looselyTypedParameters($code);
        $this->assertContains('value', $parameters);
        $this->assertContains('body', $parameters);
        $this->assertNotContains('template', $parameters, 'Ein getypter Parameter steht nicht unter der Regel.');
        $this->assertNotContains('count', $parameters);
    }
}
