# Finanzen (Kassabuch & Auswertung)

Im Finanzmodul führst du das Kassabuch des Vereins: Einnahmen und Ausgaben
erfassen, Belege anhängen und das Geschäftsjahr auswerten. Für Rechnungsprüfer
und zur Ablage kannst du ein Geschäftsjahr als PDF herunterladen.

> **Berechtigung:** Dieses Modul ist nur sichtbar, wenn deine Rolle das Recht
> **"Finanzen nur lesen"** (oder **"Finanzen lesen und schreiben"**) hat.
> Siehst du den Menüpunkt **Kassa** nicht, frag den Administrator unter
> **Verwaltung → Rollen**. Nur mit dem Recht **"Finanzen lesen und schreiben"**
> kannst du Einträge anlegen, bearbeiten und stornieren; mit "Finanzen nur lesen"
> siehst du das Kassabuch, aber ohne die Schaltflächen zum Ändern.

## 1. Einstieg

Klickpfad: **Bereiche → Kassa**. Der Menüpunkt **Kassa** erscheint nur, wenn das
Finanzmodul aktiv ist und deine Rolle das Recht "Finanzen nur lesen" oder
"Finanzen lesen und schreiben" besitzt.

Das Kassabuch listet alle Buchungen des laufenden Geschäftsjahres mit laufender
Nummer, Rechnungs- und Zahldatum, Beschreibung, Gruppe, Betrag und Konto
(Barkassa oder Bankkonto). Oben rechts wählst du im Feld **Jahr** das Geschäftsjahr;
über **Suche** und die Tabellenleiste kannst du filtern und sortieren.

> **Wann zählt eine Buchung zu welchem Jahr?** Maßgeblich ist das **Zahldatum**,
> nicht das Rechnungsdatum – eine Rechnung vom 20. August, die erst am
> 10. September bezahlt wird, zählt zum neuen Geschäftsjahr. Das entspricht dem
> Zufluss-Abfluss-Prinzip einer Einnahmen-Ausgaben-Rechnung.

![Kassabuch mit Buchungsliste](images/finance/01-kassabuch.png)

### Offene Posten

Buchungen ohne Zahldatum sind noch nicht geflossen. Sie erscheinen unterhalb der
Buchungsliste im eigenen Abschnitt **Offene Posten** – unabhängig vom gewählten
Jahr – und zählen in kein Geschäftsjahr und in keine Auswertung. Sobald du das
Zahldatum nachträgst, wandert die Buchung ins passende Geschäftsjahr.

![Abschnitt Offene Posten unterhalb der Buchungsliste](images/finance/11-open-items.png)

### Neuen Eintrag erfassen

Mit **"Neuer Eintrag"** (nur mit dem Recht "Finanzen lesen und schreiben")
öffnest du das Formular. Pflichtfelder sind Rechnungsdatum, Beschreibung,
Ein-/Ausgang, Konto und Betrag. Das **Zahldatum** bleibt leer, solange die
Rechnung offen ist. Optional ordnest du den Eintrag einer **Gruppe** zu (oder
legst über "+ Neue Gruppe eingeben…" eine neue an) und lädst **Anhänge** (Belege
als Bild oder PDF) hoch.

![Formular für einen neuen Kassabuch-Eintrag](images/finance/02-new-entry-modal.png)

### Konten (Zahlungskreise)

Jede Buchung gehört zu genau einem Konto – der Barkassa oder einem Bankkonto.
Unter **Kassabuch → Konten** legst du diese Zahlungskreise an. Je Konto erfasst
du:

- **Name** und **Art** (Bar oder Bank),
- **IBAN** (optional, nur bei Bankkonten): Wird sie eingetragen, schlägt der
  Kontoauszug-Import das passende Konto automatisch vor.
- **Anfangsbestand** und **Stichtag**: der Bestand zu Beginn dieses Tages. Ab
  dem Stichtag werden alle Zahlungen des Kontos aufgerechnet.

Die Kontenliste zeigt jederzeit den aktuellen Bestand. Dieser Wert muss mit dem
gezählten Bargeld bzw. dem Saldo auf dem Kontoauszug übereinstimmen – genau das
prüfen die Rechnungsprüfer.

Konten mit Buchungen lassen sich nicht löschen. Wird ein Konto nicht mehr
benutzt, setze es auf **inaktiv**: Bestehende Buchungen bleiben erhalten, das
Konto steht aber bei neuen Einträgen nicht mehr zur Auswahl.

![Kontenliste mit Anfangsbestand und aktuellem Bestand](images/finance/09-accounts.png)

![Formular für ein Konto](images/finance/10-account-modal.png)

### Korrigieren und stornieren

Buchungen lassen sich nicht löschen. Eine falsche Buchung wird über
**Aktionen → Stornieren** aufgehoben: Das System legt automatisch eine
Gegenbuchung mit umgekehrter Richtung und gleichem Betrag an
("Storno zu Nr. 42: …"). Original und Storno bleiben beide im Kassabuch stehen
und sind mit **storniert** bzw. **Storno** gekennzeichnet; in Summe heben sie
sich auf. Anschließend erfasst du die Buchung neu.

Weil beide Buchungen stehen bleiben, zählen sie auch in den Summen "Einnahmen"
und "Ausgaben" des Kassaberichts mit – der Saldo stimmt trotzdem, weil sie
einander aufheben. Der Bericht weist unter den beiden Summen aus, wie viel davon
auf Stornopaare entfällt. Die Budgetauswertung rechnet solche Paare dagegen
heraus; ihre Ist-Werte liegen deshalb genau um diesen Betrag niedriger.

Das ist keine Schikane, sondern Vorgabe: Eine Korrektur darf den
ursprünglichen Inhalt nicht unkenntlich machen. Aus demselben Grund wird jede
Änderung an einer Buchung mitprotokolliert.

### Änderungsjournal

Unter **Kassabuch → Journal** siehst du, wer wann welche Buchung angelegt,
geändert oder storniert hat – bei Änderungen mit dem alten und dem neuen Wert.
Auch das Verschieben des Buchungsabschlusses steht dort, als Eintrag
**Abschluss** ohne Bezug zu einer einzelnen Buchung. Das Journal ist für alle
sichtbar, die das Kassabuch lesen dürfen, und damit die erste Anlaufstelle der
Rechnungsprüfer.

![Änderungsjournal mit Anlage, Änderung und Storno](images/finance/15-journal.png)

### Geschäftsjahr abschließen

Ist ein Jahr geprüft und in der Generalversammlung entlastet, trägst du unter
**"Konfiguration"** bei **Buchungen abgeschlossen bis** das Enddatum ein.
Zahlungen bis zu diesem Tag lassen sich danach nicht mehr ändern; auch neue
Buchungen in diesem Zeitraum werden abgelehnt. Nötige Korrekturen laufen dann
über eine Stornobuchung, die automatisch auf den ersten wieder offenen Tag
gebucht wird – das geprüfte Jahr bleibt also unverändert. Ein leeres Feld hebt
die Sperre auf. Jede Änderung an diesem Datum landet im Änderungsjournal,
einschließlich einer Rückdatierung, die einen bereits geprüften Zeitraum wieder
öffnet.

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

![Fenster zum Auswählen der CSV-Datei](images/finance/12-import-modal.png)

![Vorschau des eingelesenen Kontoauszugs](images/finance/13-import-preview.png)

So werden die Spalten der Datei übernommen:

| Kontoauszug | Kassabuch |
| --- | --- |
| Buchungsdatum | Rechnungsdatum |
| Valutadatum | Zahldatum |
| Betrag mit Minus | Ausgang |
| Betrag mit Plus | Eingang |
| Gegenpartei + Verwendungszweck | Beschreibung |
| IBAN des Auszugs | Konto (wird vorbelegt) |

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
(Einnahmen, Ausgaben, Saldo), den **Kassabericht je Konto**, die Salden nach
Zahlungsart und nach Gruppe sowie den vollständigen Verlauf aller Buchungen.

Der **Kassabericht** ist der Teil, den die Rechnungsprüfer brauchen: je Konto
Anfangsbestand, Einnahmen, Ausgaben und Endbestand, darunter die Gesamtsumme.
Der Endbestand des einen Jahres ist automatisch der Anfangsbestand des nächsten.

![Kassabericht je Konto in der Auswertung](images/finance/14-account-statement.png)

![Finanzauswertung mit Kennzahlen und Salden](images/finance/04-report.png)

## 4. Kassabuch als CSV exportieren

Der Button **"CSV"** im Kassabuch lädt alle Buchungen des gewählten
Geschäftsjahres als Tabelle herunter – gedacht für Rechnungsprüfer und
Steuerberater, die mit Excel oder LibreOffice weiterrechnen wollen. Dafür
genügt das Recht **"Finanzen nur lesen"**.

Die Datei ist semikolongetrennt und UTF-8-kodiert, öffnet sich also per
Doppelklick direkt in Excel. Enthalten sind laufende Nummer, Rechnungs- und
Zahldatum, Beschreibung, Gruppe, Art, Betrag, Konto, Zahlungsart, der Bezug
einer Stornobuchung und die Anzahl der Anhänge.

Ausgaben tragen im Betrag ein Minus, Einnahmen kein Vorzeichen – damit lässt
sich die Spalte in der Tabellenkalkulation direkt aufsummieren und ergibt den
Saldo des Geschäftsjahres. Offene Posten ohne Zahldatum sind nicht enthalten,
weil sie zu keinem Geschäftsjahr gehören.

## 5. Geschäftsjahr als PDF herunterladen

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
- **Buchung fehlt in der Auswertung.** Fast immer fehlt das Zahldatum. Ohne
  Zahldatum steht die Buchung unter **Offene Posten** und zählt in kein
  Geschäftsjahr. Ebenso möglich: Die Zahlung fiel in ein anderes Geschäftsjahr
  als die Rechnung – dann zählt sie dort.
- **Import wird abgelehnt.** Der Import erwartet eine semikolongetrennte
  CSV-Datei mit den Spalten *Buchungsdatum* und *Betrag* sowie dem Datumsformat
  **TT.MM.JJJJ**, maximal 2 MB. Exportiert dein Online-Banking Excel- oder
  PDF-Dateien, wähle beim Export ausdrücklich **CSV**.
- **Fremdwährungen.** Es werden nur EUR-Buchungen übernommen; Zeilen in anderen
  Währungen erscheinen in der Vorschau gesperrt mit Hinweis.
- **Bareinnahmen.** Der Import setzt die Zahlungsart immer auf "Überweisung".
  Bargeldbewegungen erfasst du weiterhin über **"Neuer Eintrag"**.
