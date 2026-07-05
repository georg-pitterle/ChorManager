# Async Mailbox-Formular Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "Verbindung testen" und "Mailbox speichern" im Profil laufen asynchron (fetch/JSON), sodass der Browser eingegebene Werte (insbesondere das nie zurückgespielte Passwort) im DOM behält statt sie bei einem Seiten-Reload zu verlieren.

**Architecture:** Content-Negotiation in `ProfileController`: beide Methoden (`testMailboxConnection`, `updateMailbox`) bekommen einen zusätzlichen JSON-Antwortzweig, der nur greift wenn `Accept: application/json` gesendet wird. Der bestehende Redirect+Session-Flash-Pfad bleibt für Nicht-JS-Clients unverändert. Ein neues `public/js/profile-mailbox.js` fängt beide Buttons per `submit`-Event ab und sendet die Anfrage per `fetch`.

**Tech Stack:** PHP 8.5 (Slim 4), Twig, Vanilla JS (kein Framework, Projekt-Konvention), PHPUnit 10, DDEV.

**Spec:** `docs/superpowers/specs/2026-07-05-async-mailbox-form-design.md`

## Global Constraints

- Alle Kommandos via DDEV: `ddev exec ./vendor/bin/phpunit ...`, `ddev composer phpcs`, `ddev composer twigcs`.
- Kein `git push` — nur lokale Commits.
- Neue/geänderte Textdateien mit LF-Zeilenenden. Nach jedem Schreiben auf Windows normalisieren:
  `$f = "<absoluter-pfad>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))`
- Deutsche Texte mit echten Umlauten (ä/ö/ü/ß), niemals ae/oe/ue/ss.
- PHP: PSR-12, 4 Spaces, Zeilenlimit soft 120 / hard 130.
- Twig: doppelte Anführungszeichen, kein Inline-JS/CSS, Operatoren mit genau 1 Leerzeichen.
- Kein neues CSRF-Handling nötig: `HtmlFormCsrfInjectorMiddleware` injiziert das `_csrf`-Hidden-Field bereits serverseitig; `new FormData(form)` liest es automatisch mit.
- Bestehende 13 Tests in `tests/Feature/ProfileMailboxFeatureTest.php` müssen unverändert grün bleiben (kein `Accept`-Header → alter Pfad).
- Statuscodes: Validierungsfehler → 422, DB-Exception → 500, sonst 200.

---

### Task 1: JSON-Zweig für `testMailboxConnection()`

**Files:**
- Modify: `src/Controllers/ProfileController.php:282-349`
- Test: `tests/Feature/ProfileMailboxFeatureTest.php` (erweitern)

**Interfaces:**
- Produces: `private function wantsJsonResponse(Request $request): bool` — von Task 2 wiederverwendet.
- Response-Contract (neu, JSON-Zweig): `200 {"success":true,"message":"Verbindung erfolgreich."}` bei Erfolg; `200 {"success":false,"message":"..."}` bei SSRF-Block oder Socket-Fehler; `422 {"success":false,"message":"..."}` bei Validierungsfehler (Host/Port/Verschlüsselung).

**Hinweis zur Testabdeckung:** der Erfolgsfall (echter Socket-Connect zu einem
IMAP-Server, `Verbindung erfolgreich.`) hat bewusst keinen neuen Test — die
bestehenden 13 Tests in `ProfileMailboxFeatureTest.php` decken diesen Zweig
ebenfalls nicht ab, da er einen echten erreichbaren IMAP-Server braucht und
`stream_socket_client()` in `testMailboxConnection()` nicht hinter einem
Interface liegt. Kein Regressionsrisiko: der Erfolgszweig unterscheidet sich
vom bereits getesteten Fehlerzweig nur im if/else auf denselben Response-Bau
(`jsonResponse(..., true, 200)` vs. `jsonResponse(..., false, 200)`), beide
Pfade sind in Step 3 identisch strukturiert.

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Feature/ProfileMailboxFeatureTest.php` ergänzen (nach der letzten bestehenden Testmethode, vor der schließenden `}` der Klasse):

```php
    public function testConnectionTestWithJsonAcceptReturnsJsonOnValidationError(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/profile/mailbox/test',
            ['imap_host' => '', 'imap_port' => '993', 'imap_encryption' => 'ssl'],
            [],
            ['Accept' => 'application/json']
        );

        $response = $this->controller->testMailboxConnection($request, $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertNotEmpty($body['message']);
        $this->assertArrayNotHasKey('mailbox_form_old', $_SESSION);
    }

    public function testConnectionTestWithJsonAcceptReturnsJsonOnBlockedHost(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/profile/mailbox/test',
            ['imap_host' => '127.0.0.1', 'imap_port' => '993', 'imap_encryption' => 'ssl'],
            [],
            ['Accept' => 'application/json']
        );

        $response = $this->controller->testMailboxConnection($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('Verbindung fehlgeschlagen: Host ist nicht erreichbar.', $body['message']);
        $this->assertArrayNotHasKey('mailbox_form_old', $_SESSION);
        $this->assertArrayNotHasKey('error', $_SESSION);
    }
```

- [ ] **Step 2: Tests ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileMailboxFeatureTest.php --filter testConnectionTestWithJsonAccept`
Expected: FAIL (beide neuen Tests — aktuell liefert die Methode immer `302` Redirect statt JSON)

- [ ] **Step 3: `wantsJsonResponse()` + JSON-Zweig implementieren**

In `src/Controllers/ProfileController.php` nach der Methode `mailboxViewFromOldInput()` (nach Zeile 103, vor `public function updateProfile`) neue private Methode einfügen:

```php
    /**
     * True when the client explicitly asked for a JSON response (the async
     * mailbox form's fetch() calls always send this). Non-JS/legacy form
     * submissions never send it, so they keep using the redirect+session-flash
     * path unchanged.
     */
    private function wantsJsonResponse(Request $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
```

Dann `testMailboxConnection()` (Zeile 282-349) ersetzen durch:

```php
    public function testMailboxConnection(Request $request, Response $response): Response
    {
        $wantsJson = $this->wantsJsonResponse($request);
        $data = (array)$request->getParsedBody();
        if (!$wantsJson) {
            $_SESSION['mailbox_form_old'] = array_diff_key($data, ['imap_password' => true]);
        }

        $imapHost = trim((string)($data['imap_host'] ?? ''));
        $imapPortRaw = trim((string)($data['imap_port'] ?? ''));
        $imapEncryption = trim((string)($data['imap_encryption'] ?? ''));

        $error = $this->validateMailboxConnectionFields($imapHost, $imapPortRaw, $imapEncryption);
        if ($error !== null) {
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $error], 422);
            }
            $_SESSION['error'] = $error;
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        try {
            $validatedIp = OutboundConnectionGuard::resolvePublicIp($imapHost);
        } catch (BlockedHostException $e) {
            $this->logger->warning(
                'Mailbox connection test blocked: host did not resolve to a public address.',
                [
                    'event' => 'mailbox.test.host_blocked',
                    'user_id' => (int)($_SESSION['user_id'] ?? 0),
                ]
            );
            $message = 'Verbindung fehlgeschlagen: Host ist nicht erreichbar.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 200);
            }
            $_SESSION['error'] = $message;
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        $imapPort = (int)$imapPortRaw;
        $scheme = $imapEncryption === 'ssl' ? 'ssl' : 'tcp';
        // Connect to the validated IP (pinned), but keep TLS peer verification
        // bound to the original hostname so a rebind cannot redirect us.
        $ipForUrl = str_contains($validatedIp, ':') ? '[' . $validatedIp . ']' : $validatedIp;
        $remote = $scheme . '://' . $ipForUrl . ':' . $imapPort;

        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $imapHost,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            // Deliberately generic: do not echo $errstr, which would leak an
            // open/closed/filtered oracle for the targeted host:port.
            $message = 'Verbindung fehlgeschlagen: Host ist nicht erreichbar.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 200);
            }
            $_SESSION['error'] = $message;
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        stream_set_timeout($socket, 5);
        $greeting = fgets($socket, 512);
        fclose($socket);

        if ($greeting !== false && str_starts_with($greeting, '* ')) {
            $message = 'Verbindung erfolgreich.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => true, 'message' => $message], 200);
            }
            $_SESSION['success'] = $message;
        } else {
            $message = 'Verbindung fehlgeschlagen: keine gültige IMAP-Antwort erhalten.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 200);
            }
            $_SESSION['error'] = $message;
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }
```

- [ ] **Step 4: Tests ausführen, Erfolg verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileMailboxFeatureTest.php`
Expected: PASS (alle Tests inkl. der 2 neuen und der 13 bestehenden — bestehende senden keinen `Accept`-Header, nehmen also weiterhin den Redirect-Pfad)

- [ ] **Step 5: phpcs**

Run: `ddev composer phpcs`
Expected: keine Verstöße (sonst `ddev composer phpcbf` + erneut prüfen)

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/ProfileController.php tests/Feature/ProfileMailboxFeatureTest.php
git commit -m "feat(profile): add JSON response branch to mailbox connection test"
```

---

### Task 2: JSON-Zweig für `updateMailbox()`

**Files:**
- Modify: `src/Controllers/ProfileController.php:191-280`
- Test: `tests/Feature/ProfileMailboxFeatureTest.php` (erweitern)

**Interfaces:**
- Consumes: `wantsJsonResponse(Request): bool`, `jsonResponse(Response, array, int): Response` (Task 1).
- Response-Contract (neu, JSON-Zweig): `200 {"success":true,"message":"Mailbox-Einstellungen wurden gespeichert."}` bei Erfolg; `422 {"success":false,"message":"..."}` bei Validierungsfehler; `500 {"success":false,"message":"Fehler beim Speichern der Mailbox-Einstellungen."}` bei DB-Exception.

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Feature/ProfileMailboxFeatureTest.php` ergänzen:

```php
    public function testUpdateMailboxWithJsonAcceptReturnsJsonOnSuccess(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/profile/mailbox',
            [
                'imap_host' => 'imap.example.org',
                'imap_port' => '993',
                'imap_encryption' => 'ssl',
                'imap_username' => 'mailbox.tester@example.org',
                'imap_password' => 'S3cr3t-Imap-Pass',
                'imap_enabled' => '1',
            ],
            [],
            ['Accept' => 'application/json']
        );

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertSame('Mailbox-Einstellungen wurden gespeichert.', $body['message']);

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('imap.example.org', $account->imap_host);
    }

    public function testUpdateMailboxWithJsonAcceptReturnsJsonOnValidationError(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/profile/mailbox',
            [
                'imap_host' => '',
                'imap_port' => '993',
                'imap_encryption' => 'ssl',
                'imap_username' => 'mailbox.tester@example.org',
                'imap_password' => 'whatever-password',
            ],
            [],
            ['Accept' => 'application/json']
        );

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertNotEmpty($body['message']);
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }
```

- [ ] **Step 2: Tests ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileMailboxFeatureTest.php --filter testUpdateMailboxWithJsonAccept`
Expected: FAIL (beide neuen Tests — aktuell immer `302` Redirect)

- [ ] **Step 3: JSON-Zweig implementieren**

In `src/Controllers/ProfileController.php` die Methode `updateMailbox()` (Zeile 191-280) ersetzen durch:

```php
    public function updateMailbox(Request $request, Response $response): Response
    {
        $wantsJson = $this->wantsJsonResponse($request);
        $userId = (int)$_SESSION['user_id'];
        $data = (array)$request->getParsedBody();

        $imapHost = trim((string)($data['imap_host'] ?? ''));
        $imapPortRaw = trim((string)($data['imap_port'] ?? ''));
        $imapEncryption = trim((string)($data['imap_encryption'] ?? ''));
        $imapUsername = trim((string)($data['imap_username'] ?? ''));
        $imapPassword = (string)($data['imap_password'] ?? '');
        $smtpHost = trim((string)($data['smtp_host'] ?? ''));
        $smtpPortRaw = trim((string)($data['smtp_port'] ?? ''));
        $smtpEncryption = trim((string)($data['smtp_encryption'] ?? ''));
        $hasExternalWebmailUrl = array_key_exists('external_webmail_url', $data);
        $externalWebmailUrl = $hasExternalWebmailUrl ? trim((string)$data['external_webmail_url']) : '';

        $error = $this->validateMailboxConnectionFields($imapHost, $imapPortRaw, $imapEncryption);
        if ($error === null && ($imapUsername === '' || strlen($imapUsername) > 255)) {
            $error = 'Bitte gib einen gültigen Benutzernamen an (max. 255 Zeichen).';
        }

        if ($error === null && self::containsControlChars($imapUsername)) {
            $error = 'Der Benutzername darf keine Steuerzeichen enthalten.';
        }

        if ($error === null && $imapPassword !== '' && self::containsControlChars($imapPassword)) {
            $error = 'Das Passwort darf keine Steuerzeichen enthalten.';
        }

        if ($error === null && $externalWebmailUrl !== '') {
            $error = self::validateExternalWebmailUrl($externalWebmailUrl);
        }

        $existingAccount = UserMailAccount::where('user_id', $userId)->first();

        if ($error === null && $imapPassword === '' && !$existingAccount) {
            $error = 'Bitte gib ein Passwort für den Mailbox-Zugang an.';
        }

        if ($error !== null) {
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $error], 422);
            }
            $_SESSION['error'] = $error;
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        $imapEnabled = $this->isCheckboxChecked($data, 'imap_enabled');
        $mailBadgeEnabled = $this->isCheckboxChecked($data, 'mail_badge_enabled');

        $smtpPort = ($smtpPortRaw !== '' && ctype_digit($smtpPortRaw)) ? (int)$smtpPortRaw : null;
        $validEncryptions = ['ssl', 'tls', 'none'];

        $attributes = [
            'imap_host' => $imapHost,
            'imap_port' => (int)$imapPortRaw,
            'imap_encryption' => $imapEncryption,
            'smtp_host' => $smtpHost !== '' ? $smtpHost : null,
            'smtp_port' => ($smtpHost !== '' && $smtpPort !== null && $smtpPort >= 1 && $smtpPort <= 65535)
                ? $smtpPort : null,
            'smtp_encryption' => ($smtpHost !== '' && in_array($smtpEncryption, $validEncryptions, true))
                ? $smtpEncryption : null,
            'imap_username' => $imapUsername,
            'imap_enabled' => $imapEnabled,
            'mail_badge_enabled' => $mailBadgeEnabled,
        ];

        if ($hasExternalWebmailUrl) {
            $attributes['external_webmail_url'] = $externalWebmailUrl !== '' ? $externalWebmailUrl : null;
        }

        if ($imapPassword !== '') {
            $attributes['imap_password_enc'] = $this->crypto->encrypt($imapPassword);
        }

        try {
            UserMailAccount::updateOrCreate(['user_id' => $userId], $attributes);

            $message = 'Mailbox-Einstellungen wurden gespeichert.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => true, 'message' => $message], 200);
            }
            $_SESSION['success'] = $message;
        } catch (\Exception $e) {
            $this->logger->error(
                'Mail account update failed.',
                [
                    'event' => 'mail_account.update.failed',
                    'user_id' => $userId,
                    'exception' => $e,
                ]
            );
            $message = 'Fehler beim Speichern der Mailbox-Einstellungen.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 500);
            }
            $_SESSION['error'] = $message;
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }
```

- [ ] **Step 4: Tests ausführen, Erfolg verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileMailboxFeatureTest.php`
Expected: PASS (alle Tests inkl. der 4 neuen JSON-Tests und der 13 bestehenden)

- [ ] **Step 5: phpcs**

Run: `ddev composer phpcs`
Expected: keine Verstöße

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/ProfileController.php tests/Feature/ProfileMailboxFeatureTest.php
git commit -m "feat(profile): add JSON response branch to mailbox save"
```

---

### Task 3: Template — IDs, Feedback-Container, Script-Include

**Files:**
- Modify: `templates/profile/index.twig:188` (Form-Tag), `:313-323` (Button-Block), Dateiende
- Test: `tests/Feature/WebmailFeatureFlagTest.php` oder neue Datei `tests/Feature/ProfileMailboxAsyncTemplateTest.php` (neu, einfacher)

**Interfaces:**
- Consumes: keine neuen PHP-Interfaces.
- Produces: DOM-IDs `#mailboxForm`, `#mailboxFormFeedback` — von Task 4s JS konsumiert.

- [ ] **Step 1: Failing Test schreiben**

Neue Datei `tests/Feature/ProfileMailboxAsyncTemplateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class ProfileMailboxAsyncTemplateTest extends TestCase
{
    public function testProfileTemplateHasAsyncMailboxFormWiring(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/profile/index.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString('id="mailboxForm"', $template);
        $this->assertStringContainsString('id="mailboxFormFeedback"', $template);
        $this->assertStringContainsString('<script src="/js/profile-mailbox.js"></script>', $template);
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileMailboxAsyncTemplateTest.php`
Expected: FAIL (Assertions schlagen fehl, IDs/Script fehlen noch)

- [ ] **Step 3: Template anpassen**

In `templates/profile/index.twig` Zeile 188:

```twig
                    <form action="/profile/mailbox" method="post" id="mailboxForm">
```

ersetzen für (bisher):

```twig
                    <form action="/profile/mailbox" method="post">
```

Direkt danach (neue Zeile, vor `<div class="mb-3">` mit dem Host-Feld) einfügen:

```twig
                        <div id="mailboxFormFeedback" class="alert d-none" role="alert"></div>
```

Den Button-Block (aktuell Zeile 313-322):

```twig
                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit"
                                    class="btn btn-outline-secondary"
                                    formaction="/profile/mailbox/test">
                                <i class="bi bi-plug"></i> Verbindung testen
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-envelope-check"></i> Mailbox speichern
                            </button>
                        </div>
```

unverändert lassen (Attribute `formaction`/`type="submit"` bleiben — die JS-Logik in Task 4 liest sie aus, statt sie zu ersetzen).

Am Ende der Datei (nach dem letzten `{% endblock content %}`, aktuell Zeile 320 in der Gesamtdatei) neuen Block anfügen:

```twig

{% block scripts %}
    <script src="/js/profile-mailbox.js"></script>
{% endblock scripts %}
```

- [ ] **Step 4: Test ausführen, Erfolg verifizieren**

Run: `ddev exec ./vendor/bin/phpunit tests/Feature/ProfileMailboxAsyncTemplateTest.php`
Expected: PASS

- [ ] **Step 5: twigcs**

Run: `ddev composer twigcs`
Expected: keine Verstöße (sonst `ddev composer twigcbf` + erneut prüfen)

- [ ] **Step 6: Commit**

```bash
git add templates/profile/index.twig tests/Feature/ProfileMailboxAsyncTemplateTest.php
git commit -m "feat(profile): wire mailbox form for async submission"
```

---

### Task 4: `public/js/profile-mailbox.js` — Fetch-basiertes Submit-Handling

**Files:**
- Create: `public/js/profile-mailbox.js`

**Interfaces:**
- Consumes: DOM `#mailboxForm`, `#mailboxFormFeedback` (Task 3); Endpunkte `POST /profile/mailbox`, `POST /profile/mailbox/test` mit JSON-Contract aus Task 1/2 (`{success: boolean, message: string}`).
- Produces: keine weiteren Konsumenten (Blattknoten des Features).

Dieses Skript hat keinen PHPUnit-Test (reines Browser-JS, kein PHP). Verifikation erfolgt in Task 5 per echtem Browser-Lauf.

- [ ] **Step 1: Datei schreiben**

`public/js/profile-mailbox.js`:

```javascript
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('mailboxForm');
    var feedback = document.getElementById('mailboxFormFeedback');

    if (!form || !feedback) {
        return;
    }

    function showFeedback(success, message) {
        feedback.classList.remove('d-none', 'alert-success', 'alert-danger');
        feedback.classList.add(success ? 'alert-success' : 'alert-danger');
        feedback.textContent = message;
    }

    function hideFeedback() {
        feedback.classList.add('d-none');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        hideFeedback();

        var submitter = e.submitter;
        var url = (submitter && submitter.getAttribute('formaction')) || form.action;
        var isTest = url.indexOf('/profile/mailbox/test') !== -1;

        var originalHtml = submitter ? submitter.innerHTML : '';
        if (submitter) {
            submitter.disabled = true;
            submitter.textContent = 'Wird gesendet…';
        }

        fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
            },
            body: new FormData(form)
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (_) {
                        return {
                            success: false,
                            message: 'Verbindung zum Server fehlgeschlagen. Bitte versuche es erneut.'
                        };
                    }
                });
            })
            .then(function (data) {
                if (data.success && !isTest) {
                    window.location.reload();
                    return;
                }

                showFeedback(!!data.success, data.message || 'Unbekannter Fehler.');
                if (submitter) {
                    submitter.disabled = false;
                    submitter.innerHTML = originalHtml;
                }
            })
            .catch(function () {
                showFeedback(false, 'Verbindung zum Server fehlgeschlagen. Bitte versuche es erneut.');
                if (submitter) {
                    submitter.disabled = false;
                    submitter.innerHTML = originalHtml;
                }
            });
    });
});
```

- [ ] **Step 2: LF-Zeilenenden sicherstellen**

Run (PowerShell):
```powershell
$f = "d:\Proggen\ChorManager\public\js\profile-mailbox.js"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
```

- [ ] **Step 3: Commit**

```bash
git add public/js/profile-mailbox.js
git commit -m "feat(profile): add async fetch handler for mailbox form"
```

---

### Task 5: Verifikation — volle Suite + echter Browser-Lauf

**Files:** keine neuen; nur Prüfläufe.

**Interfaces:**
- Consumes: alle vorherigen Tasks.
- Produces: verifizierter Endzustand.

- [ ] **Step 1: Volle PHP-Testsuite**

Run: `ddev exec ./vendor/bin/phpunit`
Expected: alle Tests grün (inkl. der neuen aus Task 1-3). Falls ein Test unerwartet fehlschlägt: untersuchen, nicht ignorieren.

- [ ] **Step 2: phpcs + twigcs final**

Run: `ddev composer phpcs`
Run: `ddev composer twigcs`
Expected: beide sauber.

- [ ] **Step 3: Browser-Verifikation (Playwright)**

Dev-Server/DDEV läuft bereits (`https://chormanager.ddev.site`). Mit einem eingeloggten Test-Account (Dev-Seed-Nutzer) zu `/profile` navigieren, im Mailbox-Formular:

1. Host/Port/Verschlüsselung/Username/Passwort ausfüllen (z. B. `imap.example.org` / `993` / `SSL` / beliebiger Username / beliebiges Passwort).
2. "Verbindung testen" klicken.
3. Prüfen: Seite lädt NICHT neu (URL bleibt `/profile`, keine Netzwerk-Navigation), Inline-Feedback erscheint in `#mailboxFormFeedback`, **Passwort-Feld enthält weiterhin den eingegebenen Wert**.
4. Ohne die Felder zu leeren, "Mailbox speichern" klicken.
5. Prüfen: bei Erfolg lädt die Seite neu und zeigt den "Gespeichert"-Hinweis; bei einem absichtlich provozierten Validierungsfehler (z. B. Host vorher auf leer setzen) bleibt die Seite stehen, Inline-Fehler erscheint, **alle Felder bleiben ausgefüllt**.

Ergebnis (Erfolg/Abweichung) berichten.

- [ ] **Step 4: Abschlussbericht**

Zusammenfassen: geänderte Dateien, Testergebnisse, Browser-Verifikationsergebnis.
