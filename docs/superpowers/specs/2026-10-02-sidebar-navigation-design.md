# Seitenleiste mit Schnellsuche – Entwurf

Stand: 2026-10-02 · Status: freigegeben

## Ausgangslage

Die Hauptnavigation ist eine Bootstrap-Navbar mit sechs Oberpunkten und für Admins rund 25
Einträgen. Die Gruppen spiegeln die Entstehung der Module, nicht die Aufgaben:

- „Bereiche" sammelt zehn Einträge ohne gemeinsames Merkmal.
- Persönliches und Verwaltung stehen nebeneinander und heißen fast gleich (Meine Projekte /
  Projekte / Projektmitglieder, Meine Newsletter / Newsletter).
- „Anmeldungen" steht zweimal im Menü.
- Einfache Mitglieder sehen „Verwaltung" mit dem einzigen Eintrag „Dateien".

Hauptbetroffen sind Funktionäre und Admins, die viele Seiten täglich brauchen. Verglichen wurden
Top-Leiste verdichtet, Seitenleiste, Bereiche mit Unternavigation, Mega-Menü und Schnellsuche
(klickbares Modell: https://claude.ai/artifact/6XasgPGkfnP1aBmhaUBcFj). Gewählt: **Seitenleiste
mit Schnellsuche**.

## 1. Menüstruktur und Daten

`App\Navigation\NavigationBuilder` bleibt die einzige Quelle. Aus Dropdown-Gruppen werden
**Abschnitte** mit Überschrift. Die Regel „ein Abschnitt erscheint nur, wenn mindestens ein Eintrag
sichtbar ist" bleibt. Die Sichtbarkeitsprädikate der Einträge bleiben unverändert; es gibt keine
neuen Rechte. Jeder Eintrag bekommt eine Liste deutscher **Stichwörter** für die Suche. Die
Trennlinien-Logik (`section`, `divider_before`) entfällt.

Der Builder liefert: Start oben, die Abschnitte, Hilfe unten.

| Abschnitt | Einträge |
|---|---|
| *(oben)* | Start |
| Termine | Termine · Anmeldungen · Anwesenheit erfassen · Anwesenheitsquoten · Anmelde-Auswertung |
| Noten & Dateien | Probenmaterial · Dateien · Repertoire |
| Mitglieder & Projekte | Mitglieder · Projekte · Projektbesetzung · Projektübersicht |
| Finanzen | Kassa · Budget · Sponsoring |
| Kommunikation | Newsletter versenden · Newsletter-Archiv |
| Administration (einklappbar) | Rollen & Rechte · Stimmgruppen · Termin-Typen · App-Einstellungen · Mailversand · Backups |
| *(unten)* | Hilfe |

Umbenennungen – Menü, Seitentitel und Seitenüberschrift folgen dem neuen Namen:

| bisher | neu | Route |
|---|---|---|
| Dashboard | Start | `/dashboard` |
| Mitgliederverwaltung | Mitglieder | `/users` |
| Downloads | Probenmaterial | `/downloads` |
| Meine Projekte | Projektbesetzung | `/projects/members` |
| Projektmitglieder | Projektübersicht | `/evaluations/project-members` |
| Meine Newsletter | Newsletter-Archiv | `/newsletters/archive` |
| Newsletter | Newsletter versenden | `/newsletters` |
| Anwesenheit | Anwesenheit erfassen | `/attendance` |
| Anmeldungen (Auswertungen) | Anmelde-Auswertung | `/evaluations/registrations` |
| Rollen | Rollen & Rechte | `/roles` |
| Backup-Verwaltung | Backups | `/backups` |

URLs, Route-Namen und `navKey`s bleiben unverändert.

Hilfetexte: Die Klickpfade in `help/*/docs/*.md` werden auf die neuen Abschnitte und Namen
umgestellt (z. B. **Bereiche → Kassa** → **Finanzen → Kassa**, **Verwaltung → Rollen** →
**Administration → Rollen & Rechte**). Screenshots mit dem alten Menü bleiben bis zu einer
ausdrücklichen Anforderung bestehen.

## 2. Layout, Einklappen, Mobil

- **Kopfleiste** (fixiert, Gestaltung wie bisher): ☰-Knopf, Logo und Chorname, Suchknopf
  „Seite suchen … Strg K", Brief-Badge, Profilmenü. Keine Menüpunkte mehr.
- **Seitenleiste ab `lg` (≥ 992 px)**: fest links unter der Kopfleiste, ca. 15 rem, gleicher
  dunkler Verlauf, eigenständig scrollbar. Aktiver Eintrag in `--theme-primary`, mit
  `aria-current="page"`. Der Inhalt rückt nach rechts.
- **Einklappen am Desktop**: ☰ schaltet auf eine Symbolleiste (ca. 4 rem) mit Tooltips;
  Abschnittsüberschriften werden zu Trennlinien. Der Abschnitt Administration hat eine
  aufklappbare Überschrift, standardmäßig zu, immer offen, wenn die aktive Seite darin liegt.
  Beide Zustände liegen im `localStorage` (Zugriffe in try/catch).
- **Kein Springen beim Laden**: `public/js/navigation-state.js` wird im `<head>` ohne `defer`
  geladen und setzt die Klasse für den eingeklappten Zustand am `<html>`-Element, bevor gezeichnet
  wird (kein Inline-JavaScript).
- **Unter `lg`**: dieselbe Leiste als `offcanvas-lg`, von links; ☰ öffnet, Hintergrund oder ✕
  schließt. Kein Einklappen zur Symbolleiste. Der Navbar-Collapse entfällt.
- **Ohne JavaScript** ist die Leiste am Desktop voll nutzbar.
- **Druck**: Kopf- und Seitenleiste ausgeblendet.

Dateien: `templates/layout.twig`, neu `templates/partials/navigation/sidebar.twig` (ersetzt
`menu.twig`), `user_menu.twig` bleibt, Abschnitt „Seitenleiste" in `public/css/style.css`,
neu `public/js/navigation-state.js` und `public/js/navigation.js`.

## 3. Schnellsuche

- **Datenquelle ist die gerenderte Seitenleiste**: jeder Link trägt `data-nav-keywords`. Die Suche
  liest die Links beim Öffnen. Kein JSON, kein Endpunkt; gefunden wird nur, was die Rolle sehen
  darf.
- **Öffnen**: Strg+K bzw. ⌘+K, Suchknopf in der Kopfleiste, am Handy Lupe. In TinyMCE (iframe)
  bleibt Strg+K beim Editor.
- **Oberfläche**: Bootstrap-Modal, Eingabefeld und Trefferliste nach dem ARIA-Combobox-Muster;
  ↑ ↓ wählt, Enter öffnet, Esc schließt. Treffer zeigen Symbol, Namen und den Abschnitt; bei
  Stichwort-Treffern auch das Stichwort.
- **Suche**: ohne Groß-/Kleinschreibung und ohne Akzente, über Name, Abschnitt und Stichwörter.
  Reihenfolge: Name beginnt mit der Eingabe, Name enthält sie, Stichwort-Treffer.
- **Leeres Feld**: „Zuletzt besucht" (höchstens 5, aus dem `localStorage`), darunter alle Seiten nach
  Abschnitt. Gemerkt wird beim Laden der aktive Leisteneintrag; angezeigt nur, was noch in der
  Leiste steht.

Dateien: neu `templates/partials/navigation/search_modal.twig`, `public/js/navigation-search.js`.

## 4. Tests

- `NavigationBuilderFeatureTest` neu für die Abschnitte, vor dem Umbau: einfaches Mitglied,
  Stimmführer, Finanzleser/-verwalter mit und ohne Modul, Backup-Rolle (nur Administration →
  Backups), Admin vollständig, Aktiv-Markierung über Pfad und `navKey`; neu Reihenfolge und Titel
  der Abschnitte, leere Abschnitte fehlen, Start oben und Hilfe unten, Stichwörter an jedem
  Eintrag, Administration offen bei aktiver Seite darin.
- `NavigationMenuRenderFeatureTest`, `NavigationLayoutSeamFeatureTest`: Leiste mit `offcanvas-lg`,
  `aria-current`, `data-nav-keywords`, Such-Modal und Kopf-Skript im Layout, `menu.twig` entfernt.
- Tests, die den Builder-Quelltext ausschneiden: bei URL-Suche unverändert, bei Label-Suche nur
  das Label anpassen. Tests auf Seitentitel an die Umbenennungen anpassen.
- e2e: `steps/navigation.mjs` auf Offcanvas umstellen (Schutz gegen still leere Prüfungen
  bleibt). Neues Szenario `navigation-sidebar`: Strg+K → „kassabuch" → Enter öffnet Kassa;
  Sänger findet Kassa nicht; Einklappen und Administration überleben ein Neuladen; mobil öffnet ☰
  die Leiste und ein Link navigiert. Desktop- und Mobillauf.
- Keine Migration, keine Seed-Daten: nichts wird persistiert.

## Nicht Teil dieses Vorhabens

- Suche nach Inhalten (Mitglieder, Termine, Stücke, Dateien) oder Aktionen.
- Zusammenlegen von Auswertungen in Tabs der Fachseiten.
- Neue Screenshots für die Hilfe.
- Speichern des Leistenzustands im Benutzerprofil.
