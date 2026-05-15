# Design: Umbau der Newsletter-Empfängerauswahl

**Datum:** 2026-05-13  
**Status:** Approved  
**Scope:** Erweiterung der Empfängerauswahl für Newsletter von „Projektmitglieder oder Veranstaltungsteilnehmer" auf vier kombinierbare Typen mit Deduplizierung und Live-Zähler.

---

## Ausgangslage

Newsletter sind derzeit fest an ein Projekt gebunden (`newsletters.project_id NOT NULL`). Die Empfänger werden bei jedem Speichern dynamisch aufgelöst aus exakt einer von zwei Quellen:

- **Projektmitglieder:** alle aktiven User mit `project_user.project_id = newsletters.project_id`
- **Veranstaltungsteilnehmer:** alle aktiven User mit `attendances.event_id = newsletters.event_id AND status = 'present'`

Die Auswahl erfolgt über ein optionales `event_id`-Feld auf `newsletters`. Beide Typen sind gegenseitig ausschließend.

---

## Ziel

Vier kombinierbare Empfängertypen:

| Typ                   | Beschreibung |
|-----------------------|--------------|
| `project_members`     | Alle aktiven Mitglieder eines oder mehrerer Projekte |
| `event_attendees`     | Alle aktiven Teilnehmer einer Veranstaltung (status = 'present') |
| `role`                | Alle aktiven Nutzer systemweit mit einer bestimmten Rolle |
| `user`                | Einzelne explizit ausgewählte aktive Nutzer systemweit |

Alle Typen können in beliebiger Kombination gewählt und mehrfach ausgewählt werden (z. B. Rolle A + Rolle B + einzelne Person C). Pro Nutzer wird genau eine E-Mail verschickt, unabhängig von der Anzahl der Quellen, die ihn erfassen.

---

## Datenbankschema

### Neue Tabelle `newsletter_recipient_sources`

```sql
CREATE TABLE newsletter_recipient_sources (
    id            INT UNSIGNED AUTO_INCREMENT NOT NULL PRIMARY KEY,
    newsletter_id INT UNSIGNED NOT NULL,
    source_type   ENUM('project_members','event_attendees','role','user') NOT NULL,
    reference_id  INT UNSIGNED NOT NULL,
    CONSTRAINT fk_nrs_newsletter
        FOREIGN KEY (newsletter_id) REFERENCES newsletters(id) ON DELETE CASCADE,
    INDEX idx_nrs_newsletter (newsletter_id)
);
```

`reference_id` enthält je nach `source_type`:

| `source_type`     | `reference_id` |
|-------------------|----------------|
| `project_members` | `project_id`   |
| `event_attendees` | `event_id`     |
| `role`            | `role_id`      |
| `user`            | `user_id`      |

### Migration bestehender Daten

Die Phinx-Migration führt folgende Schritte durch:

1. Erstellt `newsletter_recipient_sources`.
2. Für jeden Newsletter mit `event_id IS NOT NULL`: fügt eine Zeile `(type='event_attendees', reference_id=event_id)` ein.
3. Für jeden Newsletter: fügt eine Zeile `(type='project_members', reference_id=project_id)` ein (entspricht dem bisherigen Standardverhalten).
4. Entfernt die Spalte `newsletters.event_id`.

### Unveränderte Tabellen

- `newsletter_recipients` – speichert die aufgelösten Empfänger-IDs mit Status; bleibt unverändert.
- `newsletters` – verliert nur `event_id`, alle anderen Felder bleiben.

---

## Service-Schicht

### `NewsletterRecipientService` – Änderungen

#### Entfernte Methoden / Signaturen
- `resolveRecipients(int $projectId, ?int $eventId)` → wird ersetzt

#### Neue / geänderte Methoden

**`resolveRecipients(Newsletter $newsletter): array<int>`**  
Liest alle `newsletter_recipient_sources` für den Newsletter und kombiniert die Ergebnisse:

```
foreach source in sources:
    match source.type:
        project_members  → getProjectMembers(source.reference_id)
        event_attendees  → getEventAttendees(source.reference_id)
        role             → getUsersByRole(source.reference_id)
        user             → getActiveUser(source.reference_id)

return array_unique(mergedUserIds)
```

Inaktive Nutzer (`is_active = 0`) werden in allen Pfaden ausgeschlossen. Ungültige `reference_id`s (gelöschte Entität) werden still übersprungen.

**`getUsersByRole(int $roleId): array<int>`** (neu)  
Gibt alle aktiven `user_id`s zurück, die via `user_roles` mit `role_id` verknüpft sind.

**`getActiveUser(int $userId): array<int>`** (neu)  
Gibt `[$userId]` zurück wenn der User existiert und aktiv ist, sonst `[]`.

**`setSources(Newsletter $newsletter, array $sources): void`** (neu)  
Löscht alle `newsletter_recipient_sources` für den Newsletter, fügt die neuen Einträge ein, ruft dann `resolveRecipients()` + `setRecipients()` auf.

Struktur von `$sources`:
```php
[
    ['type' => 'project_members', 'reference_id' => 3],
    ['type' => 'role',            'reference_id' => 2],
    ['type' => 'user',            'reference_id' => 42],
    ['type' => 'event_attendees', 'reference_id' => 7],
]
```

**`getSources(Newsletter $newsletter): array`** (neu)  
Gibt die gespeicherten Quellen als Array zurück (für Formular-Vorausfüllung).

`getRecipients()` und `setRecipients()` bleiben unverändert.

---

## Controller-Schicht

### `NewsletterController`

**`store()` und `update()`:**

Validierung:
- `sources` muss ein nicht-leeres Array sein (mindestens eine Quelle)
- Jede Quelle muss `type` (gültiger Enum-Wert) und `reference_id` (positive Integer) enthalten
- Bei `source_type = project_members`: `reference_id` muss ein existierendes Projekt sein
- Bei `source_type = role`: `reference_id` muss eine existierende Rolle sein
- Bei `source_type = user`: `reference_id` muss ein existierender User sein
- Bei `source_type = event_attendees`: `reference_id` muss eine existierende Veranstaltung sein

Nach erfolgreicher Validierung: `setSources()` aufrufen, das intern auch `resolveRecipients()` + `setRecipients()` durchführt.

Das bisherige `event_id`-Feld entfällt aus der Validierung.

**Neuer Endpunkt `POST /newsletters/resolve-recipients-preview`:**
- Berechtigung: `can_manage_newsletters`
- Input: `sources`-Array (gleiche Struktur wie oben)
- Verarbeitung: `resolveRecipients()` ohne zu speichern
- Output: `{"count": 42}` (HTTP 200) oder `{"errors": [...]}` (HTTP 422 bei ungültigem Input)
- Kein CSRF-Problem da POST mit eigenem CSRF-Token

---

## Frontend / Templates

### `create.twig` und `edit.twig`

Das bisherige Veranstaltungs-Dropdown wird durch eine **Empfänger-Konfiguration**-Sektion ersetzt:

#### Struktur der neuen Sektion

```
┌─ Empfänger ────────────────────────────────────────────┐
│                                                         │
│  ☑ Projektmitglieder   [Projekt 1] [+ Projekt hinzufügen] │
│                                                         │
│  ☐ Veranstaltungsteilnehmer  [Veranstaltung wählen ▼]  │
│                                                         │
│  ☐ Rollen              ☐ Vorstand  ☐ Stimmführer  ...  │
│                                                         │
│  ☐ Einzelne Mitglieder  [Suchfeld: Name eingeben...]   │
│                                                         │
│  Empfänger: [Badge: 42] (aktualisiert sich live)       │
└─────────────────────────────────────────────────────────┘
```

#### Formularfelder

```
sources[0][type]=project_members&sources[0][reference_id]=3
sources[1][type]=role&sources[1][reference_id]=2
sources[2][type]=user&sources[2][reference_id]=42
```

#### Live-Zähler

- JavaScript-Handler: bei jeder Änderung der Auswahl wird ein `POST /newsletters/resolve-recipients-preview` mit dem aktuellen Formularstand abgefeuert.
- Das Badge zeigt den zurückgegebenen `count` an.
- Bei laufendem Request: Spinner anzeigen.
- Bei Fehler (Netzwerk o. Ä.): Badge zeigt „–".
- Debounce: 300 ms um redundante Requests zu vermeiden.

### `index.twig` – Filter

Neuer Filter **„Empfängertyp"**: Dropdown/Chips mit den vier Typen. Wählt der User einen Typ, werden nur Newsletter angezeigt, die mindestens eine `newsletter_recipient_sources`-Zeile dieses Typs besitzen.

Umsetzung: `JOIN newsletter_recipient_sources WHERE source_type = ?` in der Newsletter-Index-Query (oder separates `whereHas` mit Eloquent).

---

## Fehlerbehandlung

| Situation | Verhalten |
|-----------|-----------|
| Leere `sources`-Konfiguration beim Speichern | Validierungsfehler, HTTP 422, Newsletter wird nicht gespeichert |
| Ungültige `reference_id` (gelöschte Entität) beim Senden | Quelle wird beim Auflösen übersprungen; kein Fehler; Zähler für diese Quelle = 0 |
| AJAX-Endpunkt mit ungültigem Input | HTTP 422 + JSON-Fehlerobjekt |
| Alle Quellen lösen auf 0 Empfänger auf | Newsletter kann gespeichert, aber nicht gesendet werden (bestehende Validierung in `NewsletterService::validateForSending()`) |

---

## Tests

Neue und erweiterte Tests in `tests/Feature/NewsletterFeatureTest.php` (und ggf. eigenem `NewsletterRecipientServiceTest`):

- Jeder Quelltyp löst korrekte User auf
- Deduplizierung: User in mehreren Quellen erscheint nur einmal in `newsletter_recipients`
- Inaktive User werden von allen Quelltypen ausgeschlossen
- Ungültige `reference_id` wird still übersprungen
- `setSources()` löscht alte Quellen korrekt und schreibt neue
- `getSources()` gibt gespeicherte Quellen korrekt zurück
- AJAX-Endpunkt gibt korrekten Count zurück
- AJAX-Endpunkt mit leerem `sources` gibt HTTP 422 zurück
- Validierungsfehler bei leerem `sources` beim Erstellen/Speichern
- Index-Filter nach Empfängertyp gibt korrekte Ergebnisse zurück
- Datenmigration: bestehende `event_id`-Daten werden korrekt überführt

---

## Offene Punkte / Entscheidungen

Keine. Alle relevanten Design-Fragen wurden im Brainstorming geklärt.

---

## Abgrenzung

- Opt-out-Mechanismus für Empfänger: **nicht Teil dieses Umbaus**
- Berechtigungssystem für Empfänger-Typen (z. B. nur Admins dürfen Rollen wählen): **nicht Teil dieses Umbaus**
- Änderungen an `newsletter_templates`: **nicht betroffen**
- Locking-Mechanismus: **nicht betroffen**
