# Kasse: mehrere Gruppen pro Buchung und Stapelbearbeitung

Stand: 2026-11-22

## Absicht und Warum

Eine Buchung in der Kasse hat heute genau eine Gruppe. Diese Gruppe beantwortet zwei
Fragen gleichzeitig: „wofür“ (Kostenart wie *Notenmaterial*, *Raummiete*) und „wozu“
(Anlass oder Projekt wie *Herbstkonzert 2026*, *Chorreise Wien*). Wer beides festhalten
will, kann es nicht.

Künftig soll eine Buchung **mehrere Gruppen** tragen können. Ein zweites Feld für den
Anlass kommt bewusst nicht in Frage: Man müsste sich immer um zwei Felder kümmern, und
beim Kontoauszug-Import mit vielen Buchungen macht das niemand.

Damit Gruppen nachträglich bequem gepflegt werden können – vor allem nach einem Import –,
gibt es eine **Stapelbearbeitung**: mehrere Buchungen markieren, dann eine Gruppe
hinzufügen oder entfernen.

## Ziele

- Eine Buchung kann null, eine oder mehrere Gruppen haben.
- Buchungsdialog, Import-Vorschau und Stapelbearbeitung können Gruppen setzen; im Dialog
  und im Import lassen sich neue Gruppen wie bisher direkt anlegen.
- Stapelbearbeitung in der Buchungsliste: Gruppe zu den markierten Buchungen hinzufügen
  oder von ihnen entfernen. Andere Gruppen der Buchungen bleiben unberührt.
- Das Budget zählt keine Buchung doppelt.
- Jede Gruppenänderung landet wie bisher im Journal; gesperrte Zeiträume bleiben gesperrt.

## Nicht-Ziele

- Kein Aufteilen eines Betrags auf mehrere Gruppen (kein Splitting).
- Kein eigenes Anlass- oder Schlagwort-Konzept neben den Gruppen, keine Verknüpfung mit
  den Projekten von ChorManager.
- Keine Gruppenverwaltung (Umbenennen, Zusammenführen, Löschen) – bleibt wie heute.
- Keine Budgets pro Anlass als eigene Ansicht.
- Kein Hilfethema (nur auf ausdrückliche Anforderung, siehe AGENT.md).

## Anti-Ziele

- Budget-Ist-Werte, die stillschweigend falsch sind (doppelt gezählte Buchungen).
- Eine zweite, abgeleitete Wahrheit, die nachgezogen werden muss (z. B. eine gespeicherte
  „Budget-Gruppe“ an der Buchung).
- Eine Stapelbearbeitung, die Buchungen in abgeschlossenen Zeiträumen verändert oder
  Fehlschläge verschweigt.

## Fachregel: höchstens eine budgetrelevante Gruppe

Budgets werden **vorab** und **nach Kostenarten** geplant. Ein Anlass kann aber
kurzlebig selbst eine Kostenart sein (z. B. *Chorreise Wien* mit eigener Budget-Kategorie
nur im Jahr 2025).

- **Budgetrelevant** ist eine Gruppe in einem Geschäftsjahr genau dann, wenn es in diesem
  Geschäftsjahr eine Budget-Kategorie mit dieser Gruppe gibt – unabhängig von der Art
  (Einnahme/Ausgabe). Die Art bleibt bewusst außen vor, damit die Regel erklärbar ist.
- Das **Geschäftsjahr der Buchung** ergibt sich aus dem Zahldatum; bei offenen Posten
  (ohne Zahldatum) aus dem Rechnungsdatum. Grundlage ist die bestehende
  Geschäftsjahr-Logik von Budget/Kasse (Beginn des Geschäftsjahres aus den Einstellungen).
- **Eine Buchung darf in ihrem Geschäftsjahr höchstens eine budgetrelevante Gruppe
  tragen.** Beliebig viele nicht budgetrelevante Gruppen sind erlaubt.
- Geprüft wird bei jedem Schreibweg, der Gruppen oder das Datum einer Buchung ändert:
  Speichern im Dialog, Import, Stapelbearbeitung. Auch eine Datumsänderung, die die
  Buchung in ein anderes Geschäftsjahr schiebt, wird gegen dieses Jahr geprüft.
- Verstößt eine Eingabe dagegen, wird sie abgewiesen; die Meldung nennt die beiden
  kollidierenden Gruppen und das Geschäftsjahr.
- *Entscheidung Umsetzung:* Geprüft wird nur, wenn sich die Gruppen oder das
  Geschäftsjahr der Buchung tatsächlich ändern. Wer eine alte Buchung unverändert
  speichert, scheitert nicht an einem Konflikt, der ohne sein Zutun entstanden ist.
- *Entscheidung Umsetzung:* **Storno ist von der Regel ausgenommen.** Die Gegenbuchung
  übernimmt die Gruppen des Originals auch dann, wenn sie in ein anderes Geschäftsjahr
  wandert. Storno-Paare zählen im Budget-Ist ohnehin nicht (`computeActual()`), können
  also nicht doppelt zählen; ein blockiertes Storno wäre ein Rückschritt.
- *Entscheidung Umsetzung:* **Import:** Die Gruppen werden in der Import-Vorschau pro Zeile
  gewählt und erst beim Bestätigen geprüft. Verstößt eine Zeile gegen die Regel, wird der
  ganze Import abgewiesen (eine Transaktion wie heute). Die Vorschau öffnet wieder mit den
  bisherigen Eingaben, und die betroffenen Zeilen sind mit der Meldung markiert. Kein
  stilles Überspringen einzelner Zeilen, denn eine fehlende Buchung aus dem Kontoauszug
  fällt sonst niemandem auf.
- *Entscheidung Umsetzung:* Ändert sich der Beginn des Geschäftsjahres in den
  Einstellungen, können dadurch nachträglich Konflikte entstehen. Das wird hingenommen.
  Die Einstellung wird weder gesperrt noch geprüft, denn sie ändert sich praktisch nie.

### Anlegen oder Ändern einer Budget-Kategorie wird blockiert

Wird für eine Gruppe in einem Geschäftsjahr eine Budget-Kategorie angelegt (oder eine
bestehende Kategorie auf eine andere Gruppe umgehängt), und gibt es in diesem Jahr
Buchungen, die diese Gruppe **und** eine andere dort budgetrelevante Gruppe tragen, wird
das Anlegen abgewiesen. Die Meldung nennt die Anzahl und verlinkt die Buchungsliste so,
dass die betroffenen Buchungen auffindbar sind. Bereinigt wird per Stapelbearbeitung.

*Entscheidung Umsetzung:* Stornierte Buchungen und Storno-Gegenbuchungen zählen dabei
nicht als Konflikt. Sie fließen nicht ins Budget-Ist ein und lassen sich per
Stapelbearbeitung ohnehin nicht bereinigen.

*Entscheidung Umsetzung:* Die Prüfung läuft nicht im `BudgetController`. Dessen
Schreibwege für Kategorien (`createCategory`, `updateCategory`) ziehen dabei in einen
`BudgetWriteService` um. Das verlangt `instructions/data-access.md` ohnehin für jeden
Controller, der angefasst wird: Die Einträge in der Sperrklinke werden gestrichen.

Begründung: Budgets entstehen vorab, der Fall ist selten; falsche Ist-Werte sind
schlimmer als ein zusätzlicher Aufräumschritt.

Folge: Liegen die Konfliktbuchungen in einem abgeschlossenen Zeitraum, lässt sich diese
Kategorie für dieses Jahr nicht anlegen. Das ist hingenommen.

## Wirkung auf bestehende Auswertungen

- **Budget-Ist** (`BudgetService::computeActual()` und seine Gegenprobe für Stornos):
  summiert Buchungen, die der Gruppe zugeordnet sind. Durch die Fachregel zählt jede
  Buchung in höchstens einer Kategorie des Jahres.
- **Bericht** (Summen je Gruppe, auch PDF): Eine Buchung zählt in jeder ihrer Gruppen voll.
  Unter der Gruppentabelle steht ein Hinweis, dass sich Gruppensummen überschneiden
  können und ihre Summe deshalb vom Kassenergebnis abweichen kann. Buchungen ohne Gruppe
  bleiben unter „(Keine Gruppe)“.
- **Buchungsliste**: Die Spalte „Gruppe“ zeigt alle Gruppen als Badges. Suche und Filter
  der Tabellenleiste sowie die „Summe der Treffer“ müssen mit mehreren Gruppen pro Zeile
  funktionieren. Sortierung nach Gruppe: nach den alphabetisch verketteten Namen.
- **CSV-Export**: eine Spalte „Gruppe“, mehrere Gruppen mit `; ` getrennt, alphabetisch.
- **Journal**: Gruppenänderungen werden als ein Feld „Gruppen“ protokolliert, Vorher- und
  Nachher-Wert als alphabetische Namensliste. Alte Journaleinträge mit `group_name` /
  `finance_group_id` müssen weiterhin lesbar angezeigt werden.
- **Storno**: Die Gegenbuchung übernimmt alle Gruppen des Originals.

## Stapelbearbeitung

- Ort: Buchungsliste der Kasse (gebuchte Posten und offene Posten), nur für Nutzer mit
  Schreibrecht auf die Kasse.
- Ablauf: Buchungen per Checkbox markieren (einzeln und „alle sichtbaren“ – also die von
  Suche/Filter übrig gelassenen), dann Aktion „Gruppe hinzufügen“ oder „Gruppe
  entfernen“ wählen und eine Gruppe angeben. Beim Hinzufügen kann eine neue Gruppe
  angelegt werden. Eine Gruppe pro Aktion.
- Hinzufügen einer Gruppe, die die Buchung schon hat, und Entfernen einer Gruppe, die sie
  nicht hat, sind keine Änderung und erzeugen keinen Journaleintrag.
- **Übersprungen** (nicht geändert) werden Buchungen,
  - deren Zeitraum abgeschlossen ist,
  - die storniert sind oder selbst eine Storno-Gegenbuchung sind,
  - bei denen das Hinzufügen eine zweite budgetrelevante Gruppe ergäbe.
- Die Ergebnismeldung nennt die Anzahl geänderter Buchungen und je Grund die Anzahl
  übersprungener, möglichst mit den laufenden Nummern.
- Jede geänderte Buchung bekommt ihren eigenen Journaleintrag mit dem handelnden Nutzer.
- Die Aktion läuft in einer Transaktion: Entweder werden alle nicht übersprungenen
  Buchungen geändert oder keine.
- Die Oberfläche (Markierung, Aktionsleiste, Gruppenauswahl, Ergebnismeldung, Verhalten
  auf dem Handy) wird mit dem Impeccable-Skill entworfen und gebaut.

## Gewählter Ansatz und Alternativen

**Gewählt: Zuordnungstabelle.** Eine neue Tabelle hält die Paare Buchung–Gruppe (beide
Fremdschlüssel, eindeutig je Paar). Eine Migration übernimmt die bestehende Einzelgruppe
jeder Buchung; danach entfallen `finances.group_name` und `finances.finance_group_id`.
Alle lesenden Stellen gehen über die Zuordnung. Eine Wahrheit, einfache Abfragen,
Datenbank sichert die Eindeutigkeit ab.

Verworfen:

- **Zuordnungstabelle plus gespeicherte Budget-Gruppe an der Buchung.** Hätte die
  Budget-Abfrage unverändert gelassen, aber die Budgetrelevanz hängt von Jahr und
  bestehenden Kategorien ab; die Spalte müsste bei jeder neuen Kategorie und jeder
  Datumsänderung nachgezogen werden – doppelte Wahrheit.
- **Gruppen als JSON-Liste in einer Spalte.** Kleinste Migration, aber Budget-Summen,
  Filter und Konfliktprüfung werden umständlich und langsam, keine referentielle
  Absicherung.
- **Zweites Feld „Anlass“ neben der Gruppe.** Löst das Doppelzählen ohne Regel, aber man
  muss zwei Felder pflegen; beim Import passiert das nicht.
- **Buchungen an ChorManager-Projekte hängen.** Nicht jeder Anlass ist ein Projekt, und in
  der Kasse ließe sich kein neuer Anlass anlegen.
- **Regel „budgetrelevant, sobald je in irgendeinem Budget“.** Einfacher, sperrt aber
  kurzlebige Kostenarten (z. B. *Chorreise Wien*) für immer gegen andere Budget-Gruppen.

## Randbedingungen

- Schemaänderung nur per Phinx-Migration (`/phinx-migration`); der Datenübertrag der
  bestehenden Gruppen ist Teil der Migration und darf keine Zuordnung verlieren.
- Schreibwege nicht im Controller (`instructions/data-access.md`): Gruppen-Schreiben und
  Stapelbearbeitung gehören in den bestehenden `FinanceBookingService` (Fachregeln:
  Periodensperre, Journal, Budget-Regel). Listenabfragen in eine Query-Klasse.
- Seed-Daten (`/dev-seed-completeness`): Buchungen mit mehreren Gruppen, darunter
  Kostenart + Anlass; mindestens ein Anlass mit Budget-Kategorie nur in einem Jahr.
- Tests vor der Umsetzung (TDD), Schärfeprobe für die Budget-Regel und die
  Sperr-/Storno-Ausnahmen der Stapelbearbeitung.
- Keine Inline-Skripte/-Styles, nur lokale Assets; Twig- und PHP-Stil wie in AGENT.md.
- Rechte: Lesen wie bisher `requiresFinanceRead`, alle Schreibwege einschließlich
  Stapelbearbeitung `requiresFinanceWrite`-Gruppe der Routen.

## Dem Umsetzer überlassen

- Name der Zuordnungstabelle und der Eloquent-Beziehung.
- Wie die Gruppen-Mehrfachauswahl im Buchungsdialog und in der Import-Vorschau
  aussieht (im Rahmen der Impeccable-Gestaltung; bestehende lokale Komponenten bevorzugt).
- Wie der Link aus der Budget-Sperrmeldung die betroffenen Buchungen auffindbar macht.
- Aufteilung der Budget-Regel in eine eigene, testbare Klasse oder Methode.
