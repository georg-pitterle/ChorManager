# Design: Navigations-Menü kapseln + Permission-Fähigkeit statt Magic Number

Datum: 2026-07-20
Status: Entwurf genehmigt (Brainstorming abgeschlossen)

## Ziel

Die Sichtbarkeitslogik des Navigationsmenüs ist aktuell schwer verständlich und
fehleranfällig: Parent-Dropdown-Gates werden getrennt von ihren Kind-Items
gepflegt (Desync-Bug-Klasse), Autorisierungslogik liegt verstreut in
`layout.twig` und fünf Partials, und die Stimmvertretungs-Schwelle ist als
Magic Number `role_level >= 40` an mehreren Stellen dupliziert.

Dieser Refactor kapselt das Menü in einen testbaren PHP-Service, leitet
Parent-Sichtbarkeit strukturell aus den Kindern ab und ersetzt die Magic Number
durch ein erstklassiges Rechte-Flag.

## Motivierende Bugs

1. **Registration-Bug (bereits gefixt):** Der `/registrations`-Menüpunkt wurde
   korrekt hinter `settings.modules.registration` gestellt, aber das
   übergeordnete `can_show_events`/`can_show_evaluations`-Gate blieb admin-only
   — ein normales Mitglied hatte keinen Menüweg zur Anmeldeseite. Ursache: die
   Parent-Gates sind manuell gepflegte ORs der Item-Gates.
2. **Latenter Backup-Bug (noch offen):** `can_show_admin` prüft
   `can_manage_master_data or can_manage_users or can_manage_mail_queue`, aber
   das Backup-Item im „Verwaltung"-Dropdown verlangt `can_manage_backups`. Eine
   Rolle mit *nur* Backup-Recht sieht das Dropdown gar nicht → kein
   Navigationsweg zum Backup-Item. Gleiche Bug-Klasse.

Beide werden durch die automatische Parent-Ableitung strukturell unmöglich.

## Nicht-Ziele (YAGNI)

- `hierarchy_level` bleibt als Spalte und in der Session (weiter gebraucht für
  Hierarchie-Schutz „darf keinen Höheren bearbeiten" und „≥ 80 =
  Admin-Implizitrechte"). Nur die *Capability*-Bedeutung von 40 wird migriert.
- Keine Änderung an `user_menu.twig` (Postfach-Badge, Profil, Logout) — hängt
  nicht an der Gate-Logik.
- Keine Umstellung der übrigen ~15 `can_manage_*`-Flags; nur das neue Flag kommt
  hinzu.

## 1. Permission-Schicht: `can_manage_own_voice_group`

### Was `role_level >= 40` bedeutet

Semantisch: „darf die eigene Stimmgruppe verwalten (Anwesenheit / Mitglieder im
Scope)". Es ist eine Fähigkeit, aktuell als Zahlvergleich ausgedrückt — der
einzige Ausreißer neben den sauberen `can_manage_*`-Bool-Flags. Rollen mit
diesem Level heute: Ersatzvertretung (40), Stimmvertretung (50), Chorleitung
(80).

### Migration (Phinx)

- Neue Spalte `roles.can_manage_own_voice_group` tinyint(1) NOT NULL DEFAULT 0.
- Backfill in derselben Migration:
  `UPDATE roles SET can_manage_own_voice_group = 1 WHERE hierarchy_level >= 40`.
  Damit übernehmen bestehende Rollen exakt das heutige `>= 40`-Verhalten — null
  Verhaltensänderung beim Deploy.

### SessionAuthService

Setzt `$_SESSION['can_manage_own_voice_group']` aus dem Flag, ODER-verknüpft über
alle Rollen des Users (gleiches Muster wie die anderen Flags). Admins
(`can_manage_users`) brauchen das Flag nicht gesetzt — an jeder Aufrufstelle
steht `can_manage_users OR can_manage_own_voice_group`, der Admin-Pfad läuft über
`can_manage_users`.

### Rollen-Admin

Neues Flag als editierbare Checkbox in `RoleController` + `roles/index.twig`,
konsistent zu den anderen Capabilities. Rechtevergabe bleibt bei User-Admins.
Damit wird die Fähigkeit erstklassig: eine Rolle kann das Recht ohne hohes
`hierarchy_level` tragen — genau die Entkopplung, die den latenten Fehler
beseitigt.

### Vier Aufrufstellen migrieren

Capability-Checks wechseln vom Levelvergleich auf das Flag; Hierarchie-Vergleiche
bleiben auf `role_level`:

| Stelle | vorher | nachher |
|---|---|---|
| `AttendanceScopeService::canManageOthers()` | `canManageUsers \|\| roleLevel >= 40` | `canManageUsers \|\| canManageOwnVoiceGroup` |
| `RoleMiddleware` (`allowVoiceGroupReps`) | `!canManageUsers && userLevel < 40` → 403 | Flag statt Levelvergleich |
| `UserController` (~Zeile 618) | `userLevel >= 40 && isInMyGroup` | `canManageOwnVoiceGroup && isInMyGroup` |
| Templates (evaluations/areas) | `can_manage_users or role_level >= 40` | Prädikat im NavigationBuilder (Abschnitt 2) |

Die exakten Aufrufstellen sind bei der Umsetzung per grep zu verifizieren, da
`role_level`/`hierarchy_level` auch für Hierarchie-Schutz verwendet wird — nur
die Capability-Checks migrieren.

## 2. NavigationBuilder + stumpfes Rendering

### NavigationContext (readonly Value Object)

Kapselt alles, was Sichtbarkeit + Active-State entscheidet: die Permission-Bools,
die aktiven Modul-Flags (`settings.modules.*`), den aktuellen Pfad und
optionalen nav-key. Factory
`NavigationContext::fromSession(array $session, array $settings, string $path, string $navKey = '')`
für Produktion; im Test direkt konstruierbar. Hält `$_SESSION` komplett aus dem
Builder heraus.

### NavigationBuilder::build(NavigationContext $ctx): array

Liefert einen fertigen Baum aus nur sichtbaren Knoten:

```php
[
    ['type' => 'link',  'label' => 'Dashboard', 'url' => '/dashboard',
     'icon' => 'bi-house', 'active' => false],
    ['type' => 'group', 'label' => 'Termine', 'icon' => 'bi-calendar-event',
     'active' => true, 'items' => [ /* nur sichtbare Kind-Links */ ]],
    // ...
]
```

Strukturell erzwungene Regeln:

- Jedes Item trägt ein **Sichtbarkeits-Prädikat**, ausgewertet gegen `$ctx`.
  Nicht sichtbare Items fallen aus dem Baum.
- **Gruppe sichtbar ⇔ mindestens ein sichtbares Kind** — automatisch berechnet,
  keine manuelle Parent-Gate-Pflege. Beseitigt die Desync-Bug-Klasse.
- **Active-State** berechnet der Builder aus `$ctx` (Logik, die heute in
  `nav_active()` und den `is_*_active`-Sets liegt). Gruppe `active` ⇔ ein Kind
  `active`.

### Menü-Definition

Lebt als *eine* deklarative Struktur im Builder: je Item Label, Icon, URL,
Sichtbarkeits-Prädikat, Match-Prefixes (für Active), optional nav-key. Neues Item
= eine Zeile an einer Stelle; Sichtbarkeit, Parent-Ableitung und Active-State
kommen automatisch.

Die vollständige Menü-Struktur (aus den heutigen Partials abgeleitet):

- **Dashboard** (Link, immer sichtbar)
- **Termine** (Gruppe)
  - Termine `/events` — immer sichtbar (jedes eingeloggte Mitglied)
  - Anwesenheit `/attendance` — `can_manage_attendance or can_manage_users`
  - Anmeldungen `/registrations` — `settings.modules.registration`
- **Bereiche** (Gruppe)
  - Mitgliederverwaltung `/users` — `can_manage_users or can_manage_own_voice_group`
  - Meine Projekte `/projects/members` — `can_manage_project_members and not can_manage_master_data`
  - Kassa `/finances` — `settings.modules.finance and (can_read_finances or can_manage_finances or can_manage_users)`
  - Budget `/budget` — `settings.modules.budget and (can_read_finances or can_manage_finances or can_manage_users or can_manage_budget)`
  - Sponsoring `/sponsoring` — `settings.modules.sponsoring and can_manage_sponsoring`
  - Repertoire `/song-library` — `can_manage_song_library`
  - Downloads `/downloads` — immer sichtbar
  - Meine Newsletter `/newsletters/archive` — `settings.modules.newsletter`
  - Newsletter `/newsletters` — `settings.modules.newsletter and can_manage_newsletters`
- **Auswertungen** (Gruppe)
  - Anwesenheitsquoten `/evaluations` — `can_manage_users or can_manage_own_voice_group`
  - Projektmitglieder `/evaluations/project-members` — immer sichtbar
  - Anmeldungen `/evaluations/registrations` — `settings.modules.registration`
- **Verwaltung** (Gruppe)
  - Projekte `/projects` — `can_manage_master_data or can_manage_users`
  - Rollen `/roles` — `can_manage_users`
  - Stimmgruppen `/voice-groups` — `can_manage_master_data or can_manage_users`
  - Termin-Typen `/event-types` — `can_manage_master_data or can_manage_users`
  - App-Einstellungen `/settings` — `can_manage_master_data or can_manage_users`
  - Mailversand `/admin/mail-queue` — `can_manage_mail_queue or can_manage_users`
  - Backup-Verwaltung `/backups` — `can_manage_backups`

Die „Downloads"-Gruppenzugehörigkeit und Trenner (`<hr>`) werden beim Rendern aus
Item-Metadaten abgeleitet; optionale Divider zwischen logischen Blöcken der
Verwaltung/Bereiche werden als leichte Marker im Item definiert (z. B.
`divider_before: true`), damit `menu.twig` logikfrei bleibt.

### Wiring

`Dependencies.php` setzt einen Twig-Global
`navigation = NavigationBuilder::build(NavigationContext::fromSession(...))` an
der Stelle, wo bereits `session`/`settings`/`current_path` als Globals gesetzt
werden.

### Rendering

Ein einziges Partial `partials/navigation/menu.twig` (~30 Zeilen, keine Logik)
loopt den Baum: `link` → Link, `group` → Dropdown mit Kindern, `active`-Klasse
und optionale Divider aus den Daten. `user_menu.twig` bleibt unverändert und
separat eingebunden.

## 2a. Beabsichtigte Verhaltensänderungen (kein reiner Refactor)

Die Auto-Ableitung der Parent-Sichtbarkeit deckt Items auf, die heute *immer
sichtbar* markiert sind, deren Parent-Gruppe aber admin-gated war — d. h. das
Menü versteckte für normale Mitglieder Einträge, deren Routen ohnehin für alle
eingeloggten Mitglieder erreichbar sind. Nach dem Refactor erscheinen sie
korrekt im Menü:

- **Bereiche → Downloads** (`/downloads`): Route liegt in der Basis-Protected-
  Group ohne RoleMiddleware, also für alle Mitglieder erreichbar. Heute vom
  admin-only `can_show_areas` versteckt.
- **Auswertungen → Projektmitglieder** (`/evaluations/project-members`): laut
  Routes „accessible for all logged-in users", keine RoleMiddleware. Heute vom
  admin-only `can_show_evaluations` versteckt.
- **Termine → Anmeldungen / Auswertungen → Anmeldungen**: bereits im
  vorherigen Nav-Fix behandelt; hier konsistent über die Auto-Ableitung.

Das Menü wird damit an die tatsächlichen Routen-Zugriffsrechte angeglichen (Nav
zeigt genau, was erreichbar ist). Diese Änderung ist gewollt und Teil des Ziels
„alten Müll loswerden". Kein Item wird sichtbar, dessen Route nicht ohnehin
erreichbar wäre.

## 3. Aufzuräumender alter Müll

Ersetzt/gelöscht durch Abschnitt 1+2:

- `layout.twig`: alle 4 `can_show_*`-Sets, alle 5 `is_*_active`-Sets und die 6
  `include(...)`-Aufrufe mit Bool-Durchreichung → ein
  `include('partials/navigation/menu.twig')`.
- `can_show_admin`-Backup-Lücke: verschwindet automatisch.
- Duplizierte `can_manage_users or role_level >= 40` (layout + areas +
  evaluations): nur noch *ein* Prädikat im Builder.
- Fünf Nav-Partials (`events`, `areas`, `admin`, `evaluations`, `dashboard`):
  Item-Definitionen wandern in den Builder, HTML-Struktur in `menu.twig` —
  Partials gelöscht.
- Magic Number 40 in allen vier PHP/Template-Capability-Checks → Flag.
- `nav_active()` Twig-Funktion: Match-Logik zieht in den Builder (PHP). Funktion
  wird entfernt, falls per grep kein Nicht-Nav-Aufruf mehr existiert; sonst
  bleibt sie.

## 4. Tests (TDD, echte Verhaltenstests)

### NavigationBuilder (isoliert über NavigationContext-Snapshots)

- Normales Mitglied (keine Flags): nur Dashboard + (bei `registration` an)
  Termine→Anmeldungen + Auswertungen→Anmeldungen + immer sichtbare Items
  (Bereiche→Downloads, Auswertungen→Projektmitglieder). Keine Admin-Gruppen.
- Stimmvertretung (`can_manage_own_voice_group`, kein `can_manage_users`): sieht
  Mitgliederverwaltung, Anwesenheit, Anwesenheitsquoten.
- Admin (`can_manage_users`): volle Struktur.
- **Backup-only** (nur `can_manage_backups`): sieht „Verwaltung"-Gruppe mit
  Backup-Item — der Test, der den latenten Bug einfängt.
- Modul-Toggles (`registration`/`finance`/`budget`/`sponsoring`/`newsletter` je
  an/aus): Item erscheint/verschwindet, Parent-Gruppe leitet korrekt ab.
- Active-State: Pfad `/registrations` → Item + Eltern-Gruppe `active`.
- Parent-Ableitung: Gruppe mit null sichtbaren Kindern fehlt komplett aus dem
  Baum.
- **Angeglichene Sichtbarkeit (Abschnitt 2a):** normales Mitglied sieht
  Bereiche→Downloads und Auswertungen→Projektmitglieder (heute fälschlich
  versteckt, Routen aber für alle erreichbar).

### Permission-Flag

- `SessionAuthService`: Rolle mit Flag → Session-Bool gesetzt; Admin ohne Flag
  besteht Capability-Checks via `can_manage_users`.
- Migrations-Backfill: Rollen mit `hierarchy_level >= 40` bekommen Flag = 1.
- Die drei migrierten Nicht-Nav-Stellen (`AttendanceScopeService`,
  `RoleMiddleware`, `UserController`): bestehende Tests bleiben grün + je ein
  Test für den Flag-Pfad ohne hohes Level.

### Render-Smoke

Ein Test rendert `menu.twig` mit einem Builder-Baum und prüft, dass sichtbare
Links im gerenderten HTML stehen (kein reiner Source-Grep).

### Umzug

Die bestehende `NavigationVisibilityFeatureTest` wird auf den Builder umgezogen
(Assertions gegen den Baum / gerendertes Menü statt gegen die alten
`can_show_*`-Booleans).

## 5. Seed

Bestehende Seed-Rollen decken die Flag-Werte über den Backfill ab. Optional eine
Rolle mit `can_manage_own_voice_group = 1` bei niedrigem `hierarchy_level` für
manuelle QA der Entkopplung.

## 6. Migration & Verifikation

- Eine Phinx-Migration (Spalte + Backfill). Ausführung:
  `ddev exec ./vendor/bin/phinx migrate`.
- Volle Testsuite grün, phpcs/twigcs sauber, LF-Check.
- Manuelle Browser-Verifikation: normales Mitglied sieht Termine→Anmeldungen im
  Menü; Backup-only-Rolle sieht Verwaltung→Backup.
