---
name: ChorManager
description: Ruhige, sachliche Verwaltungsoberfläche, die jede Vereinsfarbe trägt
colors:
  club-primary: "#E8A817"
  club-primary-readable: "#8E660E"
  topbar-ink: "#18212b"
  sidebar-slate: "#243244"
  page-mist: "#eef2f7"
  surface-white: "#ffffff"
  surface-muted: "#f5f7fa"
  hairline: "#d5dbe4"
  listhead-mist: "#eef1f5"
  text-ink: "#1f2937"
  text-muted: "#5b6578"
  button-ink: "#2b2b2b"
  amber-readable: "#806000"
typography:
  page-title:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Noto Sans, Liberation Sans, Arial, sans-serif"
    fontSize: "calc(1.325rem + .9vw)"
    fontWeight: 500
    lineHeight: 1.2
  section-title:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Noto Sans, Liberation Sans, Arial, sans-serif"
    fontSize: "clamp(1.05rem, 1.45vw, 1.35rem)"
    fontWeight: 700
    letterSpacing: "0.02em"
  card-title:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Noto Sans, Liberation Sans, Arial, sans-serif"
    fontSize: "1.1rem"
    fontWeight: 600
    lineHeight: 1.3
  body:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Noto Sans, Liberation Sans, Arial, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
  group-label:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Noto Sans, Liberation Sans, Arial, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 700
    lineHeight: 1.3
    letterSpacing: "0.06em"
  kpi-value:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Noto Sans, Liberation Sans, Arial, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 400
    lineHeight: 1.2
rounded:
  base: "0.375rem"
  dialog: "0.5rem"
  pill: "999px"
spacing:
  topbar: "3.75rem"
  sidebar: "15rem"
  sidebar-rail: "4rem"
  page-header-gap: "1.75rem"
  section-gap: "2rem"
components:
  button-primary:
    backgroundColor: "{colors.club-primary}"
    textColor: "{colors.button-ink}"
    rounded: "{rounded.base}"
  button-secondary:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.text-muted}"
    rounded: "{rounded.base}"
  button-outline-warning:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.amber-readable}"
    rounded: "{rounded.base}"
  card:
    backgroundColor: "{colors.surface-white}"
    rounded: "{rounded.base}"
  table-head:
    backgroundColor: "{colors.listhead-mist}"
    textColor: "{colors.text-ink}"
    typography: "{typography.group-label}"
  badge-club:
    backgroundColor: "{colors.club-primary}"
    textColor: "{colors.button-ink}"
    rounded: "{rounded.base}"
  topbar:
    backgroundColor: "{colors.topbar-ink}"
    textColor: "{colors.club-primary}"
    height: "{spacing.topbar}"
---

# Design System: ChorManager

## Overview

**Creative North Star: "Die Partitur"**

Eine Partitur hat eine feste Ordnung: Zeilen, Systeme, Stimmen, und jedes Zeichen steht an seinem Platz. Genau so ist ChorManager gebaut. Jedes Element hat eine Rolle – Seitentitel, Abschnitt, Karte, Gruppenlabel; Hauptaktion, Nebenaktion, Löschen – und jede Rolle hat genau eine Form. Nichts steht zufällig, nichts wird für eine einzelne Seite neu erfunden.

Die Oberfläche ist ruhig und sachlich. Ehrenamtliche öffnen sie zwischen Probe und Beruf, Mitglieder oft nur alle paar Wochen; beide sollen sich sofort zurechtfinden. Deshalb flache weiße Flächen auf hellem Grund, feine Konturen statt Schatten, eine einzige Rundung und kaum Farbe außer der Vereinsfarbe und den Bedeutungsfarben. Die Vereinsfarbe ist frei einstellbar (White Label) – das System muss jede Farbe tragen, auch helle, ohne an Lesbarkeit zu verlieren.

Dunkel sind nur Topbar und Seitenleiste: der Rahmen der Partitur. Der Inhalt darin bleibt hell und gleichförmig.

**Key Characteristics:**
- Eine Rolle, eine Form – für Überschriften, Knöpfe, Flächen, Hinweise und Badges.
- Flach: keine Schatten, keine Verläufe auf Flächen; Tiefe entsteht durch Kontur und Hintergrundton.
- Eine Rundung (0,375rem) wie im Menü, etwas mehr nur für Dialoge.
- Ein Rahmen pro Ebene – nie Karte in Karte.
- Farbe trägt Bedeutung oder Vereinsidentität, nie Dekoration.

## Colors

Neutrales Blaugrau als Rahmen, Weiß als Arbeitsfläche, die Vereinsfarbe als einziger Akzent.

### Primary
- **Vereinsfarbe** (`club-primary`, Standard #E8A817, pro Installation einstellbar): Primärknöpfe, Akzentlinie (3px) an Formular-, Filter- und Tabellenflächen, Tabellenkopf-Unterkante, aktiver Menüpunkt, Markenname in der Topbar, Badge „Vereinsfarbe“. Als Fläche immer mit dunkler Schrift (`button-ink`).
- **Lesbare Vereinsfarbe** (`club-primary-readable`, für das Standardgelb #8E660E): Links, Tabs, Outline-Primärknöpfe, `text-primary`. Wird zur Laufzeit aus der Vereinsfarbe berechnet (`AppSettingController::strongPrimaryColor`): so weit abgedunkelt, bis 4,5:1 auf dem Seitenhintergrund erreicht sind.

### Neutral
- **Topbar-Tinte** (`topbar-ink`): Topbar, Ende des Seitenleisten-Verlaufs, `theme-color` der installierten App.
- **Schieferblau** (`sidebar-slate`): Anfang des Seitenleisten-Verlaufs.
- **Seitennebel** (`page-mist`): Seitenhintergrund – und zugleich Bezugsfläche der Kontrastberechnung für die lesbare Vereinsfarbe.
- **Weiß** (`surface-white`): Karten, Formulare, Tabellen, Dialoge, Aktionsleisten.
- **Gedämpfte Fläche** (`surface-muted`): leere Ablagen, Symbolkreise, Kanban-Spalten.
- **Haarlinie** (`hairline`): Kontur aller Flächen.
- **Tabellenkopf-Nebel** (`listhead-mist`): Tabellenköpfe und Kartenköpfe.
- **Tinte** (`text-ink`) und **Gedämpfte Tinte** (`text-muted`, 5,2:1 auf dem Seitenhintergrund): Fließtext und Nebeninformation.
- **Lesbarer Bernstein** (`amber-readable`): `text-warning` und `btn-outline-warning` – Bootstraps Gelb als Schrift hätte nur 1,6:1.

### Bedeutungsfarben (Bootstrap)
- **Grün**: erledigt, positiv, Eingang, Zusage, positiver Saldo.
- **Rot**: negativ, dringend, Ausgang, Absage, Löschen, negativer Saldo.
- **Gelb** (als Fläche, als Schrift Bernstein): Achtung, wartet, Vielleicht.
- **Grau**: neutral, inaktiv, Art oder Kategorie.
- **Blau** (info): Hinweis, in Arbeit.

### Named Rules
**The White-Label Rule.** Keine Farbe im Code hängt an einem bestimmten Gelb. Akzente laufen über `--theme-primary` / `--theme-primary-rgb`, lesbare Varianten über `--theme-primary-strong`. Ein fester Gelbton ist ein Fehler.

**The Meaning Rule.** Grün, Rot, Gelb, Grau und Blau tragen die oben genannte Bedeutung – auch bei Badges. Eine Art (Bar, Bank, Manuell) ist grau, nie bunt.

## Typography

**Font:** Bootstraps System-Schriftstapel (`system-ui`, Segoe UI, Roboto …) – keine eigene Webschrift, keine Ladezeit, auf jedem Gerät vertraut.

**Character:** Unauffällig und gut lesbar; die Ordnung entsteht durch wenige, fest vergebene Rollen, nicht durch Schriftwechsel.

### Hierarchy
- **Seitentitel** (500, Bootstrap `.h2`): genau einer pro Seite, `h1.h2` im Seitenkopf. Ohne Icon, ohne Kleinüberschrift darüber.
- **Abschnitt** (700, clamp(1.05rem, 1.45vw, 1.35rem)): `.dashboard-section-title`, gliedert eine Seite. Ohne Icon.
- **Karte / Kachel** (600, 1,1rem): `.card-title`, `.dashboard-panel__title` und Überschriften im Kartenkopf. Darf ein Icon tragen (Kacheln).
- **Fließtext** (400, 1rem, 1,5).
- **Gruppenlabel** (700, 0,75rem, 0,06em, Großbuchstaben, gedämpft): `.group-label` – die einzige Stelle mit Großschreibung. Auch Tabellenköpfe nutzen diesen Stil.
- **Kennzahl** (400, 1,75rem, tabellarische Ziffern): `.finance-kpi-value`; fett nur der Saldo.

### Named Rules
**The Four Roles Rule.** Es gibt vier Überschriftenrollen. Eine Überschrift hat keine eigene Farbe und keine Hilfsklassen für Größe, Gewicht oder Großschreibung.

**The Bold-Is-A-Result Rule.** Fett ist ein Ergebnis oder eine Warnung: Saldo, Summe, Quote, Überfälliges. Namen, Titel, Nummern und Datum stehen normal.

## Layout

Fester Rahmen aus Topbar (3,75rem) und Seitenleiste (15rem, eingeklappt 4rem). Der Inhalt läuft in `.container-xl`; der Seitenkopf folgt denselben Breiten (1140px ab 1200px, 1320px ab 1400px), damit Titel und Inhalt bündig stehen.

Seitenaufbau: Seitenkopf (Titel, Untertitel, Aktionen rechts) – 1,75rem Abstand – Abschnitte im Abstand von 2rem. Ein Abschnitt besteht aus Überschrift mit Haarlinie darunter und seinem Inhalt.

Mobil: Seitenleiste als Offcanvas, Tabellen als Karten (Table-Engine), Aktionsknöpfe in Tabellenzeilen bei grobem Zeiger mindestens 44px. Seitenkopf-Aktionen brechen unter den Titel um.

Formulare: Die Aktionsleiste (`.form-action-bar`) steht am Formularende, rechtsbündig, Speichern rechts, Abbrechen daneben, und läuft beim Scrollen am unteren Rand mit. Am Telefon teilen sich zwei Knöpfe die Breite.

## Elevation & Depth

Das System ist flach. Flächen haben keinen Schatten und keinen Verlauf; Tiefe entsteht nur durch Weiß auf Seitennebel und eine Haarlinie. Die einzigen Schatten sind Rückmeldung auf Bewegung (Hover über Kacheln und Kanban-Karten) und die Topbar über dem Inhalt.

### Named Rules
**The One-Frame Rule.** Ein Rahmen pro Ebene. Ein Abschnitt, der Karten, Tabellen oder Kacheln enthält, ist selbst rahmenlos; in einem Dialog ist der Dialog der Rahmen, Karten darin sind flach.

## Shapes

Eine Rundung für alles Eckige: 0,375rem (`--app-radius`) – Karten, Knöpfe, Felder, Menü, Badges. Dialoge 0,5rem (`--app-radius-lg`). Pillen nur für Zähler. Akzent ist eine 3px-Linie oben an Formular-, Filter- und Tabellenflächen in der Vereinsfarbe; kein seitlicher Farbbalken.

## Components

### Buttons
- **Shape:** Grundrundung (0,375rem).
- **Primär:** Vereinsfarbe mit dunkler Schrift, Gewicht 600, ohne Icon. Eine Hauptaktion pro Kontext.
- **Neben:** graue Kontur (`btn-outline-secondary`) – Abbrechen, Schließen, Zurück, Bearbeiten, Details. Nie gefülltes Grau.
- **Gefahr:** rote Kontur (`btn-outline-danger`) für Löschen, Archivieren, Überschreiben; gefülltes Rot nur als bestätigender Knopf im Lösch-Dialog.
- **Größe:** Seitenkopf normal; Tabellenzeilen klein (`btn-sm`).

### Cards / Containers
- **Corner Style:** Grundrundung.
- **Background:** Weiß.
- **Shadow Strategy:** keiner (siehe Elevation & Depth).
- **Border:** Haarlinie; Formular-, Filter- und Tabellenflächen zusätzlich 3px Vereinsfarbe oben.
- **Kartenkopf:** Tabellenkopf-Nebel mit 3px Vereinsfarbe unten.

### Inputs / Fields
- **Style:** Bootstrap-Felder mit Grundrundung; Beschriftung immer `form-label` ohne Zusatzstil und per `for`/`id` verknüpft, Hilfstext immer `form-text`.

### Tables
- Kopf in Gruppenlabel-Typografie auf Tabellenkopf-Nebel, Unterkante 3px Vereinsfarbe. Zeilenaktionen klein, grau oder rot. Leere Tabelle: eine zentrierte, gedämpfte Zeile.

### Badges
- `text-bg-*` nach Bedeutung (siehe Colors); Schlagworte und Kategorien hell mit Rand (`text-bg-light border`).

### Notices
- `alert-info`, `alert-warning`, `alert-danger`; `alert-success` nur als Rückmeldung nach einer Aktion (Flash). Leerzustände sind gedämpfter Text, kein Hinweiskasten.

### Dialogs
- Immer scrollbar, nie zentriert, Dialogfuß mit Abbrechen und Hauptaktion. Lösch-Dialoge nur, wenn sie Folgen erklären; sonst Bestätigung per `data-confirm`. Kopf ohne rote Füllung.

### Navigation
- Dunkle Topbar mit Markenname in Vereinsfarbe und 3px-Unterkante; Seitenleiste mit gedämpften Gruppenlabels, aktiver Eintrag in Vereinsfarbe auf leicht getönter Fläche. Detailseiten tragen eine Brotkrumenleiste, keine Kleinüberschrift.

## Do's and Don'ts

### Do:
- **Do** Akzente über `--theme-primary` und lesbare Akzente über `--theme-primary-strong` setzen.
- **Do** Rundungen nur über `--app-radius` / `--app-radius-lg`.
- **Do** Seitenformulare mit `.form-action-bar` abschließen.
- **Do** neue Überschriften einer der vier Rollen zuordnen.
- **Do** Kontrast für Text mindestens 4,5:1 halten – auch für helle Vereinsfarben.

### Don't:
- **Don't** Karte in Karte oder Rahmen im Dialog.
- **Don't** Schatten, Verläufe oder Glas auf Flächen.
- **Don't** Kleinüberschriften über Titeln oder Kacheln.
- **Don't** Icons in Seiten-, Abschnitts- oder Gruppentiteln oder in Primärknöpfen.
- **Don't** gefüllte graue Knöpfe, farbige Nebenaktionen im Seitenkopf oder `bg-*` an Badges.
- **Don't** Bootstraps Gelb als Schriftfarbe.
- **Don't** Inline-Skripte oder Inline-Styles (außer CSS-Variablen für dynamische Werte).
