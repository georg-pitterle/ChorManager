# Konfigurierbare Namensdarstellung und verlinkte Mitgliedsnamen

Datum: 2026-07-27

## Ziel

1. In den Stammdaten wird eingestellt, ob Personennamen als `Vorname Nachname` oder
   `Nachname, Vorname` dargestellt werden. Die Einstellung gilt für alle Anzeigen,
   Dropdowns, Suchen und Sortierungen der Anwendung.
2. In den Mitglieder- und Verwaltungslisten ist der Name mit der Bearbeitung des
   Mitglieds verlinkt, sofern der Betrachter dieses Mitglied bearbeiten darf.

## Ausgangslage

- Namen werden heute an rund 74 Fundstellen inline zusammengesetzt, in beiden
  Reihenfolgen gemischt: `{{ u.first_name }} {{ u.last_name }}` und
  `{{ m.last_name }}, {{ m.first_name }}`.
- Es gibt keinen zentralen Helper und keinen Model-Accessor für den Anzeigenamen.
- Sortierung existiert doppelt: PHP `orderBy('last_name')->orderBy('first_name')`
  in Controllern und Queries, sowie `data-sort-*`-Attribute in Twig.
- Stammdaten sind der `AppSettingController` unter `/settings`, gespeichert in der
  Key-Value-Tabelle `app_settings`, in Twig global als `app_settings` verfügbar.
- Mitglieder werden nicht auf einer eigenen Seite bearbeitet, sondern über das Modal
  `#editUserModal{id}` auf `/users` (`templates/users/manage.twig`).
- Bearbeitungsrecht: `can_edit_users` global ODER Stimmgruppen-Vertreter
  (`can_manage_own_voice_group`) mit Schnittmenge der Stimmgruppen. Die Regel liegt
  heute verstreut in `UserController::index()`.

## Entscheidungen

| Frage | Entscheidung |
| --- | --- |
| Geltungsbereich der Einstellung | Global in den Stammdaten, keine persönliche Übersteuerung |
| Sortierung | Folgt der Einstellung (Anzeigereihenfolge = Sortierreihenfolge) |
| Ziel des Namenslinks | Deep-Link auf `/users?edit={id}`, öffnet das bestehende Modal |
| Umfang der Anzeigeumstellung | Alle Anzeigestellen zentral über Filter/Service |
| Umfang der Verlinkung | Nur Mitglieder- und Verwaltungslisten |
| Standardwert | `Vorname Nachname` |

## Architektur

### Verworfene Alternativen

- **Eloquent-Accessor `display_name` auf `User`**: greift nicht an den vielen Stellen,
  die Namen als Array aufbauen (`UserQuery`, `ProjectQuery`, `AttendanceController`),
  und löst die Sortierung nicht.
- **Nur ein Twig-Makro**: deckt die PHP-seitigen Namenszusammenbauten nicht ab
  (`SessionAuthService`, `NewsletterController`, `SponsoringDashboardController`).

### Gewählter Ansatz

`App\Services\NameFormatterService` als zentrale Stelle. Der Service liest den
Einstellungswert einmal pro Request und bietet:

- `format(?string $firstName, ?string $lastName): string`
- `formatPerson(User|array|object $person): string`
- `orderColumns(): array` liefert `['first_name', 'last_name']` oder
  `['last_name', 'first_name']` für alle `orderBy`-Ketten und `sortBy`-Aufrufe
- `sortKey(...)`: kleingeschriebener Sortierschlüssel für `data-sort-*`-Attribute

Registrierung im DI-Container in `src/Dependencies.php`, dort zusätzlich:

- Twig-Filter `person_name`, der `User`-Modelle, Arrays und `stdClass` akzeptiert
- Twig-Global `name_display_format` für die `data-sort-*`-Attribute

## Komponenten

### 1. Einstellung

- Neuer `app_settings`-Key `name_display_format` mit den erlaubten Werten
  `first_last` (Default) und `last_first`.
- Keine Phinx-Migration nötig, da `app_settings` eine Key-Value-Tabelle ist.
  Der Default wird als Fallback im Service aufgelöst.
- Dropdown in `templates/settings/index.twig`.
- Speicherung in `AppSettingController::save()` mit Whitelist-Normalisierung
  analog zu `normalizeMailQueueTriggerMode()`. Unbekannte Werte fallen auf
  `first_last` zurück.

### 2. Sortierung

Alle PHP-Sortierungen nach Personennamen werden auf den Service umgestellt:

- `AttendanceController::show()` (`sortBy(['last_name', 'first_name'])`)
- `EvaluationController` (Sortierspalten und `orderBy('first_name')`)
- `EventController` (zwei Fundstellen)
- `NewsletterController` (zwei Fundstellen)
- `RegistrationController` (`sortBy`)
- `TaskController` (zwei Fundstellen)
- `UserQuery`, `ProjectQuery`

In Twig werden die `data-sort-name`-, `data-sort-member_name`- und
`data-sort-assignee_name`-Attribute über denselben Filter erzeugt, damit die
clientseitige Sortierung in `public/js/table-engine.js` zur Serverreihenfolge passt.

### 3. Anzeige-Umstellung

Umgestellt werden (Anzeige ohne Link):

- Twig: `events/detail.twig`, `events/_audience_sources.twig`,
  `newsletters/create.twig`, `newsletters/edit.twig`, `newsletters/index.twig`,
  `newsletters/archive.twig`, `newsletters/preview.twig`, `newsletters/locked.twig`,
  `projects/members.twig`, `projects/tasks.twig`, `projects/task_detail.twig`,
  `partials/comments.twig`, `partials/history.twig`, `attendance/show.twig`,
  `evaluations/index.twig`, `evaluations/project_members.twig`,
  `registrations/detail.twig`, `sponsoring/sponsors/detail.twig`
- PHP: `SessionAuthService` (Session-Anzeigename), `NewsletterController`
  (Gesperrt-durch-Anzeige), `RegistrationController` (Bearbeiter-Anzeige),
  `SponsoringDashboardController` (Kontaktname), `EventController`
  (Zielgruppen-Label)

Bewusst nicht angefasst:

- E-Mail-Anreden `Hallo {{ user.first_name }}` in `templates/emails/*`
- Initialen-Avatare (`first_name|first ~ last_name|first`)
- Formularfelder und Eingabemasken (`auth/setup.twig`, `profile/index.twig`,
  Anlegen- und Bearbeiten-Modals)
- `TaskController`-Aktivitätstext, der bewusst nur den Vornamen nutzt

### 4. Verlinkung

- Neues Makro `templates/macros/person.twig` mit `member_link(user, can_edit)`.
  Bei `can_edit` wird `<a href="/users?edit={{ user.id }}">{{ user|person_name }}</a>`
  gerendert, sonst reiner Text.
- Eingesetzt in: `users/manage.twig`, `projects/members.twig`,
  `evaluations/index.twig`, `evaluations/project_members.twig`,
  `attendance/show.twig`.
- Neue `App\Policies\UserEditPolicy` mit `canEdit(array $actor, User $target): bool`.
  Sie bündelt die heute in `UserController::index()` verstreute Regel:
  globales `can_edit_users`, sonst Stimmgruppen-Vertreter mit Schnittmenge der
  Stimmgruppen. Archivierte Mitglieder sind nicht verlinkbar, da für sie kein
  Bearbeiten-Modal existiert.
- Templates erhalten je Zeile ein vorberechnetes `can_edit`-Flag aus dem Controller;
  die Policy wird nicht im Template aufgerufen.

### 5. Deep-Link

- `/users?edit={id}` wird in `UserController::index()` ausgewertet. Der Parameter
  wird auf Integer validiert, gegen die sichtbare Benutzerliste und die
  `UserEditPolicy` geprüft und als `open_edit_user_id` ans Template gegeben.
- `templates/users/manage.twig` schreibt den Wert in ein `data-`-Attribut,
  `public/js/users.js` öffnet daraufhin das passende Modal. Kein Inline-JavaScript.
- Ungültige oder nicht erlaubte IDs werden ignoriert; die Seite lädt normal.

## Tests

Testgetrieben, jeweils zuerst der fehlschlagende Test.

- `tests/Unit/Services/NameFormatterServiceTest.php`: beide Formate, Fallback bei
  fehlender und bei ungültiger Einstellung, leerer Vor- oder Nachname,
  `orderColumns()` je Format.
- `tests/Unit/Policies/UserEditPolicyTest.php`: globales Recht, Stimmgruppen-Vertreter
  mit und ohne Schnittmenge, archiviertes Mitglied, eigener Datensatz.
- `tests/Feature/NameDisplayFormatFeatureTest.php`: `/users` rendert in beiden
  Formaten korrekt und die Sortierreihenfolge dreht mit.
- `tests/Feature/AppSettingFeatureTest.php` erweitern: Speichern des Formats und
  Ablehnung eines ungültigen Werts.
- `tests/Feature/UserEditDeepLinkFeatureTest.php`: `/users?edit={id}` setzt
  `open_edit_user_id` nur bei Bearbeitungsrecht; ohne Recht enthält das HTML
  keinen Namenslink.

## Seed-Daten

`name_display_format` wird in `DevSeedService` beim Seeden der App-Einstellungen
mit dem Wert `first_last` angelegt und im Seed-Report gezählt. Keine neuen
Tabellen, daher kein Eintrag in `resetSeedData()`.

## Qualitätstore

- `ddev composer phpcs` und bei Bedarf `ddev composer phpcbf`
- `ddev composer twigcs` und bei Bedarf `ddev composer twigcbf`
- `ddev exec ./vendor/bin/phpunit`
- Keine Phinx-Migration erforderlich

## Risiken

Rund 30 Dateien werden berührt. Hauptrisiko sind übersehene Namensstellen.
Gegenmaßnahme: abschließender Grep auf `first_name` und `last_name` in
`templates/` und `src/`, abgeglichen gegen die oben dokumentierte Liste der
bewusst ausgenommenen Fundstellen.

## Nicht im Umfang

- Persönliche Übersteuerung der Namensdarstellung im Profil
- Eine eigene Bearbeitungsseite `/users/{id}/edit`
- Hilfetexte unter `docs/`, da es für die Stammdaten insgesamt noch keine gibt
- Änderungen an E-Mail-Anreden und Initialen-Avataren
