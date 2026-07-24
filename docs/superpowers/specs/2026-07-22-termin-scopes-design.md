# Design: Termin-Scopes (Zielgruppen)

**Datum:** 2026-07-22
**Status:** Genehmigt (Design)

## Ziel

Termine erhalten eine Zielgruppen-Schicht analog zu Newslettern. Statt einer
einzelnen `project_id` kann ein Termin mehrere additive Quellen ("Scopes")
definieren. Anwesenheit und Anmeldungen gelten nur für die betroffenen
Mitglieder. Sichtbarkeit und Kalender-Export leiten sich ebenfalls aus den
Scopes ab.

## Entscheidungen (aus Brainstorming)

- `events.project_id` wird **vollständig entfernt**; Projektmitgliedschaft wird
  eine Scope-Quelle unter mehreren.
- Unterstützte Quelltypen: `project_members`, `role`, `user`, `voice_group`
  (Stimmgruppe — neu gegenüber Newsletter).
- Quellen sind **additiv** (Vereinigung / Union).
- **Keine Quelle = alle aktiven Mitglieder** (entspricht heutigem
  `project_id = null`).
- Sichtbarkeit im Web: betroffene Nutzer **oder** Verwalter (`can_manage_users`).
- ICS-Kalender-Export: **nur betroffene** Nutzer (Verwalter-sieht-alles gilt
  hier nicht).

## Datenmodell

Neue Tabelle `event_audience_sources` (analog `newsletter_recipient_sources`):

| Spalte      | Typ            | Bemerkung                                            |
|-------------|----------------|-----------------------------------------------------|
| id          | PK             |                                                     |
| event_id    | FK → events    | `ON DELETE CASCADE`                                  |
| source_type | string         | `project_members` \| `role` \| `user` \| `voice_group` |
| reference_id| int            | Projekt-/Rollen-/User-/Stimmgruppen-ID              |

- Kein `timestamps`.
- Neues Model `App\Models\EventAudienceSource` mit Typkonstanten.
- Relation `Event::audienceSources()` (`hasMany`).
- `events.project_id` wird nach Datenmigration entfernt; `Event::project()`
  Relation und `project_id` aus `$fillable`/`$casts` entfernt.

## Service `EventAudienceService`

Analog zu `NewsletterRecipientService`. Verantwortlich für Auflösung und
Persistenz der Quellen.

- `getSources(Event): array<int, array{type:string, reference_id:int}>`
- `setSources(Event, array $sources): void` — ersetzt alle Quellen des Termins.
- `resolveEligibleUsers(Event): Collection<int, User>` — Vereinigung, leere
  Quellen ⇒ alle aktiven.
- `visibleEventsQuery(User): Builder` bzw. Filter für „Events, für die dieser
  Nutzer betroffen ist": Events **ohne** Quellen ODER mit passender Quelle
  (Projekt-/Rollen-/Stimmgruppen-Zugehörigkeit oder direkte User-Quelle).
  Genutzt für Web-Sichtbarkeit (nicht-Verwalter) und ICS-Export.

### `Event::eligibleUsersQuery(): Builder`

Bleibt als einzige Quelle der Wahrheit erhalten, damit alle bestehenden Aufrufer
unverändert funktionieren (`EvaluationController`, `RegistrationController` (3×),
`RegistrationReminderService`).

Neue Implementierung baut eine OR-Gruppe aus `whereHas` je Quelltyp:

```php
$query = User::where('is_active', 1);
$sources = $this->audienceSources; // eager/lazy geladen
if ($sources->isNotEmpty()) {
    $query->where(function ($q) use ($sources) {
        // project_members -> orWhereHas('projects', id in [...])
        // role            -> orWhereHas('roles', id in [...])
        // voice_group     -> orWhereHas('voiceGroups', id in [...])
        // user            -> orWhereIn('id', [...])
    });
}
return $query;
```

Leere Quellen ⇒ nur `is_active = 1` (= alle, wie bisher bei `project_id = null`).

## Sichtbarkeit / Zugriff

- **Web (Liste/Kalender/Detail):** betroffene Nutzer (in Scope) **oder** Nutzer
  mit `can_manage_users`. Ersetzt die projektbasierte Zugriffslogik in
  `EventController::index()`, `getAccessibleCalendarEventsForUser()` und
  `canAccessEvent()`.
- **ICS-Export (`exportCalendar`):** nur betroffene Nutzer über
  `visibleEventsQuery`. Der bisherige „Verwalter sieht alle"-Zweig entfällt für
  den Export.
- **Index-Projektfilter-Dropdown:** filtert auf Events mit `project_members`-
  Quelle für das gewählte Projekt (Subquery statt `where project_id`).
- **ICS-Beschreibung:** Zeile `Projekt: X` wird durch eine Zielgruppen-
  Zusammenfassung ersetzt (z. B. Projekt-/Rollen-/Stimmgruppennamen).

## UI — Termin-Formular

`templates/events/edit.twig`: das bisherige einzelne „Projekt (Optional)"-Select
wird durch einen Zielgruppen-Block ersetzt, aufgebaut wie
`templates/newsletters/edit.twig`:

- Checkbox-Gruppen: Projektmitglieder / Rollen / Stimmgruppen / Einzelnutzer.
- Vorhandene Quellen vorbelegen (wie Newsletter-Edit die `recipient_sources`
  gruppiert).
- Kein Inline-JS/CSS (Template-Hygiene). Bestehendes Newsletter-Quellen-JS-
  Muster wiederverwenden bzw. verallgemeinern; Assets lokal.
- `EventController` (create/edit-Rendering + Save) liefert `roles`,
  `voice_groups`, `users`, `projects` und die vorhandenen Quellen ans Template
  und persistiert über `EventAudienceService::setSources()`.

## Migration (Phinx)

1. Tabelle `event_audience_sources` anlegen (mit FK + Index auf `event_id`).
2. Datenmigration: für jedes Event mit gesetzter `project_id` eine
   `project_members`-Quelle (`reference_id = project_id`) anlegen. Events mit
   `project_id = null` erhalten **keine** Quelle (= alle, unverändert).
3. Spalte `events.project_id` entfernen.

Ausführung: `ddev exec ./vendor/bin/phinx migrate`. Ergebnis berichten.

## Seed-Daten (`DevSeedService`)

- `event_audience_sources` in `resetSeedData()` aufnehmen.
- Zähler im Seed-Report (`run()`) ergänzen.
- Model-Import ergänzen.
- Seed-Methode für Event-Zielgruppen: realistische Mischung — Termine mit
  Projekt-, Rollen-, Stimmgruppen-, gemischten und leeren (=alle) Scopes.
- In dependency-sicherer Reihenfolge in `run()` einhängen (nach Events, Rollen,
  Stimmgruppen, Projekten, Usern).
- Echten Dev-Seed-Lauf ausführen und Zähler prüfen.

## Tests (TDD)

Feature-Tests, jeweils zuerst fehlschlagend:

- Eligibility-Union je Quelltyp (`project_members`, `role`, `voice_group`,
  `user`).
- Leere Quelle ⇒ alle aktiven Mitglieder.
- Additive Kombination mehrerer Quellen (keine Duplikate).
- Sichtbarkeit: betroffene + Verwalter (Web), nur betroffene (ICS).
- Speichern/Ersetzen der Quellen über den Controller.
- Bestehende `eligibleUsersQuery`-/`project_id`-Tests auf das neue Modell
  migrieren.

Relevante Tests vor Abschluss ausführen und Ergebnis berichten.

## Blast-Radius / Anpassungen bei `project_id`-Entfernung

- `EventController`: index-Filter, Zugriff, ICS, Anzeige.
- Templates, die `event.project`/`project_id` verwenden: `events/index.twig`,
  `events/edit.twig`, ggf. `evaluations/*`, Anwesenheits-Ansichten
  (Projektname-Anzeige) — auf Zielgruppen-Anzeige umstellen bzw. entfernen.
- `NewsletterRecipientService::getEventAttendees()` bleibt unberührt (basiert auf
  `attendances`, nicht `project_id`).

## Nicht im Scope (YAGNI)

- Kein `event_attendees`-Quelltyp für Termine (zirkulär/ohne Nutzen).
- Keine Snapshot-Tabelle berechtigter Nutzer; Eligibility wird zur Laufzeit
  aufgelöst (im Gegensatz zum Newsletter-Empfänger-Snapshot).
