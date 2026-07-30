---
name: create-help-topic
description: >
  Workflow for creating a new help topic (Hilfethema) in ChorManager's `/help` system.
  Use this skill whenever the user wants to document a feature, write a how-to guide,
  create screenshots for documentation, or add a new page under `/help`. Trigger on
  phrases like "hilfe-thema", "help topic", "Dokumentation erstellen", "Anleitung schreiben",
  "Screenshots für Doku", or any request to document a ChorManager module or feature.
  This skill is mandatory when creating or updating help/ markdown files with screenshots.
---

# Hilfe-Thema erstellen

Vollständiger Workflow zum Erstellen eines neuen Hilfe-Themas inkl. Screenshots.

## Pflicht-Reihenfolge

1. **Seed-Lauf** — immer zuerst, vor Screenshots
2. **Screenshot-Skript** schreiben oder anpassen
3. **Screenshots aufnehmen**
4. **Markdown-Datei** schreiben
5. **Ergebnis prüfen** unter `/help/{slug}`

---

## Verzeichnisstruktur

Jedes Hilfe-Thema liegt in einem eigenen Ordner unter `help/`:

```
help/
  {slug}/
    docs/
      {slug}.md               ← Haupt-/Oberthema
      {slug}-{bereich}.md     ← Unterthemen (optional)
    screenshots/
      01-dashboard.png
      02-liste.png
      ...
    scripts/
      screenshot.js           ← Playwright-Skript
```

Referenzbeispiel: `help/sponsoring/`

---

## Schritt 1 – Thema klären

Vor dem Start folgende Punkte klären (wenn nicht bereits aus dem Kontext bekannt):

- **Slug**: Wie heißt das Thema in der URL? (z. B. `members`, `finance`)
  - Nur Kleinbuchstaben, Ziffern, Bindestriche; keine Umlaute
  - **Immer englisch**, auch wenn das Feature/Modul einen deutschen Namen hat (z. B. `events` statt `termine`, `members` statt `mitglieder`). Das gilt für den Ordnernamen `help/{slug}/`, alle `docs/*.md`-Dateinamen und alle Screenshot-Dateinamen — nur der Markdown-**Inhalt** ist deutsch.
  - Oberthema = `{slug}.md`; Unterthema = `{slug}-{bereich}.md` (Bereich ebenfalls englisch, z. B. `events-attendance`, nicht `termine-anwesenheit`)
- **Scope**: Welche Seiten/Ansichten sollen dokumentiert werden?
- **Berechtigung**: Welches Recht steuert die Sichtbarkeit des Moduls?
  - Berechtigung **nie** als Rollennamen nennen (z. B. nie "Admin" oder "Vorstand")
  - Stattdessen das Recht mit seinem Label aus `templates/roles/index.twig`, z. B. **"Mitglieder verwalten"**

---

## Schritt 2 – Frischer Seed-Lauf (obligatorisch vor Screenshots)

Seed-Lauf erzeugt realistische Testdaten. Immer `reset-and-seed` verwenden, damit der Zustand deterministisch ist.

```powershell
ddev exec curl -s -X POST "http://localhost/dev/seed" `
  -H "Content-Type: application/json" `
  -d '{"mode":"reset-and-seed","years":3}'
```

**Erfolg prüfen**: Antwort enthält `"status":"ok"` und eine Zählerübersicht. Bei Fehler → stoppen, Fehler melden.

Seed-Zugangsdaten für Screenshots:
- E-Mail: `seed.001@chor.local`
- Passwort: `seed`
- URL: `https://chormanager.ddev.site`

---

## Schritt 3 – Screenshot-Skript

### Dateiort

```
help/{slug}/scripts/screenshot.js
help/{slug}/screenshots/          ← Screenshots landen hier
```

### Vorlage (auf Basis von `help/sponsoring/scripts/screenshot.js`)

```js
'use strict';

const path = require('path');
const fs   = require('fs');
const { chromium } = require('playwright');

const BASE_URL       = process.env.BASE_URL       || 'https://chormanager.ddev.site';
const LOGIN_EMAIL    = process.env.LOGIN_EMAIL    || 'seed.001@chor.local';
const LOGIN_PASSWORD = process.env.LOGIN_PASSWORD || 'seed';
const IMAGES_DIR     = path.join(__dirname, '..', 'screenshots');
const VIEWPORT       = { width: 1440, height: 900 };
```

Die Hilfsfunktionen `clickAndWaitForEvent` und `clickAndWaitForTabPane` aus `help/sponsoring/scripts/screenshot.js` kopieren — sie kapseln das Bootstrap-Event-Warten und sind für alle Themen wiederverwendbar.

### Bootstrap-Wartemuster

Immer Event-basiert warten, nie `page.waitForTimeout()`:

```js
// Modal öffnen/schließen
await clickAndWaitForEvent(page, triggerLocator, '#myModal', 'shown.bs.modal');
await clickAndWaitForEvent(page, dismissLocator, '#myModal', 'hidden.bs.modal');

// Tab-Wechsel (opacity-Transition abwarten)
await clickAndWaitForTabPane(page, page.locator('#tab-foo'), '#pane-foo');
```

### Screenshot-Benennung

Nummeriert, sprechend, **englisch** (auch wenn das Modul einen deutschen Namen hat):

```
01-dashboard.png
02-list.png
03-new-modal.png
04-detail-master-data.png
```

### Skript ausführen

```powershell
ddev exec node help/{slug}/scripts/screenshot.js
```

---

## Schritt 4 – Markdown-Datei schreiben

### Dateiort & Benennung

| Typ | Datei |
|-----|-------|
| Einzel-/Oberthema | `help/{slug}/docs/{slug}.md` |
| Unterthema | `help/{slug}/docs/{slug}-{bereich}.md` |

Der `HelpController` gruppiert automatisch nach Präfix: `sponsoring.md` + `sponsoring-*.md` → Accordion-Gruppe auf der Hilfe-Übersichtsseite.

### Aufbau

```markdown
# Titel des Themas

Kurze Beschreibung: Was ist dieses Modul, wofür ist es nützlich?

> **Berechtigung:** Dieses Modul ist nur sichtbar, wenn deine Rolle das Recht **"{Recht-Label}"** hat.
> Siehst du den Menüpunkt nicht, frag den Administrator unter **Verwaltung → Rollen**.

## 1. Einstieg / Übersicht

Text. Klickpfad: **Bereiche → {Modulname}**.

![Alt-Text](images/{slug}/01-dashboard.png)

## 2. Nächster Schritt

...

## Anleitungen (nur bei Oberthema)

- [{Unterthema A}]({slug}-{bereich-a}) – Kurzbeschreibung
- [{Unterthema B}]({slug}-{bereich-b}) – Kurzbeschreibung

## Häufige Stolperfallen (optional)

- **Pflichtfeld X** ist ...
- **Löschen ist endgültig** – ...
```

### Bildpfade in Markdown

Screenshots werden im Browser über `/help/images/{slug}/{datei}.png` geladen.
In Markdown immer als **relative URL** ohne führenden Slash schreiben:

```markdown
![Alt-Text](images/{slug}/01-dashboard.png)
```

### Regeln

- Keine Rollennamen ("Admin", "Vorstand", "Kassier") — nur Recht-Labels
- Sprache: Deutsch, Du-Form (Inhalt) — Dateiname/Slug trotzdem immer englisch
- Berechtigung immer im ersten Abschnitt als Blockquote
- Screenshots direkt nach dem erklärenden Absatz einbetten

---

## Schritt 5 – Ergebnis prüfen

```powershell
Start-Process "https://chormanager.ddev.site/help/{slug}"
```

Checkliste:
- [ ] Seite lädt ohne Fehler
- [ ] Titel korrekt (aus `# Überschrift` in der Markdown-Datei)
- [ ] Alle Screenshots sichtbar (keine kaputten Bildlinks)
- [ ] Navigation unter `/help` zeigt das neue Thema in der richtigen Gruppe
- [ ] Berechtigungshinweis vorhanden und korrekt formuliert

---

## Häufige Fehler

| Problem | Ursache | Fix |
|---------|---------|-----|
| Screenshot zeigt leere/falsche Daten | Seed vergessen oder veraltete Daten | Seed-Lauf wiederholen (`reset-and-seed`) |
| Bild erscheint nicht in `/help` | Bildpfad falsch | Pfad in Markdown prüfen: `images/{slug}/datei.png` |
| Thema erscheint nicht in Index | Datei liegt nicht in `help/{slug}/docs/` oder hat keine `# Überschrift` | Pfad und erste Zeile prüfen |
| Modal-Screenshot zu früh | CSS-Transition noch aktiv | `clickAndWaitForEvent` mit `shown.bs.modal` verwenden |
| Tab-Inhalt fehlt im Screenshot | Tab-Transition noch aktiv | `clickAndWaitForTabPane` mit opacity-Check verwenden |
