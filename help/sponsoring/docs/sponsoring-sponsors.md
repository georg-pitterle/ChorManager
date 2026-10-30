# Sponsoren verwalten

Diese Anleitung zeigt, wie du Sponsoren anlegst und ihnen Kategorien und Logos gibst, Vorgänge mit Gegenleistungen und Leistungen des Sponsors erfasst, Erbrachtes abhakst, Zahlungen vermerkst und Kontakte protokollierst. Sie setzt voraus, dass du bereits im [Sponsoring-Modul](sponsoring) bist.

> **Berechtigung:** Für alles hier brauchst du das Recht **"Eigene Sponsoring-Vorgänge erfassen"** oder **"Sponsoring verwalten"**. Was das kleinere Recht bei fremden Einträgen verbirgt, steht unter [Wer darf was](sponsoring).

## 1. Sponsorenliste

Klicke in der Leiste oben auf **Sponsoren**. Die Liste zeigt je Sponsor:

- unter dem Namen Typ, **Kategorie** und Kontaktperson; ein kleines Bild-Symbol neben dem Namen heißt: Es liegt mindestens ein Logo vor
- den **Zustand** – abgeleitet aus den Vorgängen: "Anfrage läuft", "Zusage", "Absage", "Abgeschlossen", "Kein Vorgang" oder "Keine Anfragen erwünscht"; eine laufende Anfrage hat Vorrang vor einer früheren Zusage
- das **Paket** des maßgeblichen Vorgangs – einer Zusage vor einer laufenden Anfrage, sonst des jüngsten
- die Spalte **Zuständig**: die Personen der laufenden Vorgänge (Angefragt, Erinnert, Zusage), jede einmal. Abgeschlossene Vorgänge und Absagen zählen nicht. Fehlt bei allen laufenden Vorgängen eine Person, steht dort "niemand"; fehlt sie nur bei einem Teil, steht hinter den Namen etwa "1 Vorgang ohne". Eine Person, die nicht mehr aktiv ist, zählt wie niemand.
- die **nächste Frist**, also die früheste offene Wiedervorlage, mit Hinweis "überfällig"
- mit dem Recht "Sponsoring verwalten" die Summe **Zugesagt**
- Telefon und E-Mail zum direkten Anklicken

Über die Tabellenleiste suchst du nach Namen, filterst nach Zustand, Kategorie und **Zuständig** – dort stehen alle Personen, die in der Liste vorkommen, und "Niemand" – und sortierst nach den Spalten. **Öffnen** führt zur Seite des Sponsors.

Diese Liste ist der Grund, warum jedes Mitglied mit Sponsoring-Zugang alle Sponsoren sieht: Vor einer Anfrage siehst du hier, wo bereits jemand angefragt hat – und musst dich nicht vorher abstimmen.

![Sponsorenliste](images/sponsoring/02-sponsors-list.png)

## 2. Neuen Sponsor anlegen

Klicke oben rechts auf **Neuer Sponsor**. Im Dialog trägst du die Stammdaten ein: Typ (Organisation oder Firma, Einzelperson), Name, Kategorie, Kontaktperson, E-Mail, Telefon, Website und Adresse. Mit **Anlegen** wird der Sponsor gespeichert.

Einen eigenen Status hat der Sponsor nicht – der ergibt sich aus seinen Vorgängen. Nur wenn jemand ausdrücklich nicht mehr angefragt werden möchte, schaltest du **"Keine Anfragen erwünscht"** ein; erst dann erscheint das Feld für die Begründung. Danach lassen sich für diesen Sponsor keine neuen Vorgänge und Kontakte mehr anlegen. Die Begründung bleibt erhalten, auch wenn du die Sperre später wieder aufhebst.

![Dialog "Neuer Sponsor"](images/sponsoring/03-new-sponsor-modal.png)

### Kategorie

Die **Kategorie** ordnet den Sponsor ein, etwa Gastronomie, Einzelhandel oder Kultur.
Ein Klick auf das Feld öffnet die Liste aller vorhandenen Kategorien; beim Tippen
wird sie gefiltert. Gibt es die Kategorie noch nicht, wählst du **„… neu anlegen“** –
sie entsteht beim Speichern und steht danach allen zur Auswahl. Groß- und
Kleinschreibung spielen dabei keine Rolle: „gastronomie“ landet bei „Gastronomie“.
Über das kleine Kreuz im Feld entfernst du die Kategorie wieder.

![Kategorie wählen oder neu anlegen](images/sponsoring/14-category-select.png)

### Hinweis auf einen ähnlichen Sponsor

Gibt es schon einen Sponsor mit sehr ähnlichem Namen, legt **Anlegen** nichts an.
Der Dialog kommt mit deinen Eingaben zurück und nennt den vorhandenen Sponsor als Link.
Als ähnlich gilt, was sich nur in Groß- und Kleinschreibung, Satzzeichen, Leerzeichen
oder der Rechtsform unterscheidet – „Bäckerei Huber GmbH“, „bäckerei huber“ und
„Bäckerei-Huber KG“ sind für den Chor-Manager derselbe Betrieb.

Ist es wirklich derselbe, arbeite beim vorhandenen Sponsor weiter. Ist es ein
anderer – etwa eine zweite Filiale –, klickst du **Trotzdem anlegen**. Dasselbe gilt,
wenn du einen Sponsor umbenennst.

![Hinweis auf einen ähnlich benannten Sponsor](images/sponsoring/15-duplicate-warning.png)

## 3. Die Seite eines Sponsors

Die Seite liest sich von oben nach unten – ohne Reiter:

1. **Kopf**: Name, Zustand und Typ. Rechts **Zur Liste** zurück zur Sponsorenliste, die Knöpfe **Kontakt protokollieren** und **Neuer Vorgang**, unter **Mehr** das Bearbeiten der Stammdaten und – mit "Sponsoring verwalten" – das Löschen des Sponsors.
2. **Springen zu**: Abkürzungen zu den Abschnitten weiter unten – Vorgänge, Kontakte, Logos und Anhänge, jeweils mit ihrer Anzahl.
3. **Steckbrief**: als Erstes **Zuständig**, dieselbe Zusammenfassung wie in der Liste (die Zuständigkeit selbst steht weiter am einzelnen Vorgang), dann Kategorie, Kontaktperson, Telefon (anklickbar), E-Mail, Website, Adresse, die nächste offene Wiedervorlage und die Notizen zum Sponsor.
4. **Vorgänge** – je Vorgang eine Karte, siehe Abschnitt 4.
5. **Kontakthistorie** – alle Gespräche, die neuesten zuerst.
6. **Logos** – alle Logo-Dateien des Sponsors, siehe Abschnitt 5.
7. **Anhänge zum Sponsor** – Mediadaten und alles, was zu keinem einzelnen Vorgang gehört. Neue Dateien ziehst du auf die gestrichelte Fläche oder klickst dort auf **auswählen**; jede zeigt ihren eigenen Fortschritt.

![Seite eines Sponsors](images/sponsoring/04-sponsor-detail.png)

### Stammdaten bearbeiten

Über **Mehr → Stammdaten bearbeiten** öffnet sich ein Dialog mit allen Feldern; **Speichern** übernimmt die Änderungen. Den Menüpunkt siehst du nur, wenn du den Sponsor selbst angelegt hast oder das Recht "Sponsoring verwalten" hast. Sonst steht der Steckbrief nur zum Lesen da.

![Dialog "Stammdaten bearbeiten"](images/sponsoring/11-edit-master-data-modal.png)

## 4. Einen Vorgang lesen

Jeder Vorgang ist eine Karte:

- oben das **Projekt** (oder "Ohne Projekt"), das **Paket** und der **Status**
- rechts der **Betrag** und darunter der Zahlungsstand: **"Bezahlt am …"** oder, bei einer Zusage ohne Zahlung, **"Zahlung offen"**
- Zeitraum und zuständige Person
- die **Gegenleistungen** als Abhakliste mit dem Stand "2 von 4 erbracht"
- die **Leistungen des Sponsors** – was der Sponsor liefert, etwa Banner oder unterschriebener Vertrag – mit dem Stand "1 von 2 erhalten"
- die **Notizen**, die **Belege** und die **Kontakte zu diesem Vorgang** – über **Kontakt protokollieren** in der Karte landet ein neuer Kontakt gleich beim richtigen Vorgang
- unten **Bearbeiten** und **Löschen**

![Ein Vorgang mit Gegenleistungen und Leistungen des Sponsors](images/sponsoring/05-sponsor-detail-agreement.png)

### Gegenleistungen abhaken

Ein Klick auf eine Leistung hakt sie als erbracht ab; daneben erscheint das Datum. Ein weiterer Klick nimmt den Haken wieder zurück. Abhaken darf nur, wer das Recht "Sponsoring verwalten" hat. Alle anderen sehen die Liste mit ihren Haken, ohne sie ändern zu können.

Dieselben offenen Leistungen findest du auch auf der Übersicht unter **Offene Gegenleistungen** – dort über alle Sponsoren hinweg.

Die **Leistungen des Sponsors** hakst du genauso ab, als **erhalten** – etwa wenn das
Banner übergeben oder der Vertrag unterschrieben zurück ist. Das darf, wer den
Vorgang bearbeiten darf, und die Person, die als **zuständig** eingetragen ist. Was noch aussteht, sammelt die Übersicht unter
**Offene Leistungen des Sponsors**.

## 5. Logos

Logos kommen in vielen Formaten und Varianten – als SVG, EPS oder PNG, in mehreren
Farben, oft als ZIP vom Grafikbüro. Wer sie entgegennimmt, weiß meist nicht, welche
davon der Grafiker für Plakat und Flyer braucht. Deshalb nimmt der Abschnitt
**Logos** beliebig viele Dateien auf. Sie bleiben beim Sponsor, auch für spätere
Vorgänge.

![Logo-Dateien eines Sponsors](images/sponsoring/16-sponsor-logos.png)

- **Hochladen**: Dateien auf die gestrichelte Fläche ziehen oder dort auf
  **auswählen** klicken. Erlaubt sind Bilder (JPG, PNG, WebP, GIF, TIFF), SVG, PDF,
  EPS, AI und ZIP, je Datei bis 50 MB. Bilder werden nicht verkleinert – der
  Grafiker bekommt die Datei so, wie sie vom Sponsor kam.
- **Ansehen**: Bei Bildern zeigt der Abschnitt eine kleine Vorschau. SVG, EPS, AI
  und ZIP gibt es nur zum Herunterladen.
- **Löschen**: über den Papierkorb neben der Datei. Eine neue Variante lädst du
  einfach dazu; ersetzt wird nichts.

Hochladen und löschen darf, wer den Sponsor bearbeiten darf – wer ihn angelegt hat
oder das Recht "Sponsoring verwalten" hat –, und die **zuständige Person eines
zugesagten Vorgangs** dieses Sponsors. Alle anderen sehen nur, wie viele
Logo-Dateien es gibt.

Sobald ein Sponsor mindestens eine Logo-Datei hat, verschwindet er auf der Übersicht
aus der Liste **Logo fehlt**.

## 6. Vorgang anlegen oder bearbeiten

Klicke oben auf **Neuer Vorgang**. Im Dialog erfasst du Paket, Status, Betrag, "Bezahlt am", Projekt, zuständige Person, Zeitraum, Gegenleistungen, Leistungen des Sponsors, wofür das Logo gebraucht wird, Notizen und optional Belege (z. B. den unterschriebenen Vertrag als PDF). Mit **Anlegen** wird der Vorgang gespeichert.

Einiges füllt der Dialog für dich vor – überschreiben kannst du alles:

- **Paket**: Wählst du ein Paket, stehen seine Gegenleistungen im Feld **Gegenleistungen**, seine Vorgaben im Feld **Leistungen des Sponsors**, sein Mindestbetrag im Feld **Betrag** und seine Häkchen unter **Logo gebraucht für**. Liegt der Betrag unter dem Mindestbetrag, weist ein Hinweis darauf hin – speichern lässt er sich trotzdem, etwa bei einer Sachleistung.
- **Projekt**: Der Zeitraum übernimmt den Zeitraum des Projekts.

![Dialog "Neuer Vorgang" mit vorgeschlagenen Gegenleistungen](images/sponsoring/06-new-agreement-modal.png)

Die **Gegenleistungen** und die **Leistungen des Sponsors** trägst du eine pro Zeile ein. Sie gehören danach zum Vorgang: Ändert jemand später das Paket, bleiben die ausgehandelten Listen, wie sie sind. Leerst du ein Feld bewusst, bekommt der Vorgang dort keine Einträge.

Unter **Logo gebraucht für** kreuzt du an, ob das Logo für den **Druck** (Plakat, Flyer, Programmheft) oder **online** (Homepage, Social Media) gebraucht wird. Daraus ergibt sich die Frist in der Liste **Logo fehlt** auf der Übersicht.

![Leistungen des Sponsors und Logo-Bedarf im Dialog "Neuer Vorgang"](images/sponsoring/17-new-agreement-deliverables.png)

**"Bezahlt am"** bleibt leer, bis das Geld da ist. Trägst du das Datum später über **Bearbeiten** nach, verschwindet die Zusage aus "Zahlung offen".

Beim **Bearbeiten** gilt:

- Ein leeres Datumsfeld bleibt leer – so lässt sich ein Vorgang auch unbefristet führen.
- Leistungen, deren Text du nicht änderst, behalten ihren Haken – bei den Gegenleistungen wie bei den Leistungen des Sponsors. Neue Zeilen kommen offen hinzu, gestrichene verschwinden.

Ohne das Recht "Sponsoring verwalten" bearbeitest du nur die Vorgänge, die du selbst angelegt hast. Die Projektauswahl bietet dir dann genau die Projekte an, die du verwenden darfst – also die gerade laufenden. Fremde Vorgänge tragen den Hinweis "Von jemand anderem angelegt"; bei ihnen siehst du statt Betrag, Zahlungsstand, Notizen und Belegen nur, dass es sie gibt.

## 7. Kontakt protokollieren

Die **Kontakthistorie** zeigt alle Gespräche mit dem Sponsor – Datum, Art, wer protokolliert hat und zu welchem Vorgang. Steht eine Wiedervorlage an, siehst du ihr Datum und bei Bedarf den Hinweis "überfällig" oder "heute"; mit **Erledigt** hakst du sie ab.

![Kontakthistorie](images/sponsoring/07-sponsor-detail-contacts.png)

Über **Kontakt protokollieren** erfasst du einen neuen Kontakt: Datum, Art (Anruf, E-Mail, Treffen, Brief, Sonstiges), optional den zugehörigen Vorgang, eine Zusammenfassung und optional ein Datum für eine Wiedervorlage. Diese Wiedervorlage erscheint auf der Übersicht, sobald sie in die nächsten 7 Tage rückt.

![Dialog "Kontakt protokollieren"](images/sponsoring/08-new-contact-modal.png)

## 8. Anhänge zentral finden

Über **Anhänge** in der Leiste der Sponsoring-Seiten kommst du zu einer Liste aller Dateien aus dem Sponsoring – Verträge und Angebote aus den Vorgängen sowie Unterlagen der Sponsoren. Die Logos stehen nicht darin, sondern beim jeweiligen Sponsor im Abschnitt **Logos**. Dort suchst und sortierst du sie, ohne dich vorher durch die einzelnen Sponsoren klicken zu müssen. Je Zeile stehen zwei Knöpfe: die **Vorschau** öffnet die Datei in einem Fenster, der **Download** lädt sie herunter. Bei Dateien, die sich im Browser nicht anzeigen lassen – etwa Word- oder Excel-Dokumente – entfällt die Vorschau. Dieselben zwei Knöpfe findest du auch bei den Belegen und Anhängen auf der Seite eines Sponsors.

![Zentrale Anhangsammlung](images/sponsoring/10-attachments.png)

## Häufige Stolperfallen

- **Name ist Pflicht** und darf maximal 255 Zeichen lang sein.
- **E-Mail und Website müssen gültig sein.** Als Website gilt nur eine Adresse, die mit `http://` oder `https://` beginnt.
- **Bei einem Vorgang ist der Sponsor Pflichtfeld.** Ein leerer Betrag gilt als 0,00 €. Beim Betrag kannst du Komma oder Punkt als Dezimaltrennzeichen verwenden (z. B. `1500,00` oder `1500.00`).
- **Gegenleistungen und Leistungen des Sponsors:** eine pro Zeile, jede höchstens 255 Zeichen, höchstens 30 je Liste und Vorgang.
- **Kategorie:** höchstens 100 Zeichen.
- **Bei einem Kontakt sind Datum, Art und Zusammenfassung Pflichtfelder.** Die Zusammenfassung darf maximal 2000 Zeichen lang sein.
- **Anhänge haben eine Größengrenze:** Bilder bis 2 MB, andere Dateien (z. B. PDF) bis 10 MB. Logo-Dateien dürfen bis 50 MB groß sein.
- **Einen Sponsor löschen ist endgültig.** Dabei werden auch alle zugehörigen Vorgänge samt Gegenleistungen, Kontakte, Anhänge und Logos gelöscht. Löschen darf nur, wer das Recht "Sponsoring verwalten" hat.
- **Vorgänge zu abgeschlossenen Projekten** kann nur die Sponsoring-Verwaltung erfassen. Mit dem Recht "Eigene Sponsoring-Vorgänge erfassen" stehen in der Auswahl nur laufende Projekte – oder du lässt das Projekt leer.
