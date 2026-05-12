# Plan: SnappyMail Integration (Option 2)

## Ziel

SnappyMail in ChorManager integrieren mit:

- Profilbasierter IMAP-Konfiguration pro Benutzer
- Auto-Login ueber Proxy/Plugin-Ansatz (Option 2)
- Badge fuer ungelesene/neue Mails
- Keine Speicherung von Mail-Inhalten in ChorManager

## Umsetzungsphasen

- [ ] Phase 1: Datenmodell und Migration
- [ ] Phase 2: Backend-Services fuer Credentials und Security
- [ ] Phase 3: Profil-UI und Controller-Erweiterung
- [ ] Phase 4: SnappyMail Service in Compose + Nginx Routing
- [ ] Phase 5: Auto-Login Flow (Proxy/Plugin)
- [ ] Phase 6: Badge-Metadatenfluss
- [ ] Phase 7: Tests (Feature + Unit)
- [ ] Phase 8: Doku und Betriebsanleitung

---

## Phase 1: Datenmodell und Migration

- [ ] Neue Migration fuer `user_mail_accounts` anlegen
- [ ] Felder anlegen:
  - [ ] `user_id` (unique FK)
  - [ ] `imap_host`, `imap_port`, `imap_encryption`, `imap_username`, `imap_password_enc`
  - [ ] `imap_enabled`, `mail_badge_enabled`
  - [ ] `mail_last_unseen_count`, `mail_last_uid_seen`, `mail_last_checked_at`
  - [ ] `created_at`, `updated_at`
- [ ] Eloquent-Model `UserMailAccount` erstellen
- [ ] Beziehung im `User`-Model ergaenzen
- [ ] Migration mit `ddev php`/`ddev exec` lokal ausfuehren

## Phase 2: Backend-Services fuer Credentials und Security

- [ ] `MailCredentialCryptoService` erstellen
- [ ] ENV-Key einbinden (z. B. `MAIL_CREDENTIAL_KEY`)
- [ ] Encrypt/Decrypt API definieren
- [ ] Fehlerfall bei fehlendem Key fail-closed behandeln
- [ ] Logging ohne Secrets (event-basiert) ergaenzen

## Phase 3: Profil-UI und Controller-Erweiterung

- [ ] Profilformular erweitern um Mailbox-Abschnitt
- [ ] Felder: Host, Port, Verschluesselung, Username, Passwort, Aktiv-Flag, Badge-Flag
- [ ] Optionalen Button "Verbindung testen" einbauen
- [ ] `ProfileController` erweitern fuer Speichern und Validierung
- [ ] Benutzermeldungen fuer Erfolg/Fehler verbessern

## Phase 4: SnappyMail Service in Compose + Nginx Routing

- [ ] `docker-compose.yml` um Service `snappymail` erweitern
- [ ] Persistentes Volume fuer SnappyMail-Daten definieren
- [ ] Nginx-Route `/webmail` auf SnappyMail updaten
- [ ] Sicherheitsrelevante Header und Limits pruefen
- [ ] Start und Smoke-Test ueber DDEV ausfuehren

## Phase 5: Auto-Login Flow (Proxy/Plugin)

- [ ] Endpoint in ChorManager fuer Webmail-Start erstellen
- [ ] Kurzlebiges signiertes Login-Artefakt erzeugen (TTL 30-60s)
- [ ] Nonce/JTI fuer Replay-Schutz umsetzen
- [ ] Mapping zum SnappyMail-Login-Plugin implementieren
- [ ] Fehlerpfade (abgelaufen, ungueltig, replay) sauber behandeln

## Phase 6: Badge-Metadatenfluss

- [ ] Service fuer IMAP-Metadatenabruf erstellen (nur UNSEEN/UID)
- [ ] Keine Body-/Attachment-Persistenz sicherstellen
- [ ] Cache-/Refresh-Strategie definieren (on-demand oder intervallbasiert)
- [ ] Navigation um Mail-Badge erweitern
- [ ] Fallback bei IMAP-Fehlern implementieren

## Phase 7: Tests (Feature + Unit)

- [ ] Feature-Tests fuer Profilspeicherung und Validierung
- [ ] Feature-Tests fuer geschuetzten Webmail-Start
- [ ] Feature-Tests fuer Redirect-/Fehlerpfade
- [ ] Feature-Tests fuer Badge-Refresh bei Erfolg/Fehler
- [ ] Unit-Tests fuer Crypto-Service
- [ ] Unit-Tests fuer TTL/Replay-Validierung
- [ ] Relevante Tests via DDEV ausfuehren und dokumentieren

## Phase 8: Doku und Betrieb

- [ ] README um Konfiguration erweitern (ENV + Compose + Routing)
- [ ] Betriebsnotizen fuer Secret-Rotation ergaenzen
- [ ] Monitoring-/Log-Events dokumentieren
- [ ] Rollout-Checkliste fuer Staging/Prod ergänzen

---

## Akzeptanzkriterien

- [ ] Webmail-Start funktioniert fuer eingeloggte Benutzer ohne zweiten Login
- [ ] Badge zeigt ungelesene/neue Mails robust an
- [ ] Keine Mail-Inhalte werden in ChorManager gespeichert
- [ ] Security-Pruefungen fuer Artefakte sind aktiv und getestet
- [ ] Alle neuen Features sind durch automatisierte Tests abgedeckt

## Ausfuehrungshinweise

- Projektbefehle mit DDEV ausfuehren
- Fuer Composer: `ddev composer ...`
- Fuer PHP CLI: `ddev php ...`
- Vor Abschluss relevante Tests laufen lassen und Ergebnisse festhalten