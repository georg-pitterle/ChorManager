# Finanzen (Kassabuch & Auswertung)

Im Finanzmodul führst du das Kassabuch des Vereins: Einnahmen und Ausgaben
erfassen, Belege anhängen und das Geschäftsjahr auswerten. Für Rechnungsprüfer
und zur Ablage kannst du ein Geschäftsjahr als PDF herunterladen.

> **Berechtigung:** Dieses Modul ist nur sichtbar, wenn deine Rolle das Recht
> **"Finanzen nur lesen"** (oder **"Finanzen lesen und schreiben"**) hat.
> Siehst du den Menüpunkt **Kassa** nicht, frag den Administrator unter
> **Verwaltung → Rollen**. Nur mit dem Recht **"Finanzen lesen und schreiben"**
> kannst du Einträge anlegen, bearbeiten und löschen; mit "Finanzen nur lesen"
> siehst du das Kassabuch, aber ohne die Schaltflächen zum Ändern.

## 1. Einstieg

Klickpfad: **Bereiche → Kassa**. Der Menüpunkt **Kassa** erscheint nur, wenn das
Finanzmodul aktiv ist und deine Rolle das Recht "Finanzen nur lesen" oder
"Finanzen lesen und schreiben" besitzt.

Das Kassabuch listet alle Buchungen des laufenden Geschäftsjahres mit laufender
Nummer, Rechnungs- und Zahldatum, Beschreibung, Gruppe, Betrag und Zahlungsart
(Bar oder Überweisung). Oben rechts wählst du im Feld **Jahr** das Geschäftsjahr;
über **Suche** und die Tabellenleiste kannst du filtern und sortieren.

![Kassabuch mit Buchungsliste](images/finance/01-kassabuch.png)

### Neuen Eintrag erfassen

Mit **"Neuer Eintrag"** (nur mit dem Recht "Finanzen lesen und schreiben")
öffnest du das Formular. Pflichtfelder sind Rechnungsdatum, Beschreibung,
Ein-/Ausgang, Zahlungsart und Betrag. Das **Zahldatum** bleibt leer, solange die
Rechnung offen ist. Optional ordnest du den Eintrag einer **Gruppe** zu (oder
legst über "+ Neue Gruppe eingeben…" eine neue an) und lädst **Anhänge** (Belege
als Bild oder PDF) hoch.

![Formular für einen neuen Kassabuch-Eintrag](images/finance/02-new-entry-modal.png)

### Beginn des Geschäftsjahres einstellen

Über **"Konfiguration"** (ebenfalls nur mit Schreibrecht) legst du fest, an
welchem Tag das Geschäftsjahr beginnt. Das Geschäftsjahr rechnet sich automatisch
vom eingestellten Startdatum bis zum Tag davor im Folgejahr. Format: **DD.MM.**
(z. B. `01.09.` für den 1. September).

![Konfiguration des Geschäftsjahr-Beginns](images/finance/03-settings-modal.png)

## 2. Kontoauszug importieren

Statt jede Bankbewegung abzutippen, kannst du die **Umsatzübersicht** aus dem
Online-Banking als CSV-Datei hochladen. Der Button **"Import"** steht im
Kassabuch neben "Konfiguration" und ist nur mit dem Recht
**"Finanzen lesen und schreiben"** sichtbar.

Der Ablauf hat zwei Schritte:

1. **Datei einlesen.** Im Fenster **"Kontoauszug importieren"** wählst du die
   CSV-Datei aus und klickst auf **"Datei einlesen"**. Es wird noch nichts
   verbucht.
2. **Vorschau prüfen und übernehmen.** Die Vorschau zeigt jede erkannte Zeile
   mit Buchungsdatum, Zahldatum, Beschreibung, Ein-/Ausgang und Betrag. Du
   kannst einzelne Zeilen abwählen und jeder Zeile eine **Gruppe** zuordnen.
   Erst **"… Zeilen übernehmen"** legt die Buchungen an – mit fortlaufender
   Nummer wie bei manuell erfassten Einträgen. Über **"Abbrechen"** verwirfst du
   den Import vollständig.

So werden die Spalten der Datei übernommen:

| Kontoauszug | Kassabuch |
| --- | --- |
| Buchungsdatum | Rechnungsdatum |
| Valutadatum | Zahldatum |
| Betrag mit Minus | Ausgang |
| Betrag mit Plus | Eingang |
| Gegenpartei + Verwendungszweck | Beschreibung |
| – | Zahlungsart immer "Überweisung" |

Als **Gegenpartei** wird immer die Seite verwendet, die nicht das eigene Konto
ist – bei einer Lastschrift also der Auftraggeber, bei einer Überweisung der
Empfänger.

### Doppelte Buchungen

Jede importierte Zeile bekommt einen Fingerabdruck aus Datum, Betrag,
Gegenkonto und Verwendungszweck. Lädst du dieselbe Datei erneut hoch oder
überschneiden sich zwei Auszüge, erkennt der Import die betroffenen Zeilen,
markiert sie in der Vorschau als **"bereits importiert"** und sperrt sie. Sie
können also nicht doppelt verbucht werden. Zwei tatsächlich identische
Buchungen am selben Tag bleiben davon unberührt und werden beide übernommen.

## 3. Auswertung des Geschäftsjahres

Über **"Auswertung"** öffnest du die Jahresübersicht (Klickpfad:
**Bereiche → Kassa → Auswertung**). Oben rechts wählst du im Feld
**Geschäftsjahr** das gewünschte Jahr. Die Auswertung zeigt Kennzahlen
(Einnahmen, Ausgaben, Saldo), die Salden nach Zahlungsart und nach Gruppe sowie
den vollständigen Verlauf aller Buchungen.

![Finanzauswertung mit Kennzahlen und Salden](images/finance/04-report.png)

## 4. Geschäftsjahr als PDF herunterladen

Auf der Seite **Finanzauswertung** findest du oben rechts den Button
**"PDF herunterladen"**. Er erzeugt ein PDF des aktuell gewählten
Geschäftsjahres zum Speichern, Drucken oder Weitergeben an die Rechnungsprüfer.

Das PDF enthält:

- oben das Vereins-/Chorlogo, den Vereins-/Chornamen und den
  Geschäftsjahr-Zeitraum,
- die Kennzahlen Einnahmen, Ausgaben und Saldo,
- alle Bewegungen des Geschäftsjahres in chronologischer Reihenfolge mit Datum,
  laufender Nummer, Beschreibung, Zahlungsart, Einnahme und Ausgabe,
- bei jedem Seitenwechsel einen **Übertrag** (die kumulierten Zwischensummen für
  Einnahmen, Ausgaben und Saldo werden unten fortgeschrieben und oben auf der
  Folgeseite wiederholt), wie in einem Geschäftsbericht,
- am Ende den Gesamtsaldo.

Das PDF wird im **Querformat** erzeugt, damit die Bewegungstabelle genügend
Platz hat.

Der Dateiname ist zum Ablegen sprechend, z. B.
`Kassabuch_Geschäftsjahr_2025-2026.pdf`.

Möchtest du ein anderes Geschäftsjahr ausdrucken, stelle zuerst das
**Geschäftsjahr** oben um und klicke dann erneut auf **"PDF herunterladen"**.

## Anleitungen

- [Budget planen und mit dem Kassabuch vergleichen](finance-budget) – geplante
  Einnahmen und Ausgaben je Gruppe erfassen und dem tatsächlichen Ist
  gegenüberstellen.

## Häufige Stolperfallen

- **Anhänge sind nicht im PDF.** Belege bleiben im Kassabuch gespeichert und
  werden dort geöffnet; das Geschäftsjahr-PDF enthält bewusst nur die
  Bewegungen und Kennzahlen.
- **Beginn des Geschäftsjahres.** Welcher Zeitraum ein Geschäftsjahr umfasst,
  richtet sich nach dem eingestellten Geschäftsjahr-Beginn. Passt der Zeitraum
  nicht, lässt sich der Beginn im Kassabuch unter **"Konfiguration"** ändern
  (Recht "Finanzen lesen und schreiben" nötig).
- **Offene Rechnungen.** Bleibt das Zahldatum leer, gilt die Buchung als offen
  und wird im Kassabuch entsprechend markiert.
- **Import wird abgelehnt.** Der Import erwartet eine semikolongetrennte
  CSV-Datei mit den Spalten *Buchungsdatum* und *Betrag* sowie dem Datumsformat
  **TT.MM.JJJJ**, maximal 2 MB. Exportiert dein Online-Banking Excel- oder
  PDF-Dateien, wähle beim Export ausdrücklich **CSV**.
- **Fremdwährungen.** Es werden nur EUR-Buchungen übernommen; Zeilen in anderen
  Währungen erscheinen in der Vorschau gesperrt mit Hinweis.
- **Bareinnahmen.** Der Import setzt die Zahlungsart immer auf "Überweisung".
  Bargeldbewegungen erfasst du weiterhin über **"Neuer Eintrag"**.
