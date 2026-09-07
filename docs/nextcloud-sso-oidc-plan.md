# Nextcloud-SSO: ChorManager wird OpenID-Connect-Provider

## Ausgangslage

Nextcloud soll sich gegen ChorManager anmelden, ChorManager bleibt datenführend für
Benutzer, Namen, E-Mail und Gruppen. Randbedingungen:

- Nextcloud ist Managed-Hosting: nur über HTTPS erreichbar, kein LDAP-Port. LDAP fällt damit aus.
- Admin-Zugang und offizieller App-Store vorhanden, nur keine selbstgeschriebenen Nextcloud-Apps.
  Damit ist die offizielle App **`user_oidc`** installierbar — das ist der ganze Nextcloud-Anteil.
- Gefordert ist **echtes SSO**: aus ChorManager per Klick nach Nextcloud ohne zweite Eingabe.
- Wenige bestehende Nextcloud-Konten müssen erhalten bleiben, Zuordnung von Hand.

Ergebnis: ChorManager bekommt einen eng geschnittenen OIDC-Provider (Authorization Code Flow
mit PKCE, RS256). `user_oidc` erwartet dafür Discovery-Dokument, JWKS, Authorize-, Token- und
Userinfo-Endpunkt; PKCE ist dort standardmäßig aktiv. Die Claims `name`, `email`, `groups`
werden von `user_oidc` ausgewertet und auf Nextcloud-Konten abgebildet.

Bestandslage in der App: Auth ist rein sessionbasiert, es gibt **kein** OAuth/OIDC/JWT/LDAP,
keine API, keine passenden Composer-Pakete. Einziges verwandtes Muster ist
`src/Services/WebmailSsoTokenService.php` (kurzlebiges Token an ein fremdes Runtime, Schlüssel
aus `.env`, fail-closed). Daran orientiert sich der Entwurf.

---

## Grundsatzentscheidung: schlanker Eigenbau statt OAuth-Server-Framework

Empfehlung: **keine neue OAuth-Abhängigkeit**, Signatur über `ext-openssl`.

Begründung:

- ChorManager **signiert nur**, es verifiziert nie fremde Tokens. Die gefährliche JWT-Klasse
  (`alg: none`, RS/HS-Verwechslung, Key-Confusion) entsteht ausschließlich beim Verifizieren und
  betrifft uns gar nicht. Genau dieselbe Asymmetrie hat `WebmailSsoTokenService` (nur `createToken`,
  kein `decode`) — dort mit der gleichen Begründung im Klassenkommentar.
- Ein RS256-JWT ist `base64url(header).base64url(payload).base64url(openssl_sign(..., OPENSSL_ALGO_SHA256))`.
  JWKS kommt aus `openssl_pkey_get_details()` (`rsa.n`, `rsa.e`, base64url-kodiert). Kein Eigenbau
  von Kryptografie.
- `league/oauth2-server` bräuchte zusätzlich `defuse/php-encryption` und `lcobucci/jwt`, sechs
  Repository-Interfaces, **und** einen selbstgeschriebenen OIDC-ResponseType für das `id_token`
  (`steverhoades/oauth2-openid-connect-server` ist nicht gepflegt). Der PHP-8.5-Support der Kette
  müsste erst geprüft werden. Der Sicherheitsgewinn wäre gering, weil wir nur **einen**
  vertrauenswürdigen Client, **nur** den Code-Flow und keinen Consent-Screen brauchen.

Nicht verhandelbar bleibt: PKCE-Pflicht (S256), exakter `redirect_uri`-Vergleich, einmalig
verwendbare Codes, 60-Sekunden-Code-TTL, `nonce` im `id_token`, Client-Secret nur gehasht.

Zu verifizieren vor Beginn: `ddev exec php -m | grep -i openssl` und
`ddev exec php -r 'var_dump(function_exists("openssl_pkey_new"));'`.

---

## Endpunkte

Neues Feature-Flag `FEATURE_OIDC` in `src/Settings.php` unter `modules` (Muster: `tasks`,
`registration`). Ist es aus, werden die Routen gar nicht registriert — wie beim Aufgaben-Feed in
`src/Routes.php:117`.

Alle Routen **öffentlich** außer `/oidc/authorize`. Registrierung im öffentlichen Block von
`src/Routes.php` (Z. 82-123):

| Route | Zweck | Auth |
|---|---|---|
| `GET /.well-known/openid-configuration` | Discovery, `issuer` = `APP_URL` | offen |
| `GET /oidc/jwks.json` | öffentliche Schlüssel mit `kid` | offen |
| `GET /oidc/authorize` | Code ausstellen | **hinter `AuthMiddleware`** |
| `POST /oidc/token` | Code → `id_token` + `access_token` | Client-Auth, CSRF-Ausnahme |
| `GET /oidc/userinfo` | Claims per Bearer | Access-Token |
| `GET /oidc/logout` | `end_session_endpoint` | offen |

`/oidc/authorize` gehört in die geschützte Gruppe (`src/Routes.php:127`), **ohne** RoleMiddleware.
Damit greift der bestehende Anmelde-Umweg geschenkt: `AuthMiddleware::redirectToLogin()`
(Z. 113-133) hängt `?redirect=` an, `AuthController::processLogin()` schickt danach zurück — die
volle Authorize-URL inklusive `state`, `nonce` und `code_challenge` überlebt den Login.
**Zu prüfen:** ob `App\Util\SafeRedirect` den Query-String der Rückkehr-URL unangetastet lässt;
falls er nur den Pfad durchlässt, muss das dort erweitert werden (nur eigener Host, kein offener
Redirect).

Kein Consent-Screen: der einzige Client ist die eigene Nextcloud-Instanz, als `is_trusted`
gekennzeichnet. Ein nicht vertrauenswürdiger Client wird schlicht abgelehnt.

`POST /oidc/token` muss in `CsrfMiddleware::EXEMPT_PATHS` (`src/Middleware/CsrfMiddleware.php:31-34`)
aufgenommen werden — samt Kommentar nach dem Vorbild dort: kein Browser, keine Sitzung, Ausweis ist
das Client-Secret plus `code_verifier`, ausgewertet bevor der Controller irgendetwas anfasst.

`SecurityHeadersMiddleware` setzt `Cache-Control: no-store` als Default (Z. 45-54). Für `/token`
und `/userinfo` ist das genau richtig und vom Standard gefordert; für Discovery und JWKS ist eine
Ausnahme sinnvoll (kurzes `max-age`, damit `user_oidc` die Schlüssel zwischenspeichern kann).

---

## Datenmodell

Eine Migration `db/migrations/<ts>_create_oidc_tables.php`. Phinx-Ketten mit `create()` abschließen
(`tests/Unit/Migrations/MigrationChainCompletionTest` prüft das statisch).

- `oidc_clients(id, client_id UNIQUE, client_secret_hash, name, redirect_uris TEXT,
  is_trusted TINYINT(1) DEFAULT 0, is_active TINYINT(1) DEFAULT 1, created_at, updated_at)`
  — `redirect_uris` als JSON-Liste, Vergleich immer exakt und vollständig, nie per Präfix.
- `oidc_auth_codes(id, code_hash CHAR(64) UNIQUE, client_id, user_id, redirect_uri, scope, nonce,
  code_challenge, code_challenge_method, expires_at, used_at NULL, created_at)`
- `oidc_access_tokens(id, token_hash CHAR(64) UNIQUE, client_id, user_id, scope, expires_at,
  revoked_at NULL, created_at)`
- `oidc_signing_keys(id, kid UNIQUE, public_key TEXT, private_key_encrypted TEXT, is_active, created_at)`

Zweite Migration, beides für die Claims (siehe unten):

- `users.external_uid VARCHAR(64) NULL UNIQUE` — die Nextcloud-Benutzer-ID
- `roles.external_group VARCHAR(64) NULL` — die Nextcloud-Gruppe, die dieser Rolle entspricht

Beide Spalten heißen bewusst `external_*` und nicht `nextcloud_*`: der Provider kann später weitere
Clients bedienen, die Bedeutung bleibt dieselbe.

Gehasht gespeichert wird nach dem Muster von `CalendarSubscriptionService` (SHA-256 des
Zufallstokens, Klartext existiert nur einmal in der Antwort). Client-Secrets über `password_hash`
wie Benutzerpasswörter, Vergleich mit `password_verify`.

Aufräumen abgelaufener Codes und Tokens analog `RememberLoginService::clearExpiredTokens()`,
aufgerufen im Token-Endpunkt (billiges, gelegentliches Löschen statt Cronjob).

TTLs: Auth-Code 60 s, Access-Token 5 min, `id_token` 5 min. **Kein Refresh-Token** in Ausbaustufe 1
— `user_oidc` kommt ohne aus, und weniger langlebige Artefakte heißt weniger Widerrufsproblem.

---

## Claims und Zuordnung zu Nextcloud

`sub` = `users.external_uid`, Fallback `'cm-' . $user->id`. In `user_oidc` wird
**„unique user id" abgeschaltet**, dann ist `sub` unverändert die Nextcloud-Benutzer-ID. Für die
wenigen Bestandskonten trägt man deren vorhandene Nextcloud-UID in `external_uid` ein, alle
weiteren Benutzer bekommen automatisch die stabile `cm-<id>`-Form.

| Claim | Quelle |
|---|---|
| `sub`, `preferred_username` | `external_uid` bzw. `cm-<id>` |
| `name` | `first_name . ' ' . last_name` |
| `email`, `email_verified` | `users.email`, `true` |
| `groups` | `roles.external_group` der Rollen des Benutzers, dedupliziert |

Nur Rollen mit **gesetzter** `external_group` landen im Claim. Eine Rolle ohne Zuordnung geht gar
nicht nach Nextcloud — kein Rückfall auf den Rollennamen. Das ist die vorhersagbare Variante: es
entstehen dort ausschließlich Gruppen, die vorher bewusst zugewiesen wurden, und ein Umbenennen
einer Rolle in ChorManager zerlegt keine Nextcloud-Freigaben.

Stimmgruppen gehen **nicht** hinaus, ebenso wenig die `can_*`-Flags aus `Role::PERMISSIONS` —
Nextcloud kennt ChorManager-Rechte nicht, es braucht Gruppen für Freigaben.

Ein inaktiver Benutzer (`is_active = 0`) bekommt weder Code noch Token: `UserQuery::findByEmail()`
filtert bereits auf `is_active`, für die Session-basierten Pfade muss der Authorize-Endpunkt den
Benutzer erneut über `UserQuery::findById()` laden und den Status selbst prüfen.

---

## Schlüsselverwaltung

RSA-2048-Keypair, erzeugt per `bin/oidc_admin.php key:generate` (siehe CLI unten).
Ablage in `oidc_signing_keys`; der private Schlüssel wird mit
libsodium-Secretbox verschlüsselt, Schlüssel aus neuer Env-Variable `OIDC_SIGNING_KEY_SECRET` —
gleiches Muster und gleiche Fail-Closed-Haltung wie `MAIL_CREDENTIAL_KEY` und `WEBMAIL_SSO_SECRET`.
**Vor Beginn prüfen**, ob es für `MAIL_CREDENTIAL_KEY` bereits einen wiederverwendbaren
Ver-/Entschlüsselungsdienst in `src/Services/` gibt; wenn ja, den nutzen statt einen zweiten zu bauen.

Rotation: neuer Schlüssel wird `is_active`, der alte bleibt eine Karenzzeit in JWKS stehen, damit
zwischengespeicherte Schlüsselsätze in Nextcloud nicht sofort brechen. Signiert wird immer mit dem
aktiven `kid`, das auch im JWT-Header steht.

Neue Einträge in `.env.example`: `FEATURE_OIDC`, `OIDC_SIGNING_KEY_SECRET`. (`.env.example` hat
bereits Lücken — `SESSION_SAVE_PATH`, `MAIL_DSN_INGEST_TOKEN`, `SMTP2GO_WEBHOOK_SECRET`,
`BREVO_WEBHOOK_SECRET`, `APP_LOG_STREAM`, `APP_LOG_LEVEL`, `APP_VERSION` fehlen dort. Die
nachzutragen ist eine sinnvolle Beigabe, gehört aber in einen eigenen Commit.)

---

## Neue Klassen (Vorschlag im Projektstil)

```
src/Controllers/Oidc/DiscoveryController.php     Discovery + JWKS
src/Controllers/Oidc/AuthorizeController.php     /oidc/authorize
src/Controllers/Oidc/TokenController.php         /oidc/token
src/Controllers/Oidc/UserinfoController.php      /oidc/userinfo, /oidc/logout
src/Services/Oidc/OidcClientService.php          Client laden, Secret prüfen, redirect_uri prüfen
src/Services/Oidc/AuthorizationCodeService.php   Code ausstellen, einlösen, Einmaligkeit erzwingen
src/Services/Oidc/AccessTokenService.php         Access-Token ausstellen und auflösen
src/Services/Oidc/IdTokenSigner.php              RS256-JWT bauen, JWKS liefern
src/Services/Oidc/OidcClaimsBuilder.php          User -> Claims inkl. groups
src/Models/OidcClient.php, OidcAuthCode.php, OidcAccessToken.php, OidcSigningKey.php
```

Wiring in `src/Dependencies.php` nach dem dortigen Muster (z. B. `RememberLoginService` Z. 357-359).
Logging über `LoggerInterface` mit stabilen `event`-Keys: `oidc.authorize.granted`,
`oidc.authorize.denied`, `oidc.token.issued`, `oidc.token.rejected`, `oidc.userinfo.rejected` —
Grund immer nur ins Log, nach außen die knappen Standardfehler (`invalid_grant`, `invalid_client`).

Rate-Limiting über den bestehenden `RateLimiterService`: `oidc:token:<client_id>` und
`oidc:authorize:<user_id>`, Fenster wie beim Login (10 / 900 s).

---

## Verwaltung per CLI

Keine Admin-Oberfläche in Ausbaustufe 1. Ein Einstiegspunkt mit Unterbefehlen,
`bin/oidc_admin.php`, nach dem Muster von `bin/rotating_review_state.php` (Unterbefehl als erstes
Argument) und mit dem Bootstrap aus `src/Util/CliBootstrap.php` wie `bin/dev_seed.php`.
Die Logik liegt in den Services, das Skript ist nur Ein- und Ausgabe.

```bash
ddev php bin/oidc_admin.php key:generate                  # RSA-Keypair anlegen, altes bleibt in JWKS
ddev php bin/oidc_admin.php key:list

ddev php bin/oidc_admin.php client:create "Nextcloud" --redirect-uri="https://cloud.example.org/apps/user_oidc/code"
ddev php bin/oidc_admin.php client:list
ddev php bin/oidc_admin.php client:delete <client_id>

ddev php bin/oidc_admin.php group:list                    # alle Rollen mit ihrer Nextcloud-Gruppe
ddev php bin/oidc_admin.php group:set "Vorstand" vorstand
ddev php bin/oidc_admin.php group:unset "Vorstand"

ddev php bin/oidc_admin.php user:list-uid                 # Benutzer mit gesetzter external_uid
ddev php bin/oidc_admin.php user:set-uid max@example.org mmustermann
ddev php bin/oidc_admin.php user:unset-uid max@example.org
```

Regeln:

- `client:create` gibt das Client-Secret **einmalig** im Klartext aus; gespeichert wird nur der Hash.
  Verloren heißt neu ausstellen, nicht nachschlagen.
- `group:list` zeigt auch Rollen **ohne** Zuordnung, damit sichtbar ist, was gerade nicht nach
  Nextcloud geht.
- `user:set-uid` weist zurück, wenn die UID schon einem anderen Benutzer gehört (`UNIQUE` greift
  ohnehin, aber die Meldung soll verständlich sein).
- Ausgaben sind deutscher Fließtext, Unterbefehle und Optionen bleiben englisch.

---

## Abmelden und Sperren

Ausbaustufe 1, bewusst pragmatisch — so entschieden:

- `GET /oidc/logout` beendet die ChorManager-Sitzung über den vorhandenen Logout-Pfad und leitet auf
  `post_logout_redirect_uri` zurück, sofern diese beim Client hinterlegt ist.
- Wird ein Benutzer inaktiv gesetzt oder global abgemeldet (`session_valid_after` via
  `SessionInvalidationService`), werden zusätzlich seine offenen `oidc_auth_codes` und
  `oidc_access_tokens` widerrufen. Eine **bereits bestehende** Nextcloud-Sitzung endet dadurch nicht
  sofort — sie läuft nach Nextcloud-Sitzungsdauer aus.
- Sofortiges Aussperren in Nextcloud (Backchannel-Logout bzw. Konto deaktivieren über die
  Nextcloud-Provisioning-API) ist Ausbaustufe 2 und ausdrücklich **nicht** Teil dieses Plans.

Der Abstrich ist bewusst akzeptiert: ein gesperrtes Mitglied kommt nicht mehr **hinein**, eine
schon offene Nextcloud-Sitzung läuft aber noch aus.

---

## Seed-Daten

Pflicht laut `instructions/seed.md`, in `src/Services/DevSeedService.php`:

- `oidc_clients`, `oidc_auth_codes`, `oidc_access_tokens`, `oidc_signing_keys` in `resetSeedData()`
- neue Seed-Methode `seedOidcClients()`: ein Client „Nextcloud" mit realistischer Redirect-URI
  (`https://cloud.example.org/apps/user_oidc/code`), bekanntes Test-Secret, `is_trusted = 1`
- `roles.external_group` bei einem Teil der Seed-Rollen befüllen, bei einem anderen bewusst leer
  lassen — nur so ist im Dev-Stand beides sichtbar: zugeordnet und nicht zugeordnet
- `users.external_uid` bei einigen Seed-Benutzern befüllen, damit der Bestandsfall abgedeckt ist
- Zähler in den Report in `run()`, Aufruf in abhängigkeitssicherer Reihenfolge nach Benutzern und
  Rollen
- echten Seed-Lauf ausführen und die neuen Zahlen im Report prüfen

---

## Umsetzungsreihenfolge (TDD, jeder Schritt startet mit einem roten Test)

1. **Migrationen + Modelle.** Roter Test: `MigrationChainCompletionTest` läuft mit; dazu ein
   Feature-Test, der die neuen Tabellen sowie `users.external_uid` und `roles.external_group` erwartet.
2. **`IdTokenSigner` + JWKS.** Roter Unit-Test: signiertes JWT lässt sich mit dem öffentlichen
   Schlüssel aus der JWKS-Ausgabe per `openssl_verify` prüfen, Header trägt `alg: RS256` und `kid`.
3. **Discovery-Endpunkt.** Roter Feature-Test: Antwort enthält `issuer`, `authorization_endpoint`,
   `token_endpoint`, `userinfo_endpoint`, `jwks_uri`, `response_types_supported: ["code"]`,
   `code_challenge_methods_supported: ["S256"]`, `id_token_signing_alg_values_supported: ["RS256"]`.
4. **`OidcClientService`.** Rote Tests: unbekannter Client, falsches Secret, `redirect_uri` mit
   angehängtem Pfad oder Query wird abgelehnt (exakter Vergleich).
5. **`/oidc/authorize`.** Rote Tests: Happy Path liefert Redirect mit `code` und unverändertem
   `state`; fehlende `code_challenge` wird abgelehnt; `code_challenge_method=plain` wird abgelehnt;
   inaktiver Benutzer bekommt keinen Code; ohne Session greift der Redirect auf `/login?redirect=…`.
6. **`/oidc/token`.** Rote Tests: Happy Path liefert `id_token` mit korrektem `sub`, `aud`, `iss`,
   `nonce`, `exp`; falscher `code_verifier` → `invalid_grant`; abgelaufener Code → `invalid_grant`;
   **zweite Einlösung desselben Codes** → `invalid_grant` **und** Widerruf der bereits daraus
   ausgestellten Tokens; abweichende `redirect_uri` → `invalid_grant`.
7. **CSRF-Ausnahme für `/token`.** Roter Test im Stil von `CsrfMiddlewareFeatureTest`: POST ohne
   Token wird durchgelassen, andere POST-Pfade weiterhin nicht.
8. **`OidcClaimsBuilder`.** Rote Tests: `sub` fällt ohne `external_uid` auf `cm-<id>` zurück;
   `groups` enthält genau die `external_group`-Werte der Rollen des Benutzers; eine Rolle **ohne**
   Zuordnung taucht nicht auf; Stimmgruppen tauchen nicht auf; doppelte Gruppen erscheinen einmal.
9. **`/oidc/userinfo`.** Rote Tests: Claims wie in Schritt 8; abgelaufenes und widerrufenes Token → 401.
10. **Widerruf bei Sperren/globalem Abmelden.** Roter Test: nach `SessionInvalidationService` sind
    Codes und Tokens des Benutzers widerrufen.
11. **`bin/oidc_admin.php`.** Rote Tests auf der Service-Ebene, nicht auf dem Skript: Client anlegen
    liefert das Secret genau einmal und speichert nur den Hash; `group:set` schreibt
    `roles.external_group`; `user:set-uid` weist eine bereits vergebene UID ab.
12. **Seed-Daten** wie oben, danach echter Seed-Lauf.
13. **Hilfetext** über die `create-help-topic`-Skill: Einrichtung in Nextcloud (App `user_oidc`
    installieren, Provider mit Discovery-URL, Client-ID und Secret anlegen, „unique user id"
    abschalten, Gruppen-Provisioning aktivieren), Zuordnung der Bestandskonten über `external_uid`,
    Rollen-zu-Gruppen-Zuordnung über `group:set`. Regel aus `instructions/help-docs.md` beachten:
    keine konkreten Rollennamen, nur Rechte-Labels.
14. **Schärfeprobe** für die drei sicherheitstragenden Tests (PKCE, Code-Einmaligkeit,
    `redirect_uri`-Vergleich): jeweils die Prüfung im Produktivcode gezielt sabotieren und belegen,
    dass der Test rot wird.

---

## Verifikation

```bash
ddev exec php vendor/bin/phpunit --filter Oidc
ddev exec ./vendor/bin/phinx migrate
ddev php bin/oidc_admin.php key:generate
ddev php bin/oidc_admin.php client:create "Nextcloud" --redirect-uri="https://cloud.example.org/apps/user_oidc/code"
ddev php bin/oidc_admin.php group:list
ddev exec php bin/dev_seed.php          # Report auf die neuen Zähler prüfen
ddev composer phpcs
ddev composer test                       # volle Suite einmal zum Schluss
```

End-to-End gegen die echte Nextcloud-Instanz (manuell, nach dem grünen Lauf):

1. `curl https://<app>/.well-known/openid-configuration` — Discovery vollständig.
2. `curl https://<app>/oidc/jwks.json` — genau ein aktiver Schlüssel mit `kid`.
3. In Nextcloud unter Administration → OpenID Connect den Provider anlegen (Discovery-URL,
   Client-ID, Secret), „unique user id" aus, Gruppen-Provisioning an.
4. Abmelden, „Anmelden mit ChorManager" wählen, Login in ChorManager, Rücksprung nach Nextcloud.
   Erwartung: Konto existiert mit korrekter UID, Anzeigename, E-Mail und genau den Gruppen aus
   `group:list`.
5. Zweiter Durchlauf mit einem Bestandskonto, dessen `external_uid` gesetzt wurde: es darf **kein**
   neues Konto entstehen, die vorhandenen Dateien müssen da sein.

---

## Entschieden

- Sofortiger Rauswurf aus einer laufenden Nextcloud-Sitzung ist **nicht** Teil von Ausbaustufe 1.
- Verwaltung läuft über CLI, **inklusive** Zuordnung Rolle → Nextcloud-Gruppe. Keine Admin-Oberfläche.
- Der `groups`-Claim speist sich **nur** aus Rollen, nicht aus Stimmgruppen.

## Offene Punkte

1. **`SafeRedirect`** muss den Query-String der Authorize-URL über den Login hinweg erhalten; ob er
   das heute tut, ist im ersten Schritt zu prüfen. Falls nicht, dort erweitern — weiterhin nur
   eigener Host, kein offener Redirect.
2. **`quota`-Claim** wird nicht geliefert, Nextcloud bleibt bei seinem Standardkontingent.
