# Feature Tests Required

- Every new feature must include automated tests.
- Use TDD: write a failing test before writing implementation code.
- Feature work is not complete until tests covering the new behavior are added or updated.
- Tests should cover the primary success path and relevant edge cases or failure paths.
- Run the relevant automated tests before finishing and report the outcome.

## Datenbank eines Testlaufs

Die Suite läuft nicht gegen die Entwicklungsdatenbank. `tests/bootstrap.php` leitet den
Namen ab (`db` wird zu `db_test`), legt die Datenbank bei Bedarf an und migriert sie —
über `bin/prepare_test_database.php`, das bei jedem Lauf mitläuft und rund eine Sekunde
kostet. Eine neue Migration ist damit im nächsten Testlauf von selbst eingespielt.

Ein zweiter gleichzeitiger Lauf auf derselben Datenbank bricht ab (`Tests\Support\TestRunLock`).
Das ist Absicht: zwei Läufe auf einem Bestand überschreiben einander, und das sah bisher
aus wie ein sporadisch undichter Test.

Gegen eine andere Datenbank läuft die Suite nur mit `ALLOW_NON_TEST_DATABASE=1`.

## Parallel laufen lassen

```bash
ddev composer test            # sequenziell, mit dem Leck-Wächter am Ende
ddev composer test:parallel   # paratest, vier Prozesse
```

Jeder paratest-Prozess bekommt über `TEST_TOKEN` eine eigene Datenbank (`db_test_1` und
so weiter) und eine eigene Sperre. Weil die Reihenfolge der Test-Suites aus `phpunit.xml`
dann nur noch innerhalb eines Prozesses gilt, prüft zusätzlich jeder Prozess am Ende
selbst, ob er Zeilen hinterlassen hat.

Ein Test, der im parallelen Lauf rot wird und allein grün bleibt, hängt an der
Reihenfolge: er verlässt sich auf etwas, das eine andere Testklasse im selben Prozess
gesetzt hat — `$_SESSION`, eine Umgebungsvariable, eine Twig-Funktion. Das gehört in den
eigenen `setUp()`, nicht in die Hoffnung auf einen Vorgänger.

## Passwörter in Tests

Gehasht wird über `App\Util\PasswordHasher::hash()`, nie über `password_hash()` direkt.
Im Testlauf (`APP_ENV=test`) senkt der Hasher den bcrypt-Aufwand auf das Minimum; ein
Hash mit dem Standardaufwand kostet im Container rund 160 ms, und die Suite legt in
`setUp()` reihenweise Personen an. `tests/Unit/TestSuite/PasswordHashingGoesThroughTheHasherTest`
wacht darüber.
