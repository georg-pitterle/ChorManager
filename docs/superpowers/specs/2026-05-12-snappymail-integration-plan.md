# SnappyMail Integration Plan (Option 2)

Datum: 2026-05-12
Status: Freigegeben für Umsetzung

## 1. Ziel

SnappyMail in ChorManager integrieren mit:

- Profilbasierter IMAP-Konfiguration pro Benutzer
- Auto-Login über Proxy/Plugin-Ansatz (Option 2)
- Badge für ungelesene/neue Mails
- Keine Speicherung von Mail-Inhalten in ChorManager

## 2. Festgelegte Entscheidungen

1. Auth-Modell: pro Benutzer eigene IMAP-Zugangsdaten
2. Passwortspeicherung: ja, verschlüsselt in der Datenbank mit Key aus ENV
3. Phase-1 Scope: SSO/Auto-Login plus Badge für ungelesene oder neue Mails
4. Architekturwahl: Option 2 (Login-Remote/Proxy-Plugin)

## 3. Architektur

### Komponenten

- ChorManager als führendes Portal für Session, Rollen, Profil, Badge
- SnappyMail als separater Service im Compose-Stack
- Nginx Reverse Proxy für Routing auf `/webmail`
- SnappyMail Login-Plugin für automatischen Login aus trusted Kontext

### High-Level Flow

1. Benutzer pflegt IMAP-Zugang im Profil.
2. ChorManager validiert Eingaben und speichert verschlüsselt.
3. Bei Klick auf Webmail erzeugt ChorManager ein kurzlebiges, signiertes Login-Artefakt.
4. Browser wird auf SnappyMail geleitet.
5. SnappyMail-Plugin validiert Artefakt, mappt User, startet IMAP-Session.
6. ChorManager aktualisiert den Mail-Badge über Metadatenabruf.

## 4. Datenmodell

Eigene Tabelle `user_mail_accounts`:

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

Nicht in ChorManager speichern:

- Message Body
- Attachments
- Vollständige Header
- Nachrichtentexte

## 5. Sicherheit

1. Symmetrische Verschlüsselung der IMAP-Passwörter mit ENV-Key
2. Keine Secrets in Logs
3. Login-Artefakte mit kurzer TTL (30-60 Sekunden)
4. Einmalverwendung mit Replay-Schutz (Nonce oder JTI)
5. Strikte Signaturprüfung im SnappyMail-Login-Plugin
6. Fail-closed Verhalten bei fehlendem Secret oder ungültiger Signatur

## 6. Datenfluss und Fehlerbehandlung

### Profil speichern

1. Pflichtfelder validieren (Host, Port, Security, Username, Passwort)
2. Verschlüsseln und speichern
3. Optional Verbindungscheck
4. Aktivierung nur bei gültiger Konfiguration

### Webmail starten

1. Session und Berechtigung prüfen
2. Login-Artefakt erzeugen
3. Redirect nach `/webmail`
4. Plugin validiert, loggt ein
5. Bei Fehlern kontrollierter Redirect mit neutraler Meldung

### Badge refresh

1. Nur IMAP-Metadaten abrufen (z. B. UNSEEN)
2. Cache-Werte aktualisieren
3. Keine Mail-Inhalte persistieren

## 7. Umsetzungsphasen

- [ ] Phase 1: Datenmodell und Migration
- [ ] Phase 2: Backend-Services für Credentials und Security
- [ ] Phase 3: Profil-UI und Controller-Erweiterung
- [ ] Phase 4: SnappyMail Service in Compose + Nginx Routing
- [ ] Phase 5: Auto-Login Flow (Proxy/Plugin)
- [ ] Phase 6: Badge-Metadatenfluss
- [ ] Phase 7: Tests (Feature + Unit)
- [ ] Phase 8: Doku und Betriebsanleitung

### Phase 1: Datenmodell und Migration

- [ ] Neue Migration für `user_mail_accounts` anlegen
- [ ] Felder anlegen:
  - [ ] `user_id` (unique FK)
  - [ ] `imap_host`, `imap_port`, `imap_encryption`, `imap_username`, `imap_password_enc`
  - [ ] `imap_enabled`, `mail_badge_enabled`
  - [ ] `mail_last_unseen_count`, `mail_last_uid_seen`, `mail_last_checked_at`
  - [ ] `created_at`, `updated_at`
- [ ] Eloquent-Model `UserMailAccount` erstellen
- [ ] Beziehung im `User`-Model ergänzen
- [ ] Migration mit `ddev php`/`ddev exec` lokal ausführen

### Phase 2: Backend-Services für Credentials und Security

- [ ] `MailCredentialCryptoService` erstellen
- [ ] ENV-Key einbinden (z. B. `MAIL_CREDENTIAL_KEY`)
- [ ] Encrypt/Decrypt API definieren
- [ ] Fehlerfall bei fehlendem Key fail-closed behandeln
- [ ] Logging ohne Secrets (event-basiert) ergänzen

### Phase 3: Profil-UI und Controller-Erweiterung

- [ ] Profilformular erweitern um Mailbox-Abschnitt
- [ ] Felder: Host, Port, Verschlüsselung, Username, Passwort, Aktiv-Flag, Badge-Flag
- [ ] Optionalen Button "Verbindung testen" einbauen
- [ ] `ProfileController` erweitern für Speichern und Validierung
- [ ] Benutzermeldungen für Erfolg/Fehler verbessern

### Phase 4: SnappyMail Service in Compose + Nginx Routing

- [ ] `docker-compose.yml` um Service `snappymail` erweitern
- [ ] Persistentes Volume für SnappyMail-Daten definieren
- [ ] Nginx-Route `/webmail` auf SnappyMail updaten
- [ ] Sicherheitsrelevante Header und Limits prüfen
- [ ] Start und Smoke-Test über DDEV ausführen

### Phase 5: Auto-Login Flow (Proxy/Plugin)

- [ ] Endpoint in ChorManager für Webmail-Start erstellen
- [ ] Kurzlebiges signiertes Login-Artefakt erzeugen (TTL 30-60s)
- [ ] Nonce/JTI für Replay-Schutz umsetzen
- [ ] Mapping zum SnappyMail-Login-Plugin implementieren
- [ ] Fehlerpfade (abgelaufen, ungültig, replay) sauber behandeln

### Phase 6: Badge-Metadatenfluss

- [ ] Service für IMAP-Metadatenabruf erstellen (nur UNSEEN/UID)
- [ ] Keine Body-/Attachment-Persistenz sicherstellen
- [ ] Cache-/Refresh-Strategie definieren (on-demand oder intervallbasiert)
- [ ] Navigation um Mail-Badge erweitern
- [ ] Fallback bei IMAP-Fehlern implementieren

### Phase 7: Tests (Feature + Unit)

- [ ] Feature-Tests für Profilspeicherung und Validierung
- [ ] Feature-Tests für geschützten Webmail-Start
- [ ] Feature-Tests für Redirect-/Fehlerpfade
- [ ] Feature-Tests für Badge-Refresh bei Erfolg/Fehler
- [ ] Unit-Tests für Crypto-Service
- [ ] Unit-Tests für TTL/Replay-Validierung
- [ ] Relevante Tests via DDEV ausführen und dokumentieren

### Phase 8: Doku und Betrieb

- [ ] README um Konfiguration erweitern (ENV + Compose + Routing)
- [ ] Betriebsnotizen für Secret-Rotation ergänzen
- [ ] Monitoring-/Log-Events dokumentieren
- [ ] Rollout-Checkliste für Staging/Prod ergänzen

## 8. Testing, Abnahme und Rollout

### Abnahmekriterien

- [ ] Webmail-Start funktioniert für eingeloggte Benutzer ohne zweiten Login
- [ ] Badge zeigt ungelesene/neue Mails robust an
- [ ] Keine Mail-Inhalte werden in ChorManager gespeichert
- [ ] Security-Prüfungen für Artefakte sind aktiv und getestet
- [ ] Alle neuen Features sind durch automatisierte Tests abgedeckt

### Rollout

1. Dev: Compose-Service und Proxy-Routing integrieren
2. Staging: End-to-End mit Test-IMAP, Negativtests für Security
3. Produktion: stufenweise Freischaltung via Feature-Flag

## 9. Ausführungshinweise

- Projektbefehle mit DDEV ausführen
- Für Composer: `ddev composer ...`
- Für PHP CLI: `ddev php ...`
- Vor Abschluss relevante Tests laufen lassen und Ergebnisse festhalten
