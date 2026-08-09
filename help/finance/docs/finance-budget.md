# Budget planen und mit dem Kassabuch vergleichen

Im Budget planst du je Gruppe die erwarteten Einnahmen und Ausgaben eines
Haushaltsjahres und stellst diesen Planwerten das tatsächliche **Ist** aus dem
Kassabuch gegenüber. So siehst du auf einen Blick, wo du im Plan liegst und wo
nachgesteuert werden muss.

> **Berechtigung:** Der Menüpunkt **Budget** ist sichtbar, wenn das Budget-Modul
> aktiv ist und deine Rolle ein Finanz-Leserecht (**"Finanzen nur lesen"** oder
> **"Finanzen lesen und schreiben"**) oder das Recht **"Budget verwalten"** hat.
> **Kategorien und Posten anlegen, bearbeiten oder löschen** kannst du nur mit
> dem Recht **"Budget verwalten"** – ohne dieses Recht siehst du das Budget nur.
> Fehlt der Menüpunkt oder eine Schaltfläche, frag den Administrator unter
> **Verwaltung → Rollen**.

## 1. Übersicht

Klickpfad: **Bereiche → Budget**. Die Seite ist in **Einnahmen** und **Ausgaben**
geteilt. Jede Zeile ist eine Budgetkategorie mit **Geplant**, **Ist** und der
**Differenz**. Unten je Abschnitt stehen die Summen. Oben rechts wählst du im
Feld **Jahr** das Haushaltsjahr.

![Budget-Übersicht mit Einnahmen und Ausgaben](images/finance/05-budget-overview.png)

Das **Ist** wird automatisch aus den Buchungen im Kassabuch berechnet: Es zählen
alle Buchungen der verknüpften Gruppe, die ins gewählte Haushaltsjahr fallen. Es
gibt also keine doppelte Erfassung – du planst im Budget, buchst im Kassabuch,
und der Abgleich passiert von selbst.

## 2. Kategorie aufklappen

Ein Klick auf den Kategorienamen klappt die Kategorie auf und zeigt die
einzelnen **Posten** mit ihren geplanten Beträgen. Die Summe der Posten ergibt
den Planwert der Kategorie.

![Aufgeklappte Kategorie mit Posten](images/finance/06-budget-category-expanded.png)

## 3. Posten hinzufügen

Innerhalb einer aufgeklappten Kategorie legst du mit **"Posten hinzufügen"**
einen einzelnen Planposten an (Bezeichnung und geplanter Betrag). Über
**"Bearbeiten"** änderst du einen Posten, über das Aufklapp-Menü löschst du ihn.

![Formular für einen neuen Budgetposten](images/finance/07-budget-new-item-modal.png)

## 4. Neue Kategorie anlegen

Mit **"Kategorie hinzufügen"** (oben rechts) erstellst du eine neue
Budgetkategorie. Du wählst das **Haushaltsjahr**, den **Typ** (Einnahme oder
Ausgabe) und die **Gruppe**. Über die Gruppe wird das Budget mit den Buchungen
dieser Gruppe im Kassabuch verknüpft – daraus entsteht der Ist-Wert. Fehlt die
passende Gruppe, legst du sie direkt über "+ Neue Gruppe eingeben…" an.

![Formular für eine neue Budgetkategorie](images/finance/08-budget-new-category-modal.png)

## Häufige Stolperfallen

- **Ist bleibt 0.** Der Ist-Wert kommt ausschließlich aus dem Kassabuch. Ist er
  0, obwohl Buchungen existieren, ist die Kategorie mit der falschen Gruppe
  verknüpft oder die Buchungen liegen in einem anderen Haushaltsjahr. Prüfe die
  Gruppe der Kategorie und das gewählte Jahr.
- **Haushaltsjahr = Geschäftsjahr.** Der Zeitraum richtet sich nach demselben
  Geschäftsjahr-Beginn wie das Kassabuch. Diesen stellst du im Kassabuch unter
  **"Konfiguration"** ein (siehe [Finanzen](finance)).
- **Kategorie löschen ist endgültig.** Beim Löschen einer Kategorie werden auch
  alle ihre Posten entfernt. Die Buchungen im Kassabuch bleiben unberührt.
