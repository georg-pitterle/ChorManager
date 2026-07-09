# Design: Anmeldemodul für zukünftige Termine (Event Registration)

Datum: 2026-07-09
Status: Entwurf genehmigt (Brainstorming abgeschlossen)

## Ziel

Mitglieder (oder stellvertretend ihre Stimmvertretungen) tragen ihre Teilnahme an
zukünftigen Terminen ein. Ziel ist ein Überblick, wer bei einem Termin anwesend
sein wird und ob die Besetzung reicht. Das Modul ist als Feature global
ein-/ausschaltbar. Zusätzlich wird pro Termin steuerbar, ob eine
Anwesenheitsliste geführt wird.

## Anforderungen

- Status pro Mitglied und Termin: Zusage / Absage / Vielleicht; optionale
  Begründung (v. a. bei Absage). Kein Eintrag = „offen".
- Anmeldung ist von der Anwesenheitserfassung getrennt, wird dort aber als
  read-only Hinweis angezeigt (keine automatische Übernahme).
- Die Anmeldeübersicht (Namen + Status) ist für alle eingeloggten Mitglieder
  sichtbar.
- Anmeldung wird pro Termin freigeschaltet (`registration_enabled`); optionaler
  Anmeldeschluss pro Termin (`registration_deadline`), Default = Terminbeginn.
- Vergangene Termine bzw. Termine nach Anmeldeschluss sind nicht mehr
  bearbeitbar (read-only).
- Vertretungseinträge: wie bei der Anwesenheit — Stimmvertretungen (Level ≥ 40 /
  `can_manage_attendance`) für Mitglieder der eigenen Stimmgruppen,
  Admin/Vorstand (`can_manage_users`) für alle.
- Auswertung: Besetzung pro Stimmgruppe je Termin + Rücklaufquote.
- Keine Sollstärke; die Auswertung zeigt nur Zahlen.
- Erinnerungsmail X Tage vor Anmeldeschluss an alle, die noch nicht geantwortet
  haben, mit Direktlink auf die Eintragung (Login mit Redirect).
- Pro Termin einstellbar, ob überhaupt eine Anwesenheitsliste verlangt wird
  (`attendance_required`). Anmeldung und Anwesenheit sind unabhängig
  kombinierbar (alle vier Kombinationen möglich).
- Feature-Toggle global über `app_settings`.

## 1. Datenmodell

### Neue Tabelle `event_registrations` (Phinx-Migration)

| Spalte | Typ | Bemerkung |
|---|---|---|
| `id` | int PK auto | |
| `event_id` | int FK → `events.id`, ON DELETE CASCADE | |
| `user_id` | int FK → `users.id`, ON DELETE CASCADE | |
| `status` | enum `yes` / `no` / `maybe` | |
| `note` | varchar(255) nullable | Begründung, v. a. bei Absage |
| `updated_by` | int FK → `users.id`, nullable | wer eingetragen hat; ≠ `user_id` ⇒ Vertretungseintrag |
| `created_at` / `updated_at` | datetime | Eloquent-Timestamps aktiv |

Unique-Index auf `(event_id, user_id)`.

### Erweiterung `events` (gleiche Migration)

- `registration_enabled` tinyint(1) NOT NULL DEFAULT 0 — Anmeldung freigeschaltet
- `registration_deadline` datetime NULL — leer ⇒ Anmeldeschluss = `starts_at`
- `registration_reminder_sent_at` datetime NULL — Erinnerung bereits verschickt
- `attendance_required` tinyint(1) NOT NULL DEFAULT 1 — Anwesenheitsliste wird
  geführt; Default 1 erhält das heutige Verhalten für Bestandstermine

### Feature-Toggle

`app_settings`-Key `feature_registration_enabled` (`'1'`/`'0'`, Default aus).
Checkbox in den App-Einstellungen (`AppSettingController`). Toggle aus ⇒
Menüpunkt ausgeblendet (Twig-Global `app_settings` existiert), Routen antworten
404, Termin-Formular zeigt die Anmeldefelder nicht.

### Modelle

- Neues Modell `EventRegistration` (Timestamps an, Relationen `event`, `user`,
  `updatedBy`).
- `Event::registrations()` hasMany, `User::eventRegistrations()` hasMany.

## 2. Routen, Controller, Rechte

Neuer `RegistrationController`; Routen hinter Login, kein eigenes Recht-Flag
(Selbsteintrag ist für alle Mitglieder):

| Route | Methode | Zweck |
|---|---|---|
| `/registrations` | GET | Kommende Termine mit freigeschalteter Anmeldung + eigener Status |
| `/registrations/{event_id}` | GET | Termin-Detail: Übersicht nach Stimmgruppe, eigener Eintrag, Vertretungs-Formular falls berechtigt |
| `/registrations/{event_id}` | POST | Eigenen Status speichern |
| `/registrations/{event_id}/proxy` | POST | Vertretungseintrag für andere |

### Feature-Gate

Neue schlanke `FeatureMiddleware('feature_registration_enabled')` auf der
Routengruppe — Setting aus ⇒ 404. Wiederverwendbar für spätere Features.

### Validierungsregeln (beide POSTs)

1. Feature an (Middleware).
2. `registration_enabled = 1` am Event, sonst 403.
3. Deadline nicht überschritten: `(registration_deadline ?? starts_at) > now`,
   sonst 403 — deckt „vergangene Termine nicht bearbeitbar" ab.
4. Proxy: Ziel-User in erlaubter Menge — gleiche Logik wie
   `getManageableUserIds()` im `AttendanceController`; wird in gemeinsamen
   Service extrahiert (`AttendanceScopeService`), damit keine Kopie entsteht.
5. `updated_by` = eingeloggter User.

### Termin-Formular (`EventController`)

- Checkbox „Anmeldung freischalten" + optionales Datumsfeld „Anmeldeschluss"
  (nur sichtbar, wenn Feature an).
- Checkbox „Anwesenheitsliste führen" (immer sichtbar, unabhängig vom
  Anmelde-Feature; Default an).
- Gilt auch für Serien-Termine (Felder pro Einzeltermin).

## 3. UI, Anwesenheits-Integration, Auswertung

### `/registrations` (Liste)

Kommende Termine mit `registration_enabled`, je Zeile: Datum, Titel, Ort,
Zähler (Zusagen / Absagen / Vielleicht / Offen), eigener Status als Badge,
Schnell-Buttons zum direkten Zu-/Absagen ohne Detailseite. Anmeldeschluss
sichtbar; abgelaufen ⇒ Buttons gesperrt, nur Ansicht.

### `/registrations/{id}` (Detail)

- Kopf: Termin-Infos, Zähler gesamt + Rücklaufquote (X von Y geantwortet).
- Eigener Eintrag: drei Buttons + Notizfeld; Notizfeld erscheint bei Absage
  (empfohlen, nicht erzwungen).
- Mitgliederliste gruppiert nach Stimmgruppe (gleiche Gruppierung wie
  Anwesenheitsseite): Name, Status-Badge, Notiz, „eingetragen von" bei
  Vertretung.
- Berechtigte (Stimmvertretung/Admin) setzen den Status anderer direkt in der
  Liste — nur Mitglieder der eigenen Stimmgruppen editierbar, Rest read-only.

### Anwesenheits-Integration

`templates/attendance/show.twig`: bei Terminen mit Anmeldung neue read-only
Spalte „Anmeldung" (Badge Zusage/Absage/Vielleicht + Notiz als Tooltip). Keine
automatische Vorbelegung der Radio-Buttons — bewusst nur Anzeige
(Fehlklick-Risiko).

### Auswertung

Neuer Bereich unter `/evaluations` (sichtbar für alle Mitglieder):

- Tabelle kommender freigeschalteter Termine × Stimmgruppen; Zellen:
  Zusagen-Zahl (+ Vielleicht in Klammern).
- Spalten: Gesamt-Zusagen, Rücklaufquote in %.
- Vergangene Termine mit Anmeldung per Filter „auch vergangene" sichtbar,
  read-only — Nachbetrachtung Anmeldung vs. tatsächliche Anwesenheit; die
  Vergleichsspalte erscheint nur, wenn für den Termin eine Anwesenheitsliste
  geführt wurde.

### Template-Regeln

Mobile: Karten statt breiter Tabellen, wie bestehende Seiten. Kein
Inline-CSS/JS; eigene Klassen in `public/css/style.css`, JS in eigener Datei.

## 4. Anwesenheitsliste pro Termin abschaltbar

Verhalten bei `attendance_required = 0`:

| Stelle | Verhalten |
|---|---|
| Anwesenheitsseite (`AttendanceController`) | Termin fehlt in Auswahl/Navigation; direkter Aufruf ⇒ Hinweis „Für diesen Termin wird keine Anwesenheit geführt", kein Formular; POST ⇒ 403 |
| Anwesenheits-Auswertung (`EvaluationController`) | Termin zählt weder im Nenner (`totalEvents`) noch in den Status-Zählern — Prozentwerte bleiben fair |
| Anmeldung | unabhängig — Termin kann Anmeldung ohne Anwesenheitsliste haben (z. B. freiwilliges Fest) und umgekehrt |

## 5. Erinnerungsmails

- Einstellung: `app_settings`-Key `registration_reminder_days_before` (Zahl,
  leer/0 = Erinnerung aus). Feld in den App-Einstellungen. Global, nicht pro
  Termin.
- Empfänger pro Termin mit `registration_enabled = 1`, Deadline in ≤ X Tagen
  und noch nicht vorbei:
  - Termin projektgebunden ⇒ aktive Projektmitglieder; sonst alle aktiven
    Mitglieder mit E-Mail-Adresse,
  - minus alle mit vorhandenem `event_registrations`-Eintrag (egal welcher
    Status).
- Einmaligkeit: `events.registration_reminder_sent_at` wird nach Versand
  gesetzt ⇒ genau eine Erinnerungsrunde pro Termin.
- Trigger: neuer `RegistrationReminderService::processDue()` — gleiches Muster
  wie Mail-Queue: opportunistisch via Middleware (gedrosselt, Check max.
  1×/Stunde, Zeitstempel in `app_settings`) + CLI-Command für Cron-Betrieb.
- Versand über bestehende Queue:
  `MailQueueService::enqueueGenericMail(mailType: 'registration_reminder')`.
- Mail-Template `templates/emails/registration_reminder.twig` (Inline-Styles
  dort erlaubt): Termin, Datum, Ort, Anmeldeschluss, Button „Jetzt eintragen"
  → `{baseUrl}/registrations/{event_id}` (Basis-URL via `AppUrlResolver`).

### Login-Redirect (neue Kern-Fähigkeit)

- `AuthMiddleware`: nicht eingeloggt + GET ⇒ Redirect auf
  `/login?redirect={pfad}`.
- Login-Formular trägt `redirect` als Hidden-Field durch.
- Nach erfolgreichem Login Redirect auf das Ziel **nur wenn** validiert:
  relativer Pfad, beginnt mit genau einem `/`, kein `//`, kein `\`, kein
  Schema (Open-Redirect-Schutz). Sonst Standard-Dashboard.

## 6. Fehlerbehandlung

| Fall | Verhalten |
|---|---|
| Feature aus, Route aufgerufen | 404 (Middleware) |
| Anmeldung am Event nicht freigeschaltet | 403 + Meldung |
| Deadline vorbei / Termin vergangen | 403 bei POST; GET zeigt read-only |
| Proxy für fremde Stimmgruppe | 403 |
| Ungültiger Status-Wert | Eintrag ignoriert, Fehlermeldung |
| POST Anwesenheit bei `attendance_required = 0` | 403 |
| DB-Fehler beim Speichern | Transaktion + Rollback, Flash-Error, Log via `LoggerInterface` (`event`: `registration.save_failed`) |

Logging generell nach Logging-Standard: strukturiert, `event`-Key, Exceptions
im `exception`-Kontext.

## 7. Tests (TDD)

- Selbsteintrag: anlegen, ändern, alle drei Status, Notiz.
- Sperren: vergangener Termin, Deadline überschritten,
  `registration_enabled = 0`, Feature-Toggle aus (404).
- Proxy: Stimmvertretung eigene Gruppe ✓, fremde Gruppe ✗, Admin alle ✓.
- `updated_by` korrekt gesetzt (Selbsteintrag vs. Vertretung).
- Zähler/Rücklaufquote-Berechnung.
- Auswertungs-Query pro Stimmgruppe.
- Erinnerung: Empfängerauswahl (registriert/nicht registriert, Projekt-Scope),
  Einmaligkeit, Deadline-Fenster.
- Login-Redirect: gültige relative Pfade funktionieren, bösartige URLs
  (`//evil`, `http://…`, `\`) werden abgewiesen.
- `attendance_required = 0`: Termin fehlt in Anwesenheitsauswahl, POST
  blockiert, Auswertungs-Nenner korrekt.

## 8. Seed-Daten (`DevSeedService`)

- `feature_registration_enabled = 1` und
  `registration_reminder_days_before = 3` im App-Settings-Seed.
- Mehrere zukünftige Termine mit `registration_enabled = 1`: einer mit
  Deadline, einer ohne, einer im Erinnerungsfenster ohne vollständigen
  Rücklauf, einer vergangener mit Anmeldedaten.
- Mindestens ein Termin mit `attendance_required = 0` (mit freigeschalteter
  Anmeldung).
- Realistische Anmeldungen über alle Status verteilt, einige
  Vertretungseinträge (`updated_by` ≠ `user_id`), Teil der Mitglieder ohne
  Antwort (für Rücklaufquote).
- Pflicht-Checkliste aus `instructions/seed.md`: `event_registrations` in
  `resetSeedData()`, Zähler im Report, Modell-Import, Seed-Methoden in
  `run()`-Flow, echter Seed-Lauf vor Abschluss.

## 9. Migration

Eine Phinx-Migration: Tabelle `event_registrations` + vier neue
`events`-Spalten. Ausführung: `ddev exec ./vendor/bin/phinx migrate`.

## Bewusste Nicht-Ziele (YAGNI)

- Keine Sollstärke pro Termin oder Stimmgruppe.
- Keine automatische Übernahme der Anmeldung in die Anwesenheit.
- Kein Anmeldeschluss-Vorlauf als globale Einstellung.
- Kein generisches Umfrage-Modul.
- Keine Mehrfach-Erinnerungen pro Termin.
