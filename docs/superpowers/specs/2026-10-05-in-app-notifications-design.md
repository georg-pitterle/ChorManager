# Benachrichtigungen in der App (Glocke)

Stand: 2026-10-05 · Status: Entwurf, abgestimmt im Brainstorming

## Ziel

Neben dem Mail-Badge in der Kopfzeile steht eine Glocke mit der Zahl ungelesener
Benachrichtigungen. Sie ist ein **zweiter Kanal** des bestehenden
Benachrichtigungssystems: Jeder Anlass aus `NotificationType`, der heute eine Mail
auslöst, legt zusätzlich einen Eintrag in der Glocke an. Mail und Glocke lassen sich im
Profil pro Anlass getrennt abbestellen.

Verhalten wie bei solchen Funktionen üblich: Zähler, Liste der letzten Einträge im
Dropdown, Klick führt zum Objekt und markiert gelesen, „Alle als gelesen markieren“,
vollständige Liste auf einer eigenen Seite.

Erfolg heißt: Kommentiert jemand eine Aufgabe, sehen die Zugewiesenen und die
Erstellerin beim nächsten Seitenaufruf oder Tab-Wechsel eine rote Zahl an der Glocke,
öffnen über den Eintrag die Aufgabe, und die Zahl sinkt.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Verhältnis zur Mail | Zweiter Kanal im selben `NotificationService` (Ansatz A). Die Auslöser rufen weiter nur `notify()` auf. |
| Welche Anlässe | Alle neun Anlässe aus `NotificationType`. Keine Kanal-Liste pro Anlass – jeder Anlass hat beide Kanäle. |
| Empfängerkreis | Unverändert pro Anlass, für beide Kanäle gleich (z. B. `TASK_COMMENT`: Zugewiesene und Erstellerin). |
| Einstellungen der Person | Zwei Häkchen pro Anlass: „E-Mail“ und „Glocke“. Vorgabe: beide an. Gespeichert wird nur die Abweichung. |
| Schalter der Verwaltung | Bleibt einer pro Anlass (`AppSetting`) und schaltet beide Kanäle ab. |
| Aktualisierung | Abfrage beim Zurückwechseln in den Tab und alle 2 Minuten bei sichtbarem Tab. Kein WebSocket, kein SSE. |
| Aufbewahrung | Gelesene Einträge 30 Tage, ungelesene 180 Tage; Aufräumen nebenbei wie beim Versandprotokoll. |

## Nicht im Umfang

- Push-Benachrichtigungen im Browser oder auf dem Handy
- Live-Aktualisierung (WebSocket, SSE)
- Zusammenfassen mehrerer Einträge („3 neue Kommentare zu …“)
- Eigener Schalter der Verwaltung je Kanal
- Neue Anlässe oder geänderte Empfängerkreise
- Rechte auf den Zielseiten: Ein Link führt dorthin, wohin heute schon die Mail führt.
  Kann die Person die Zielseite nicht öffnen, ist das ein bestehendes Problem der Mail
  und wird hier nicht gelöst.
- Hilfethema (nur auf ausdrückliche Anforderung)
- Playwright-Szenario (kann nachgereicht werden)

## Datenmodell

### Neue Tabelle `user_notifications`

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | int, PK | |
| `user_id` | int, FK `users` ON DELETE CASCADE | Empfängerin |
| `notification_type` | string(64) | Schlüssel aus `NotificationType` |
| `actor_user_id` | int NULL, FK `users` ON DELETE SET NULL | Wer den Anlass ausgelöst hat; leer bei Erinnerungen |
| `title` | string(255) | z. B. „Neuer Kommentar: Notenpult reparieren“ |
| `body` | string(255) NULL | Eine Zeile reiner Text, gekürzt auf 140 Zeichen mit „…“ |
| `link` | string(512) | Relativer Pfad, muss mit genau einem `/` beginnen |
| `entity_type` | string(64) NULL | Objekt, auf das der Link zeigt (`task`, `event`, `project`, `sponsor`) |
| `entity_id` | int NULL | |
| `comment_id` | int NULL | Bemerkung, aus der der Eintrag stammt; Löschen entfernt, Ändern aktualisiert den Eintrag |
| `read_at` | datetime NULL | Leer heißt ungelesen |
| `created_at` | datetime | |

Indizes: `(user_id, read_at, created_at)` für Zähler und Liste,
`(user_id, entity_type, entity_id)` für „gelesen beim Öffnen des Objekts“.

### Änderung an `user_notification_settings`

- Neue Spalte `channel` (string(16), `mail` oder `in_app`, Vorgabe `mail`).
- Primärschlüssel wird `(user_id, notification_type, channel)`.
- Bestehende Zeilen werden zu `mail` – eine bisherige Abmeldung bleibt eine Abmeldung
  von der Mail, die Glocke bleibt für diese Person an.
- Die Migration ist umkehrbar: `down()` löscht die `in_app`-Zeilen und stellt den alten
  Schlüssel wieder her.

## Kanal-Logik im `NotificationService`

### Neue Signatur

`notify()` bekommt einen zusätzlichen Pflicht-Parameter, ein Wertobjekt
`App\Services\Notifications\InAppMessage`:

```php
new InAppMessage(
    title: 'Neuer Kommentar: ' . $task->name,
    body: $comment,              // wird gekürzt und von HTML befreit
    link: '/tasks/' . $task->id,
    entityType: 'task',
    entityId: (int) $task->id,
);
```

Pflicht, damit kein Auslöser die Glocke vergisst. Der Konstruktor weist einen `link` ab,
der nicht mit `/` beginnt oder mit `//` beginnt (offene Weiterleitung).

Der Rückgabewert von `notify()` bleibt die Zahl eingereihter Mails – die bestehenden
Aufrufer und Tests werten ihn so aus.

### Prüfkette pro Kanal

1. Anlass verfügbar (Modul in Betrieb, Verwaltung nicht abgeschaltet) – sonst beide Kanäle aus.
2. Gemeinsame Filter: auslösende Person, inaktive Konten, `user_id <= 0`, doppelte Personen.
3. Nur für Mail: gültige E-Mail-Adresse.
4. Abmeldung der Person für **diesen** Kanal (`user_notification_settings` mit `channel`).

Die Glocken-Einträge eines Aufrufs werden in einem einzigen `INSERT` geschrieben. Scheitert
er, wird das mit `event` = `notification.in_app_failed` geloggt; der Mail-Versand läuft
trotzdem weiter (und umgekehrt).

### Einstellungen

`wantsNotification()`, `settingsFor()` und `storeSettings()` bekommen den Kanal dazu.
`settingsFor()` liefert `array<string, array{mail: bool, in_app: bool}>`.
`eligibleRecipients()` bleibt auf den Mail-Kanal bezogen (derzeit ohne Aufrufer).

### Erinnerungen

`NotificationReminderService` belegt den Anlass im Versandprotokoll (`claim()`) **vor**
`notify()`. Damit gilt die Sperre gegen doppelte Erinnerungen für beide Kanäle zusammen:
höchstens eine Mail und ein Glocken-Eintrag pro Fälligkeit.

### Glockentexte der Auslöser

| Anlass | Titel | Zeile | Link / Objekt |
|---|---|---|---|
| `TASK_ASSIGNED` | Neue Aufgabe: {Name} | „{Person} hat dich eingetragen“ | `/tasks/{id}` · `task` |
| `TASK_COMMENT` | Neuer Kommentar: {Name} | „{Person}: {Kommentar}“ | `/tasks/{id}` · `task` |
| `TASK_DUE_SOON` | Bald fällig: {Name} | „Fällig am {Datum}“ | `/tasks/{id}` · `task` |
| `EVENT_CREATED` | Neuer Termin: {Titel} | Datum und Uhrzeit; bei Serien „{n} Termine ab {Datum}“ | `/events/{id}` · `event` |
| `EVENT_CHANGED` | Termin geändert: {Titel} | Neue Zeit / neuer Ort | `/events/{id}` · `event` |
| `EVENT_CANCELLED` | Termin abgesagt: {Titel} | Datum des abgesagten Termins | `/events` · kein Objekt |
| `EVENT_NOTE` | Neue Bemerkung: {Titel} | „{Person}: {Bemerkung}“ | `/events/{id}` · `event` |
| `PROJECT_MEMBER_ADDED` | Neues Projekt: {Name} | „Du bist jetzt dabei“ | `/projects/{id}/members` · `project` |
| `SPONSORING_FOLLOW_UP_DUE` | Wiedervorlage: {Sponsor} | „Fällig am {Datum}“ | `/sponsoring/sponsors/{id}` · `sponsor` |

Die genauen Formulierungen dürfen bei der Umsetzung an die Betreffzeilen der Mails
angeglichen werden; Titel und Link müssen den Anlass eindeutig machen.

## Oberfläche

### Glocke in der Kopfzeile

- Partial `templates/partials/navigation/notification_bell.twig`, eingebunden in
  `user_menu.twig` links vom Mail-Badge; sichtbar für jede angemeldete Person.
- Symbol `bi-bell-fill`, rote Zahl wie beim Mail-Badge (`99+` ab 100, bei 0 ausgeblendet).
- Der Zähler kommt beim Rendern aus einer Twig-Funktion `notification_badge()`, analog
  zu `mail_badge()`.

### Dropdown

- Bootstrap-Dropdown, Inhalt wird beim Öffnen per `fetch` von `/notifications/recent`
  geladen (JSON, die letzten 10 Einträge). Kein Inhalt im initialen HTML, damit nicht
  jeder Seitenaufruf die Liste lädt.
- Pro Eintrag: Symbol je Bereich (Aufgaben, Termine, Projekte, Sponsoring), Titel,
  Zeile, relative Zeit („vor 5 Min.“), Punkt und Hervorhebung bei ungelesen.
- Kopf: „Alle als gelesen markieren“. Fuß: „Alle anzeigen“ → `/notifications`.
- Leerer Zustand: „Keine Benachrichtigungen“.
- Das Öffnen des Dropdowns allein markiert nichts als gelesen.
- Die Einträge werden im JavaScript per `textContent` gesetzt, nie per `innerHTML`.

### Seite `/notifications`

Vollständige Liste, neueste oben, 25 pro Seite, Filter „Nur ungelesene“, Knopf
„Alle als gelesen markieren“. Mobil als Liste, keine Tabelle.

### Gelesen-Logik

- Ein Eintrag verlinkt auf `/notifications/{id}/open` (GET). Der Endpunkt markiert ihn
  als gelesen und leitet mit 302 auf `link` weiter. GET, weil es ein normaler Link ist,
  den man auch mit Mittelklick in einem neuen Tab öffnen kann. Die Änderung betrifft nur
  den eigenen Lesestatus.
- Wird ein Objekt auf anderem Weg geöffnet (Aufgaben-Detail, Termin-Detail,
  Projekt-Mitglieder, Sponsor-Detail), markiert der Controller alle ungelesenen Einträge
  der angemeldeten Person zu `(entity_type, entity_id)` als gelesen.
- „Alle als gelesen“: `POST /notifications/read-all` mit CSRF-Token.

### Aktualisierung des Zählers

`public/js/notification-bell.js`: Abfrage von `/notifications/badge`
(`{"unread_count": n}`, `Cache-Control: no-store`) bei `focus` / `visibilitychange`,
höchstens alle 5 Sekunden, und alle 2 Minuten, solange der Tab sichtbar ist.

### Profil

Im Reiter „Benachrichtigungen“ pro Anlass zwei Schalter nebeneinander, Spaltenköpfe
„E-Mail“ und „Glocke“. Formularfelder `notifications[{type}][mail]` und
`notifications[{type}][in_app]`. Auf schmalen Bildschirmen stehen die beiden Schalter mit
eigener Beschriftung unter dem Anlass.

## Routen

Alle in der Gruppe der angemeldeten Personen, ohne zusätzliches Recht:

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/notifications` | Seite mit vollständiger Liste |
| GET | `/notifications/badge` | Zähler als JSON |
| GET | `/notifications/recent` | Letzte 10 als JSON |
| GET | `/notifications/{id}/open` | Gelesen markieren und weiterleiten |
| POST | `/notifications/read-all` | Alle gelesen markieren |

Jede Abfrage ist auf `user_id` der Sitzung beschränkt. Ein fremder oder unbekannter
Eintrag ergibt 404 (kein 403, damit sich fremde IDs nicht abtasten lassen).

## Aufräumen

`NotificationReminderService::pruneExpiredDispatchLog()` wird zu einem gemeinsamen
Aufräumschritt erweitert, der auch `user_notifications` bereinigt: gelesen und älter als
30 Tage, ungelesen und älter als 180 Tage. Läuft dort, wo das Versandprotokoll heute
schon bereinigt wird. Fehler werden geloggt (`event` = `notification.in_app_prune_failed`)
und brechen nichts ab.

## Logging

- `notification.enqueued` bekommt zusätzlich `in_app_count`.
- `notification.in_app_failed` bei gescheitertem Schreiben der Glocken-Einträge.
- `notification.in_app_pruned` (debug) mit Anzahl gelöschter Einträge.

## Tests

Zuerst geschrieben; gefiltert ausgeführt, die volle Suite einmal am Schluss.

- `NotificationServiceFeatureTest`: Mail und Glocke aus einem Aufruf; nur der
  abbestellte Kanal fällt weg; Verwaltungsschalter schaltet beide ab; ohne E-Mail-Adresse
  nur Glocke; Auslöserin und inaktive Konten bekommen nichts; doppelte Person nur ein
  Eintrag; `body` gekürzt und ohne HTML.
- `InAppMessageTest`: Link ohne `/`, mit `//` oder absolut wird abgewiesen.
- `NotificationTriggersFeatureTest`, `NotificationEventTriggersFeatureTest`: Jeder der
  sieben Auslöser legt Einträge mit passendem Titel, Link und Objekt an; abgesagter
  Termin verlinkt auf `/events`.
- `NotificationReminderFeatureTest`: zweiter Lauf legt keinen zweiten Glocken-Eintrag an.
- `ProfileNotificationSettingsFeatureTest`: beide Kanäle getrennt gespeichert; nur
  Abweichungen als Zeile; Bestandszeilen gelten als Mail.
- `UserNotificationControllerFeatureTest`: Zähler, Liste, Seite mit Filter und
  Seitenumbruch; `open` markiert und leitet weiter; fremder Eintrag 404; `read-all` ohne
  CSRF abgewiesen und mit CSRF wirksam nur für die eigene Person.
- Gelesen beim Öffnen von Aufgabe, Termin, Projekt-Mitgliedern und Sponsor: nur Einträge
  dieser Person zu diesem Objekt.
- Aufräumen: Grenzen 30 und 180 Tage, nach dem Muster von `NotificationDispatchLogPruneTest`.
- Schärfeprobe: Für die Beschränkung auf die eigene `user_id` und für die Kanal-Trennung
  wird der Produktivcode gezielt sabotiert und gezeigt, dass die Tests rot werden.

## Seed-Daten

- `seedNotificationSettings` setzt auch Abweichungen im Kanal `in_app`.
- Neu `seedUserNotifications`: pro aktivem Testkonto einige Einträge über verschiedene
  Anlässe, gemischt gelesen und ungelesen, unterschiedlich alt; die Admin-Person hat
  sicher ungelesene Einträge.
- `user_notifications` in der Zählung und in der Liste der vor dem Seeden geleerten
  Tabellen.
