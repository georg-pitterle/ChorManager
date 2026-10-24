# Sponsoring: Logos mit Fristen, Leistungen des Sponsors, Kategorien, Dublettenwarnung

Stand: 2026-10-23 · Herkunft: Abgleich der bisherigen Excel-Sponsoringliste mit dem Modul

## Anlass

Bisher lief das Sponsoring in einer Excel-Liste. Beim Abgleich mit dem Modul fehlten vor allem
die Punkte, die vor einem Konzert nachgejagt werden müssen. Der größte Schmerz laut Georg: **die
Logos zusammensuchen**, weil sie auf Plakat und Flyer müssen und dafür Fristen gelten. Für
Homepage und Social Media reicht ein Logo noch am Tag vor dem Konzert, fürs Plakat muss es viel
früher da sein.

Daneben fehlten: das, was der Sponsor liefert (Banner/Beachflag, Vertrag, Sachleistungen), eine
Einordnung der Sponsoren nach Kategorie und eine Warnung bei doppelt angelegten Sponsoren.

## Ziele

1. **Logo am Sponsor mit Fristen.** Jeder Sponsor hat genau ein aktuelles Logo, das über alle
   Vereinbarungen hinweg bleibt. Sponsert er wieder, ist das Logo schon da. Es lässt sich
   ersetzen. Das Dashboard zeigt, von wem ein Logo bis wann fehlt.
2. **Leistungen des Sponsors** als abhakbare Liste je Vereinbarung, aufgebaut wie die
   bestehenden Gegenleistungen.
3. **Kategorie am Sponsor**, wählbar aus den vorhandenen Werten, ohne Umweg erweiterbar.
4. **Dublettenwarnung** beim Anlegen und Umbenennen eines Sponsors.

## Nicht-Ziele

- Keine Pflegeseite für Kategorien (Umbenennen, Zusammenführen, Löschen). Siehe „Offen für später“.
- Keine Fristen als Vorlauf („3 Wochen vor dem Konzert“). Georg hat sich ausdrücklich für ein
  festes Datum am Projekt entschieden.
- Keine Logo-Versionierung. Ersetzt ist ersetzt.
- Keine Übernahme der Excel-Notizen („Kategorie: Lokale Firmen“) in das neue Kategorienfeld.
- Kein Hilfethema. Laut `AGENT.md` entsteht es erst auf ausdrückliche Anforderung.

## Anti-Ziele (wäre ein Fehlschlag, auch wenn es technisch läuft)

- Fest eingebaute, Chorkuma-spezifische Kategorien. Das Modul muss für jeden Chor passen.
- Eine Dublettenwarnung, die das Anlegen verhindert. Zwei Filialen derselben Kette können
  legitim zwei Sponsoren sein.
- Mehr Pflegeaufwand als in der Excel: Wer eine Vereinbarung aus einem Paket anlegt, soll
  Logo-Bedarf und Sponsor-Leistungen ohne weiteres Zutun mitbekommen.

---

## 1. Logo am Sponsor

### Was Georg entschieden hat

- Das Logo wird getrennt von den Listen behandelt, wegen der Fristen.
- Es hängt **am Sponsor**, nicht an der Vereinbarung, und bleibt für spätere Vereinbarungen.
- **Ersetzen** muss vorgesehen sein, falls sich das Logo ändert.
- Die Fristen sind **feste Daten am Projekt**.
- Die Frist hängt vom Paket ab: Druck (Plakat, Flyer) früh, online (Homepage, Social Media) spät.

### Umsetzung

**Logo-Datei.** Sponsoren haben heute schon Anhänge (Entitäts-Anhänge, `entity_type = 'sponsor'`).
Das Logo wird einer davon, als Logo gekennzeichnet; höchstens ein Logo je Sponsor. Ob das über
eine Spalte am Sponsor (`logo_attachment_id`) oder ein Kennzeichen am Anhang läuft, entscheidet
der Builder.
- Auf der Detailseite des Sponsors: eigener Bereich „Logo“ mit Vorschau, „Logo hochladen“ bzw.
  „Logo ersetzen“ und Löschen.
- **Rechte am Logo (Georg, 2026-10-23):**
  - Hochladen, ersetzen und ansehen darf, wer den Sponsor bearbeiten darf
    (`canEditSponsor`).
  - Dasselbe darf zusätzlich, wer bei einer **zugesagten** Vereinbarung (`accepted`) dieses
    Sponsors als zuständig eingetragen ist (`assigned_user_id`). Das ist die Person, die das
    Dashboard unter „Logo fehlt“ nennt.
  - Das neue Recht gilt **nur für das Logo**, nicht für die übrigen Anhänge des Sponsors.
    `SponsoringPolicy` und die Download-Prüfung in `AttachmentAccessRegistry` werden dafür
    erweitert, mit Rechte-Tests für beide Seiten.
  - *Meine Entscheidung:* Löschen ohne Ersatz bleibt bei `canEditSponsor`.
- *Meine Entscheidung:* Beim Ersetzen wird das alte Logo gelöscht und nicht zum normalen Anhang
  herabgestuft. Das hält „genau ein aktuelles Logo“ einfach.
- Erlaubte Formate: die bestehenden Bild-Typen der Anhänge plus SVG und PDF, wenn die
  Anhang-Validierung sie schon zulässt. Sonst nur Bilder. Die Prüfung bleibt die der Anhänge;
  das Logo bekommt keine eigene, lockerere Prüfung.

**Logo-Bedarf.** *Meine Entscheidung:* Der Bedarf wird nicht pro Gegenleistungs-Zeile markiert,
sondern mit zwei Häkchen:
- **„Logo für Druck“** (Plakat, Flyer, Programmheft, Aufsteller)
- **„Logo online“** (Homepage, Social Media, Video-Abspann)

Beide Häkchen gibt es am **Paket** als Vorlage und an der **Vereinbarung**. Eine neue
Vereinbarung aus einem Paket übernimmt sie als Kopie, genau wie heute die Gegenleistungen.
Grund: Die Gegenleistungen sind bewusst ein Textfeld, eine Zeile pro Leistung, weil sie meist
aus einem Angebotsschreiben kopiert werden (`App\Util\BenefitList`). Eine Markierung pro Zeile
würde diesen Weg zerstören.

**Fristen am Projekt.** Zwei neue, optionale Datumsfelder am Projekt:
- `logo_deadline_print`: Logo-Frist Druck
- `logo_deadline_online`: Logo-Frist online

*Meine Entscheidung zur Bearbeitung:*
- Die Felder stehen im Projektformular der Projektverwaltung.
- Zusätzlich kann sie setzen, wer `can_manage_sponsoring` hat, direkt im Sponsoring-Modul an der
  Liste „Logo fehlt“ bzw. pro Projekt. Grund: Die Fristen gehören dem Sponsoring-Team, das oft
  keine Projektrechte hat.
- Wie genau das im Sponsoring-Modul aussieht, entscheidet der Builder.

**Wann ein Logo „fehlt“.** Eine Vereinbarung zählt, wenn alle diese Bedingungen gelten:
- Ihr Status ist `accepted`. Bei Anfragen weiß man noch nicht, ob man ein Logo braucht.
  Abgeschlossene oder abgelehnte Vereinbarungen zählen nicht.
- Mindestens eines der Logo-Häkchen ist gesetzt.
- Ihr Sponsor hat kein Logo.

**Maßgebliche Frist** einer Vereinbarung:
- „Logo für Druck“ gesetzt → `logo_deadline_print` ihres Projekts
- sonst `logo_deadline_online`
- *Meine Entscheidung:* Fehlt bei „Logo für Druck“ die Druck-Frist, gilt ersatzweise die
  Online-Frist (Art „online“). Spätestens dann wird das Logo ja ohnehin gebraucht.
- fehlt dann immer noch ein Datum, oder hat die Vereinbarung kein Projekt: „ohne Frist“

**Dashboard: neuer Abschnitt „Logo fehlt“.**
- Je Zeile: Sponsor (verlinkt), Projekt, maßgebliche Frist, Art (Druck/online), zuständiges
  Mitglied (`assigned_user`).
- Sortiert nach Frist aufsteigend. „Ohne Frist“ steht am Ende.
- Eine überschrittene Frist ist deutlich hervorgehoben, eine Frist innerhalb von 14 Tagen
  schwächer. *Die 14 Tage sind meine Entscheidung.*
- Ein Sponsor mit mehreren betroffenen Vereinbarungen erscheint **einmal**, mit der frühesten
  Frist.
- *Meine Entscheidung zur Sichtbarkeit:* wie beim bestehenden Abschnitt der offenen
  Gegenleistungen (`SponsoringDashboardController::openBenefits()`): Alle mit Sponsoring-Zugang
  sehen die ganze Liste. Gefiltert wird nicht. Das gilt genauso für „Offene Leistungen des
  Sponsors“.

**Sponsorenliste.** Ein kleines Kennzeichen „Logo vorhanden“ bzw. „Logo fehlt“ in der Liste
wäre nützlich. Ob als Spalte oder Symbol am Namen, entscheidet der Builder.

## 2. Leistungen des Sponsors

### Was Georg entschieden hat

Banner/Beachflag und Ähnliches werden **allgemein** geführt, so wie die Gegenleistungen, nicht
als einzelne Sonderfelder.

### Umsetzung

Eine zweite Liste neben den Gegenleistungen, gleich aufgebaut:

| | Gegenleistungen (vorhanden) | Leistungen des Sponsors (neu) |
|---|---|---|
| Vorlage am Paket | `sponsor_package_benefits` | `sponsor_package_deliverables` |
| Kopie an der Vereinbarung | `sponsorship_benefits` | `sponsorship_deliverables` |
| Eingabe | Textfeld, eine Zeile je Leistung (`BenefitList`) | genauso |
| Abhaken | `delivered_at`, `delivered_by_user_id` | `received_at`, `received_by_user_id` |
| Bezeichnung im UI | „Gegenleistungen“ / „erbracht“ | „Leistungen des Sponsors“ / „erhalten“ |

Beispiele: „Banner/Beachflag übergeben“, „Vertrag unterschrieben zurück“, „Druck der Flyer“,
„Fotografieren beim Konzert“.

- Abhaken darf, wer Gegenleistungen abhaken darf (`SponsoringPolicy::canTickBenefits`).
- Das Abgleichen beim Bearbeiten (Zeilen behalten, deren Text gleich bleibt, samt Haken) folgt
  `SponsorshipController::syncBenefits`.
- Dashboard: Abschnitt „Offene Leistungen des Sponsors“, aufgebaut wie der bestehende Abschnitt
  der offenen Gegenleistungen, nur für Vereinbarungen mit Status `accepted`.
- `BenefitList` wird so verallgemeinert, dass beide Listen sie nutzen. Die Fehlermeldungen
  nennen die jeweilige Liste und sprechen nicht pauschal von „Gegenleistung“.

**Spendenbescheinigung.** *Meine Entscheidung:* Kein eigenes Feld. Wir stellen sie aus, also ist
sie eine Gegenleistung. Möchte der Sponsor eine, kommt sie als Zeile in die Gegenleistungen und
wird abgehakt, wenn sie ausgestellt ist.

**Sachleistung.** Wird als Zeile in „Leistungen des Sponsors“ geführt. Der Betrag bleibt ein
Geldbetrag; eine reine Sachleistung hat weiterhin 0 €.

**Bestandsdaten.** Bestehende Vereinbarungen und Pakete bekommen keine Leistungen des Sponsors
und keine Logo-Häkchen. Woraus sie bestanden, ließe sich nur raten. Das ist dieselbe Haltung wie
in der Migration `20261012120000_add_sponsoring_benefits_and_payment`.

## 3. Kategorie am Sponsor

### Was Georg entschieden hat

- **Keine Chorkuma-spezifischen Werte** im Code: Das Modul ist allgemein.
- Es gibt **ein paar allgemeine Standard-Kategorien**.
- Vorhandene Werte sind **aus einem Dropdown wählbar**, aber **direkt dort erweiterbar**.

### Umsetzung

- Eigene Tabelle `sponsor_categories` (`id`, `name` eindeutig, ohne Rücksicht auf Groß- und
  Kleinschreibung). Am Sponsor kommt `category_id` dazu, optional, beim Löschen der Kategorie
  `SET NULL`.
- *Meine Entscheidung:* Eine Tabelle statt Freitext am Sponsor. So werden „Gastronomie“ und
  „gastronomie“ nicht zu zwei Werten, und eine spätere Pflegeseite kann umbenennen, ohne jeden
  Sponsor anzufassen.
- **Standard-Kategorien** legt die Migration an. Sie sind allgemein und können später
  umbenannt oder gelöscht werden. *Die Auswahl ist mein Vorschlag:* Einzelhandel, Gastronomie,
  Handwerk, Dienstleistung, Banken & Versicherungen, Öffentliche Einrichtungen, Kultur, Medien,
  Privatperson.
- **Eingabe im Sponsor-Formular:** ein Auswahlfeld mit allen vorhandenen Kategorien plus der
  Möglichkeit, einen neuen Namen einzutippen.
  - Beim Speichern wird ein neuer Name angelegt. Gibt es ihn schon in anderer Schreibweise,
    wird der vorhandene verwendet.
  - Leer heißt „ohne Kategorie“.
  - Ob das als `<input list>` mit `<datalist>` oder als kleines Combobox-Skript umgesetzt wird,
    entscheidet der Builder. Lokal ausgeliefert, kein Inline-JS.
- Neue Kategorien anlegen darf, wer den Sponsor bearbeiten darf.
- **Sponsorenliste:** Kategorie als Spalte und als Filter.

## 4. Dublettenwarnung

### Umsetzung

- Beim **Anlegen** und beim **Umbenennen** eines Sponsors wird gegen die vorhandenen Namen
  geprüft.
- **Vergleich über einen normalisierten Namen:**
  - Kleinschreibung.
  - Satzzeichen und mehrfache Leerzeichen raus.
  - Gängige Rechtsformen am Ende entfernt: GmbH, GmbH & Co KG, KG, OG, AG, e.U., e.V., Co.
  - „Bäckerei Huber GmbH“ und „bäckerei huber“ gelten als gleich.
  - Die genaue Liste der Rechtsformen ist Sache des Builders und landet als Konstante in einer
    Hilfsklasse, mit Test.
- **Treffer heißt Hinweis, keine Sperre.** Das Formular kommt mit seinen Eingaben zurück und
  zeigt: „Es gibt bereits einen ähnlichen Sponsor: <Name, verlinkt>“. Dazu den Knopf „Trotzdem
  anlegen“ bzw. „Trotzdem speichern“, der dasselbe Formular mit einer Bestätigung erneut
  absendet.
- Ein Sponsor ist keine Dublette seiner selbst. Beim Bearbeiten ohne Namensänderung wird nicht
  geprüft.
- *Meine Entscheidung:* Keine unscharfe Ähnlichkeit (Tippfehler-Abstand). Nur der normalisierte
  Vergleich. Das bleibt erklärbar und erzeugt keine Fehlalarme bei „Huber“ und „Hubert“.
- Der Sponsor-Import über SQL ist davon nicht berührt.

---

## Rahmen

- **Schema** nur über Phinx-Migrationen (`/phinx-migration`), destruktive Rückwege abgesichert
  wie in `20261012120000_add_sponsoring_benefits_and_payment::down()`.
- **Tests zuerst** (TDD) für jede der vier Funktionen: Hauptweg sowie Fehler- und Randfälle, unter
  anderem Logo ersetzen, Frist ohne Projekt, mehrere Vereinbarungen eines Sponsors, Kategorie in
  anderer Schreibweise, Dublette mit Rechtsform, Bestätigen trotz Dublette.
- **Seed-Daten** (`/dev-seed-completeness`):
  - Kategorien an Sponsoren
  - Logos an einem Teil der Sponsoren
  - Logo-Häkchen an Paketen und Vereinbarungen
  - Fristen an mindestens einem Projekt: eine überschritten, eine bald, eine später
  - Leistungen des Sponsors, teils erhalten
  - ein Sponsorpaar mit ähnlichem Namen
- **Rechte:** keine neuen Rollenrechte. Alles hängt an `can_manage_sponsoring` und
  `can_create_own_sponsorships` über die bestehende `SponsoringPolicy`.
- **Stil:** Bezeichner englisch, UI deutsch, Twig nach `instructions/twig-style.md`, kein
  Inline-CSS/-JS, mobil benutzbar.
- **Logging:** Logo hochgeladen, ersetzt oder gelöscht mit stabilem `event`-Key, wie bei den
  bestehenden Anhang-Ereignissen.

## Dem Builder überlassen

- Speicherort der Logo-Kennzeichnung (Spalte am Sponsor oder Kennzeichen am Anhang).
- Wo im Sponsoring-Modul die Projekt-Fristen gesetzt werden.
- Darstellung von „Logo vorhanden/fehlt“ in der Sponsorenliste.
- Datalist oder Combobox für die Kategorie.
- Genaue Liste der Rechtsformen für den Namensvergleich.
- Reihenfolge der Umsetzung. Die vier Teile sind unabhängig. Ein sinnvoller Schnitt ist ein
  Commit je Teil.

## Offen für später

- Pflegeseite für Kategorien (umbenennen, zusammenführen, löschen).
- Zählung der Gegenleistungen je Art für den Druck („9 × Logo im Programmheft“).
- Teilzahlungen (heute nur bezahlt oder nicht bezahlt).
