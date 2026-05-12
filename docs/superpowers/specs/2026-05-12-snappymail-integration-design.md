# SnappyMail Integration Design fuer ChorManager

Datum: 2026-05-12
Status: Freigegeben fuer Planerstellung

## Ziel

ChorManager integriert SnappyMail als eingebetteten Webmail-Client mit:

- Konfiguration pro Benutzer im Profil
- Auto-Login via Proxy/Plugin-Ansatz (Option 2)
- IMAP-only
- Badge fuer ungelesene oder neue Mails
- Keine Speicherung von Mail-Inhalten in ChorManager

## Festgelegte Entscheidungen

1. Auth-Modell: pro Benutzer eigene IMAP-Zugangsdaten
2. Passwortspeicherung: ja, verschluesselt in der Datenbank mit Key aus ENV
3. Phase-1 Scope: SSO/Auto-Login plus Badge fuer ungelesene oder neue Mails
4. Architekturwahl: Option 2 (Login-Remote/Proxy-Plugin)

## Architektur

### Komponenten

- ChorManager als fuehrendes Portal fuer Session, Rollen, Profil, Badge
- SnappyMail als separater Service im Compose-Stack
- Nginx Reverse Proxy fuer Routing auf `/webmail`
- SnappyMail Login-Plugin fuer automatischen Login aus trusted Kontext

### High-Level Flow

1. Benutzer pflegt IMAP-Zugang im Profil.
2. ChorManager validiert Eingaben und speichert verschluesselt.
3. Bei Klick auf Webmail erzeugt ChorManager ein kurzlebiges, signiertes Login-Artefakt.
4. Browser wird auf SnappyMail geleitet.
5. SnappyMail-Plugin validiert Artefakt, mappt User, startet IMAP-Session.
6. ChorManager aktualisiert den Mail-Badge ueber Metadatenabruf.

## Datenmodell

Empfohlen ist eine eigene Tabelle `user_mail_accounts`:

- `id` (PK)
- `user_id` (FK, unique)
- `imap_host` (varchar)
- `imap_port` (int)
- `imap_encryption` (enum: ssl, tls, none)
- `imap_username` (varchar)
- `imap_password_enc` (text)
- `imap_enabled` (bool)
- `mail_badge_enabled` (bool)
- `mail_last_unseen_count` (int, nullable)
- `mail_last_uid_seen` (varchar, nullable)
- `mail_last_checked_at` (datetime, nullable)
- `created_at`, `updated_at`

Nicht in ChorManager gespeichert werden:

- Message Body
- Attachments
- Vollstaendige Header
- Nachrichtentexte

## Sicherheit

1. Symmetrische Verschluesselung der IMAP-Passwoerter mit ENV-Key
2. Keine Secrets in Logs
3. Login-Artefakte mit kurzer TTL (30-60 Sekunden)
4. Einmalverwendung mit Replay-Schutz (Nonce oder JTI)
5. Strikte Signaturpruefung im SnappyMail-Login-Plugin
6. Fail-closed Verhalten bei fehlendem Secret oder ungultiger Signatur

## Datenfluss und Fehlerbehandlung

### Profil speichern

1. Pflichtfelder validieren (Host, Port, Security, Username, Passwort)
2. Verschluesseln und speichern
3. Optional Verbindungscheck
4. Aktivierung nur bei gueltiger Konfiguration

### Webmail starten

1. Session und Berechtigung pruefen
2. Login-Artefakt erzeugen
3. Redirect nach `/webmail`
4. Plugin validiert, loggt ein
5. Bei Fehlern kontrollierter Redirect mit neutraler Meldung

### Badge refresh

1. Nur IMAP-Metadaten abrufen (z. B. UNSEEN)
2. Cache-Werte aktualisieren
3. Keine Mail-Inhalte persistieren

## Testing und Abnahme

### Pflichttests (Feature)

1. Profilspeichern validiert Eingaben und speichert verschluesselt
2. Unauthentifizierte Zugriffe auf Webmail-Start blockiert
3. Fehlende Konfiguration fuehrt zu sauberer Fehlerrueckgabe
4. Gueltige Konfiguration fuehrt zu Webmail-Redirect
5. Badge-Refresh bricht UI bei IMAP-Fehlern nicht

### Unit Tests

1. Crypto Roundtrip
2. TTL und Replay-Pruefung
3. Validatoren fuer Profilfelder

### Abnahmekriterien

1. Ein eingeloggter Benutzer erreicht SnappyMail ohne zweiten Login
2. Badge ist sichtbar und robust
3. Keine Mailinhalte im ChorManager
4. Security-Pruefungen fuer Artefakte sind wirksam

## Rollout

1. Dev: Compose-Service und Proxy-Routing integrieren
2. Staging: End-to-End mit Test-IMAP, Negativtests fuer Security
3. Produktion: stufenweise Freischaltung via Feature-Flag

## Empfehlung

Option 2 wird umgesetzt mit harten Sicherheitsgrenzen am Proxy/Plugin-Einstieg und minimaler Metadatenhaltung fuer den Badge.