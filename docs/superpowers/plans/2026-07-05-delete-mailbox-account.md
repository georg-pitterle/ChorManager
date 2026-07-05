# Mailbox-Zugang entfernen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Button im Profil löscht den gespeicherten Mailbox-Zugang (IMAP/SMTP-Zugangsdaten + externe Webmail-URL) vollständig.

**Architecture:** Neue `ProfileController::deleteMailbox()`-Methode löscht die `UserMailAccount`-Zeile des Users und redirectet mit Session-Flash — synchron, kein JSON-Zweig, analog zu anderen Lösch-Aktionen im Projekt. Ein eigenes, separates `<form>` im Template (außerhalb des async Save/Test-Formulars) nutzt das bereits bestehende globale `data-confirm`-Bestätigungsmuster aus `public/js/common.js`.

**Tech Stack:** PHP 8.5 (Slim 4, Eloquent), Twig, PHPUnit 10, DDEV.

**Spec:** `docs/superpowers/specs/2026-07-05-delete-mailbox-account-design.md`

## Global Constraints

- Alle Kommandos via DDEV: `ddev exec ./vendor/bin/phpunit ...`, `ddev composer phpcs`, `ddev composer twigcs`.
- Kein `git push` — nur lokale Commits.
- Neue/geänderte Textdateien mit LF-Zeilenenden. Nach jedem Schreiben auf Windows normalisieren:
  `$f = "<absoluter-pfad>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
- Deutsche Texte mit echten Umlauten (ä/ö/ü/ß), niemals ae/oe/ue/ss.
- PHP: PSR-12, 4 Spaces, Zeilenlimit soft 120 / hard 130.
- Twig: doppelte Anführungszeichen, kein Inline-JS/CSS, Operatoren mit genau 1 Leerzeichen.
- Löscht die komplette `user_mail_accounts`-Zeile (IMAP/SMTP + `external_webmail_url` zusammen) — kein Teil-Löschen.
- Kein neues JS — Bestätigung läuft über `public/js/common.js`s bestehenden `data-confirm`-Mechanismus (Capture-Phase-`submit`-Listener, siehe `public/js/common.js:23-35`).
- Kein Schema-Change, keine Migration.

---

### Task 1: Delete-Route, Controller-Methode, Template-Button

**Files:**
- Modify: `src/Routes.php:108` (neue Route)
- Modify: `src/Controllers/ProfileController.php:312` (neue Methode zwischen `updateMailbox()` und `testMailboxConnection()`)
- Modify: `templates/profile/index.twig:324` (neues Formular nach dem schließenden `</form>` des Mailbox-Formulars)
- Test: `tests/Feature/ProfileMailboxFeatureTest.php` (erweitern)

**Interfaces:**
- Consumes: `App\Models\UserMailAccount` (bestehend, `where('user_id', ...)->first()`, `->delete()`).
- Produces: Route `POST /profile/mailbox/delete` → `ProfileController::deleteMailbox()`. Keine weiteren Konsumenten (Blattknoten dieses Features).

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Feature/ProfileMailboxFeatureTest.php` ergänzen (nach der letzten bestehenden Testmethode, vor der schließenden `}` der Klasse):

```php
    public function testDeleteMailboxRemovesAccountAndSetsSuccessMessage(): void
    {
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password_enc' => $this->crypto->encrypt('whatever-password'),
            'imap_enabled' => true,
            'external_webmail_url' => 'https://webmail.example.org/',
        ]);

        $request = $this->makeRequest('POST', '/profile/mailbox/delete');
        $response = $this->controller->deleteMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertSame('Mailbox-Zugang wurde entfernt.', $_SESSION['success'] ?? null);
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }

    public function testDeleteMailboxWithoutExistingAccountIsANoOp(): void
    {
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());

        $request = $this->makeRequest('POST', '/profile/mailbox/delete');
        $response = $this->controller->deleteMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertArrayNotHasKey('success', $_SESSION);
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testIndexReflectsEmptyStateAfterMailboxDeletion(): void
    {
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password_enc' => $this->crypto->encrypt('whatever-password'),
            'imap_enabled' => true,
        ]);

        $this->controller->deleteMailbox($this->makeRequest('POST', '/profile/mailbox/delete'), $this->makeResponse());
        unset($_SESSION['success'], $_SESSION['error']);

        $capturedData = null;
        $this->twigMock->expects($this->once())
            ->method('render')
            ->willReturnCallback(function ($response, $template, $data) use (&$capturedData) {
                $capturedData = $data;
                return $response;
            });

        $this->controller->index($this->makeRequest('GET', '/profile'), $this->makeResponse());

        $this->assertIsArray($capturedData);
        $this->assertFalse($capturedData['has_saved_account']);
        $this->assertFalse($capturedData['webmail_available']);
        $this->assertNull($capturedData['mail_account']);
    }
```

- [ ] **Step 2: Tests ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileMailboxFeatureTest.php --filter testDeleteMailbox`
Expected: FAIL (Methode `deleteMailbox` existiert noch nicht — Fatal error: Call to undefined method)

- [ ] **Step 3: Route ergänzen**

In `src/Routes.php` die Zeile

```php
            $group->post('/profile/mailbox/test', [ProfileController::class, 'testMailboxConnection']);
```

danach ergänzen um:

```php
            $group->post('/profile/mailbox/delete', [ProfileController::class, 'deleteMailbox']);
```

- [ ] **Step 4: Controller-Methode implementieren**

In `src/Controllers/ProfileController.php` nach dem Ende von `updateMailbox()` (nach der schließenden `}` bei Zeile 312, vor `public function testMailboxConnection`) einfügen:

```php
    public function deleteMailbox(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];

        $account = UserMailAccount::where('user_id', $userId)->first();
        if ($account) {
            $account->delete();
            $_SESSION['success'] = 'Mailbox-Zugang wurde entfernt.';
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }

```

- [ ] **Step 5: Tests ausführen, Erfolg verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileMailboxFeatureTest.php`
Expected: PASS (alle Tests inkl. der 3 neuen und aller bestehenden)

- [ ] **Step 6: Template-Button ergänzen**

In `templates/profile/index.twig` nach dem schließenden `</form>` des Mailbox-Formulars (Zeile 324) und vor dem `{% if settings.modules.webmail and webmail_available %}`-Block (Zeile 326) einfügen:

```twig

                    {% if has_saved_account %}
                        <form action="/profile/mailbox/delete"
                              method="post"
                              class="mt-3"
                              data-confirm="Mailbox-Zugang wirklich entfernen? Diese Aktion kann nicht rückgängig gemacht werden.">
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-trash"></i> Zugang entfernen
                                </button>
                            </div>
                        </form>
                    {% endif %}
```

- [ ] **Step 7: Template-Test schreiben und ausführen**

In `tests/Feature/WebmailFeatureFlagTest.php` ergänzen (nach der letzten bestehenden Testmethode, vor der schließenden `}` der Klasse):

```php
    public function testProfileTemplateHasDeleteMailboxForm(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/profile/index.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString('{% if has_saved_account %}', $template);
        $this->assertStringContainsString('action="/profile/mailbox/delete"', $template);
        $this->assertStringContainsString('data-confirm="Mailbox-Zugang wirklich entfernen?', $template);
    }
```

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/WebmailFeatureFlagTest.php`
Expected: PASS

- [ ] **Step 8: phpcs + twigcs**

Run: `ddev composer phpcs`
Expected: keine Verstöße (sonst `ddev composer phpcbf` + erneut prüfen)

Run: `ddev composer twigcs`
Expected: keine Verstöße (sonst `ddev composer twigcbf` + erneut prüfen)

- [ ] **Step 9: Volle Testsuite**

Run: `ddev exec ./vendor/bin/phpunit`
Expected: alle Tests grün, keine Regression.

- [ ] **Step 10: Commit**

```bash
git add src/Routes.php src/Controllers/ProfileController.php templates/profile/index.twig tests/Feature/ProfileMailboxFeatureTest.php tests/Feature/WebmailFeatureFlagTest.php
git commit -m "feat(profile): add button to remove saved mailbox account"
```

---

### Task 2: Browser-Verifikation

**Files:** keine neuen; nur Prüflauf.

**Interfaces:**
- Consumes: Task 1.
- Produces: verifizierter Endzustand.

- [ ] **Step 1: Echter Browser-Lauf (Playwright)**

Mit dem seeded Test-Account `seed.052@chor.local` / Passwort `seed` einloggen (dieser Account hatte in einer früheren Verifikation noch keinen Mailbox-Account — falls inzwischen doch einer existiert, das ist für diesen Test sogar besser). Zu `/profile` navigieren.

1. Falls kein Mailbox-Zugang gespeichert ist: Host/Port/Verschlüsselung/Username/Passwort ausfüllen und "Mailbox speichern" klicken, damit ein Account existiert.
2. Nach Reload: prüfen, dass der Button "Zugang entfernen" jetzt sichtbar ist.
3. Klicken — Bestätigungsdialog muss erscheinen (Browser-`confirm()`). Dialog abbrechen (Cancel) — prüfen: Seite bleibt, Account weiterhin vorhanden (z. B. Formular weiterhin ausgefüllt nach Reload).
4. Erneut klicken, diesmal bestätigen — prüfen: Seite lädt neu, Erfolgsmeldung "Mailbox-Zugang wurde entfernt." erscheint, Formular ist jetzt komplett leer, "Zugang entfernen"-Button ist verschwunden, Mail-Badge (falls vorher sichtbar) ist verschwunden.

Ergebnis berichten.

- [ ] **Step 2: Abschlussbericht**

Zusammenfassen: Testergebnisse, Browser-Verifikationsergebnis.
