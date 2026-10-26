# Stimmgruppen-Listen: eigene Arbeitslisten der Stimmvertretungen

Stand: 2026-10-26 · Herkunft: Wunsch der Stimmvertretungen, für ein Projekt abzuhaken, wer den
Mitgliedsbeitrag bezahlt hat

## Anlass

Die Stimmvertretungen wollen nachhalten, wer aus ihrer Stimmgruppe für ein Projekt den
Mitgliedsbeitrag bezahlt hat. Im Gespräch wurde daraus etwas Allgemeineres: ein eigener Bereich
für die Stimmvertretungen, in dem sie **selbst formulierte Fragen** zu ihren Mitgliedern
abhaken oder notieren können. Eine Art Notizzettel pro Mitglied. Der Mitgliedsbeitrag ist dann
eine Spalte unter vielen („Beitrag bezahlt“, „Noten erhalten“, „Kleidergröße“).

Verworfen wurden dabei zwei Alternativen, damit sie nicht neu aufkommen:

- ein reines „Beitrag bezahlt“-Häkchen an der Projektzuordnung: ein schmaler Sonderfall, der
  nur eine Frage beantwortet;
- eine offizielle Projekt-Checkliste, deren Punkte die Projektverwaltung vorgibt: Die
  Stimmvertretungen wollen eigene Fragen stellen, nicht vorgegebene abarbeiten. Sie bleibt als
  mögliche spätere, chorweite Ergänzung (siehe „Offen für später“).

## Ziele

1. Eine Stimmvertretung legt für ihre Stimmgruppe **Listen** an, optional bezogen auf ein
   Projekt.
2. Eine Liste hat **selbst definierte Spalten** in zwei Arten: **Häkchen** und **Notiz**
   (freier Text).
3. **Zeilen sind die Mitglieder** der Stimmgruppe, mit Projektbezug nur die Projektmitglieder
   der Stimmgruppe. Neue Mitglieder erscheinen ohne Zutun.
4. Häkchen halten fest, **wer wann** abgehakt hat.
5. Pro Häkchen-Spalte sieht man den **Fortschritt** („18 / 30“) und kann auf „offen“ filtern.

## Nicht-Ziele

- Keine weiteren Spaltenarten (Auswahl, Betrag, Datum). Georg: „Häkchen und Notiz fürs Erste.“
- Keine Vorlagen und kein Kopieren von Listen. Siehe „Offen für später“.
- Keine Verknüpfung mit dem Finanzmodul.
- Keine Benachrichtigungen, kein Newsletter-Filter, kein Export.
- Kein Hilfethema. Laut `AGENT.md` entsteht es erst auf ausdrückliche Anforderung.

## Anti-Ziele (wäre ein Fehlschlag, auch wenn es technisch läuft)

- **Mitglieder sehen irgendetwas davon**: nicht im Profil, nicht in einer Auswertung, nicht
  über eine erratbare URL. Georg: „die Mitglieder selbst sehen nichts.“
- **Eine Stimmvertretung sieht die Listen einer fremden Stimmgruppe**, auch nicht durch
  Hochzählen einer Id.
- **Die Listen hängen an der Person**, sodass sie beim Wechsel der Stimmvertretung verloren
  gehen.
- **Notizinhalte landen im Log.** Es sind Notizen über Personen.

---

## Was Georg entschieden hat

- Eigener Bereich für die Stimmvertretungen, nicht Teil der Projektverwaltung.
- Die Fragen formulieren die Stimmvertretungen selbst.
- Mitglieder sehen nichts.
- Die Liste **gehört der Stimmgruppe**, nicht der einzelnen Stimmvertretung. Mehrere
  Stimmvertretungen derselben Stimmgruppe arbeiten gemeinsam daran, und die Nachfolge findet
  alles vor.
- **Teilstimmen** gehören zur Liste ihrer Stimmgruppe. Sopran 1 und Sopran 2 stehen also in der
  Sopran-Liste.
- **Nur die Stimmvertretungen** sehen die Listen. Die Administration sieht sie nicht.
- Spaltenarten: Häkchen und Notiz.
- Zugriff über ein **eigenes Recht** „Stimmgruppen-Listen führen“, nicht über „Eigene
  Stimmgruppe verwalten“.

## Umsetzung

### Wer Zugriff hat

Zugriff auf die Listen einer Stimmgruppe hat, wer **beides** erfüllt:

- das **neue Recht** `can_keep_voice_group_lists` („Stimmgruppen-Listen führen“), und
- die Mitgliedschaft in genau dieser Stimmgruppe (`voice_group_ids` der Sitzung).

**Warum ein eigenes Recht** (Georg, nach dem Hinweis des Prüfers): `can_manage_own_voice_group`
haben nicht nur die Stimmvertretungen, sondern auch Admin, Vorstand, Kassier und Chorleitung
(Seed-Rollen, Einrichtung in `AuthController`, Migrationen `20260720120000` und
`20260902120000`). Wer davon selbst in einer Stimmgruppe singt, hätte deren Listen gesehen.

Das Recht wird angelegt wie die übrigen Rollenrechte: Spalte auf `roles` per Migration,
`RolePermissionCatalog` (Gruppe mit den Stimmgruppen-Rechten), `Role`, `RoleController`,
Rollenseite, Sitzungs-Schnappschuss beim Anmelden. Bestehende Rollen bekommen es **nicht**:
Die Migration setzt es überall auf 0, und auch die erste Admin-Rolle bei der Einrichtung
bekommt es nicht. Die Administration vergibt es bewusst. In den Seed-Daten haben es
Stimmvertretung und Ersatzvertretung.

Es gibt **keinen Weg darüber hinaus**. `can_manage_users`, `can_manage_own_voice_group`,
`can_manage_attendance_all` und alle anderen Rechte öffnen die Listen nicht. Eine
Administratorin, die sich selbst das Recht gibt und in die Stimmgruppe einträgt, sieht sie
natürlich. Das ist in Ordnung, denn genau dann führt sie die Liste.

„Die Administration sieht sie nicht“ meint die Anwendung. Datenbank-Backups enthalten die
Listen wie alle anderen Daten auch; ein eigener Schutz dafür (Ausnehmen, Verschlüsseln) ist
nicht vorgesehen. *(Meine Entscheidung.)*

Lesen und Schreiben sind dasselbe Recht. Ein reines Leserecht gibt es nicht, wie bei der
Projektbesetzung (`ProjectMemberPolicy`).

Die Prüfung gehört in eine eigene Policy (Sitzung kommt von außen, wie bei den bestehenden
Policies, siehe `tests/Unit/Policies/PoliciesReceiveTheSessionTest`). Jeder Zugriff über eine
Id (Liste, Spalte, Eintrag) wird über die Stimmgruppe der Liste geprüft. Fehlt der Zugriff,
antwortet die App wie bei anderen fremden Objekten auch. Ob das 403 oder 404 ist, entscheidet
der Builder nach dem bestehenden Muster. Wichtig ist nur, dass sich die Antwort für „gibt es
nicht“ und „fremde Stimmgruppe“ nicht unterscheidet, damit sich nichts erraten lässt.

### Daten

Drei Tabellen (Namen sind ein Vorschlag, Englisch nach `instructions/naming.md`):

- `voice_group_lists`
  - `voice_group_id` (FK, CASCADE)
  - `title` (Pflicht, ≤ 255)
  - `project_id` (optional, FK, **SET NULL**: Projekte lassen sich heute über die Oberfläche
    nicht löschen; geschieht es doch, bleibt die Liste mit allen Notizen erhalten und zeigt
    danach die ganze Stimmgruppe)
  - `archived_at` (optional)
  - `created_by_user_id` (FK, SET NULL), Zeitstempel
- `voice_group_list_columns`
  - `list_id` (FK, CASCADE)
  - `label` (Pflicht, ≤ 255)
  - `type` (`checkbox` | `note`)
  - `sort_order`
- `voice_group_list_entries`
  - `column_id` (FK, CASCADE), `user_id` (FK auf `users`, CASCADE), eindeutig
    `(column_id, user_id)`
  - `checked_at` (datetime, optional; nur für `checkbox`)
  - `note` (Text, optional; nur für `note`; Länge begrenzt, ca. 1000 Zeichen, genauer Wert nach
    Builder)
  - `updated_by_user_id` (FK, SET NULL), Zeitstempel

Ein Eintrag existiert nur, wenn etwas gesetzt ist. Ein Häkchen zurücknehmen oder eine Notiz
leeren löscht die Zeile. So ist eine neue Spalte automatisch für alle offen.

Die Migration läuft über Phinx (`/phinx-migration`). Ihr `down()` bricht ab, solange Daten in
den Tabellen liegen, nach dem Muster von `20261023121000_add_sponsoring_deliverables`.

### Welche Mitglieder eine Liste zeigt

- **Ohne Projekt:** alle **aktiven** Mitglieder (`is_active = 1`), die über `user_voice_groups`
  zur Stimmgruppe gehören, gleich welche Teilstimme.
- **Mit Projekt:** dieselben, eingeschränkt auf die Mitglieder des Projekts (`project_users`).
- Die Stimmvertretung selbst steht mit in der Liste, wenn sie dazugehört.
- Wer in zwei Stimmgruppen ist, steht in beiden Listen, unabhängig voneinander.

**Mitglieder, die herausfallen** (archiviert, aus der Stimmgruppe oder dem Projekt entfernt):
Ihre Einträge bleiben in der Datenbank, werden aber nicht angezeigt und nicht mitgezählt. Kommen
sie zurück, ist ihr Stand wieder da. *(Meine Entscheidung: Das Gegenteil, Löschen beim
Herausfallen, würde bei einem versehentlich entfernten Mitglied die Notizen vernichten.)*
Endgültig gelöscht werden die Einträge nur mit dem Mitglied selbst (CASCADE).

**Beschreibbar ist nur, was angezeigt wird.** Ein Eintrag wird nur für ein Mitglied angenommen,
das gerade als Zeile in der Liste steht. Bei einer Liste mit Projektbezug ist ein Mitglied der
Stimmgruppe, das nicht im Projekt ist, also abgewiesen. *(Meine Entscheidung: Die Schreibregel
ist damit dieselbe wie die Anzeigeregel, und es entstehen keine Einträge, die niemand sieht.)*

Sortierung der Zeilen: nach Teilstimme, darin nach Name. Die Reihenfolge der Stimmgruppen
folgt der Vorgabe Sopran, Alt, Tenor, Bass, Untergruppen alphabetisch.

### Oberfläche

**Navigation.** Ein neuer Eintrag „Stimmgruppen-Listen“ im Bereich „Mitglieder & Projekte“,
sichtbar mit `can_keep_voice_group_lists` (`src/Navigation/NavigationBuilder.php`).

**Übersicht** (`/voice-group-lists`)
- Die Listen der eigenen Stimmgruppen, gruppiert nach Stimmgruppe, falls es mehrere sind.
- Archivierte Listen stehen getrennt darunter, aufklappbar oder als eigener Filter.
- Knopf „Neue Liste“: Titel, Stimmgruppe (Auswahl nur, wenn es mehrere gibt), optional
  Projekt. Angeboten werden dabei die Projekte, in denen mindestens ein Mitglied der
  Stimmgruppe ist.

**Liste** (`/voice-group-lists/{id}`)
- Tabelle: eine Zeile pro Mitglied (Name, Teilstimme), eine Spalte pro selbst angelegter Spalte.
- Häkchen-Zelle: ein Klick setzt oder nimmt das Häkchen. Ein Tooltip zeigt, wer es wann gesetzt
  hat.
- Notiz-Zelle: zeigt den Text (gekürzt) und lässt sich bearbeiten.
- Im Spaltenkopf jeder Häkchen-Spalte: Fortschritt „x / n“ und ein Filter „nur offene“.
- Am Handy über die bestehende Kartenansicht (`table-responsive-cards`).
- Spalten pflegen: hinzufügen, umbenennen, verschieben, löschen. Löschen nur nach Rückfrage,
  weil die Einträge der Spalte mitgehen. Die Art einer Spalte lässt sich nachträglich nicht
  ändern.
- Liste pflegen: umbenennen, Projektbezug ändern, archivieren bzw. zurückholen, löschen
  (mit Rückfrage).
- Archivierte Listen sind nur lesbar. *(Meine Entscheidung: Archivieren heißt „abgeschlossen“.
  Wer weiterarbeiten will, holt die Liste zurück.)*

**Häkchen und Notizen speichern ohne Neuladen** (Georg: „wenn ich 30 Mitglieder abhaken muss,
brauche ich nicht 30 Reloads mit 30-mal Scrollen“). Ein Klick auf ein Häkchen speichert sofort
im Hintergrund. Die Zelle, ihr Tooltip und der Fortschritt im Spaltenkopf aktualisieren sich,
und die Seite bleibt, wo sie ist. Dasselbe gilt fürs Speichern einer Notiz. Schlägt das
Speichern fehl, zeigt die Zelle den alten Stand und eine Meldung; nichts wird still verworfen.

Technisch bleiben es POST-Formulare mit CSRF-Schutz wie im Rest der App. Eine eigene JS-Datei
schickt sie mit `Accept: application/json` ab, der Server antwortet dann mit JSON statt mit
einer Weiterleitung (`RequestFormat::expectsJson`). Ohne JavaScript funktionieren sie weiter
mit Neuladen. Kein Inline-Script (`instructions/template-hygiene.md`).

Ist der Filter „nur offene“ aktiv, bleibt eine gerade abgehakte Zeile bis zum nächsten Laden
stehen (abgehakt dargestellt). *(Meine Entscheidung: Würde sie sofort verschwinden, rutschen
die Zeilen unter dem Mauszeiger weg, und ein versehentlicher Klick ließe sich nicht mehr
zurücknehmen.)*

Spalten- und Listenpflege (Anlegen, Umbenennen, Verschieben, Löschen) läuft selten und darf
neu laden.

### Protokollierung

Strukturierte Events nach `instructions/logging.md` für Anlegen, Archivieren und Löschen von
Listen und Spalten, mit Ids und Stimmgruppe. **Kein Notiztext und kein Spaltenname im Log**,
denn auch der Spaltenname kann etwas über Personen verraten („Gespräch wegen Fehlzeiten“).
Einzelne Häkchen und Notizen werden nicht protokolliert, wer was zuletzt geändert hat, steht
ohnehin am Eintrag.

### Seed-Daten

Nach `/dev-seed-completeness`: Für mindestens zwei Stimmgruppen je eine Liste mit Projektbezug
(„Beitrag bezahlt“ als Häkchen, „Kleidergröße“ als Notiz, teilweise ausgefüllt) und eine ohne
Projekt, dazu eine archivierte Liste. Die Seed-Rollen Stimmvertretung und Ersatzvertretung
bekommen `can_keep_voice_group_lists`, alle anderen nicht.

### Tests (vor der Umsetzung, TDD)

Schwerpunkt ist die Abschottung, denn genau dort wäre ein Fehler peinlich:

- Policy-Einheitstests: Recht und passende Stimmgruppe öffnen die Liste. Ohne das Recht, ohne
  die Stimmgruppe oder nur mit anderen Rechten (`can_manage_own_voice_group`,
  `can_manage_users`, `can_manage_attendance_all`) bleibt sie zu.
- Migration: Bestehende Rollen haben das neue Recht danach nicht, auch die Admin-Rolle nicht.
- Rollenseite: Das Recht lässt sich setzen und entfernen und landet nach dem Anmelden in der
  Sitzung.
- Feature-Tests über HTTP für jeden Schreib- und Leseweg: Eine Stimmvertretung aus einer
  fremden Stimmgruppe und ein einfaches Mitglied bekommen über direkte Ids weder Liste noch
  Spalte noch Eintrag zu sehen oder zu ändern. Ein Eintrag für ein Mitglied außerhalb der
  Stimmgruppe wird abgewiesen, auch wenn die Liste eigene ist.
- Zeilen: Teilstimmen sind enthalten, archivierte Mitglieder fehlen, und mit Projektbezug
  stehen nur Projektmitglieder in der Liste. Wer herausfällt und zurückkommt, hat seinen Stand
  wieder.
- Häkchen setzen und zurücknehmen, Notiz setzen und leeren (der Eintrag verschwindet).
  Archivierte Listen weisen Änderungen ab.
- Logs enthalten keinen Notiztext.
- Schärfeprobe: Die Prüfung auf die Stimmgruppe in der Policy wird probeweise entfernt, und
  die Tests müssen dann rot werden.

## Dem Builder überlassen

- Genaue Tabellen-, Routen- und Klassennamen
- 403 oder 404 bei fremden Objekten (Hauptsache einheitlich, siehe oben)
- Wie Notizen bearbeitet werden (Inline-Feld, Modal, eigene Seite)
- Wie Spalten verschoben werden (Pfeile oder Ziehen)
- Maximallänge der Notiz

## Offen für später

- Spalten aus einer anderen Liste übernehmen („wie beim letzten Projekt“)
- Weitere Spaltenarten (Auswahl, Betrag)
- Eine offizielle, chorweite Projekt-Checkliste für die Kassa, falls ein Gesamtüberblick über
  den Mitgliedsbeitrag gebraucht wird
