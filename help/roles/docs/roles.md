# Rollen und Rechte

Über **Rollen** legst du fest, was Mitglieder in deiner Installation sehen und tun dürfen. Jede Rolle hat einen Namen, ein Hierarchie-Level (0–100) und eine Reihe von Einzelrechten, die du unabhängig voneinander ein- oder ausschalten kannst. Ein Mitglied kann mehrere Rollen haben – es erhält dann die Vereinigung aller zugehörigen Rechte.

> **Berechtigung:** Dieses Modul ist nur sichtbar, wenn deine Rolle das Recht **"Rollen verwalten"** hat.
> Siehst du den Menüpunkt nicht, frag den Administrator unter **Verwaltung → Rollen**.

## 1. Berechtigungsmatrix

Unter **Verwaltung → Rollen** siehst du alle Rollen deiner Installation nebeneinander in einer Matrix: Jede Spalte ist eine Rolle, jede Zeile ein Recht. Ein grüner Haken zeigt, dass die Rolle dieses Recht besitzt, ein rotes Kreuz, dass sie es nicht hat. Zusätzlich zeigt die Matrix pro Rolle die Anzahl aktiver Mitglieder und bietet einen direkten Zugang zum Bearbeiten.

![Berechtigungsmatrix mit allen Rollen und Rechten](images/roles/01-permission-matrix.png)

## 2. Neue Rolle anlegen

Über den Button **"Neue Rolle"** öffnest du ein Formular, in dem du einen Rollennamen, ein Hierarchie-Level sowie die gewünschten Rechte per Schalter aktivierst. Höhere Hierarchie-Level stehen typischerweise für Leitungsfunktionen, während ein einfaches Mitglied oft Level 0 hat.

Das Hierarchie-Level vergibt **keine** Rechte. Es hat genau eine Aufgabe: Wer ein Mitglied bearbeiten oder ihm Rollen zuweisen will, kommt an niemanden heran, der eine höher eingestufte Rolle besitzt – und kann auch keine Rolle oberhalb des eigenen Levels anlegen, bearbeiten oder vergeben.

![Formular zum Anlegen einer neuen Rolle](images/roles/02-new-role-modal.png)

## 3. Rolle bearbeiten

Über den Button **"Bearbeiten"** in der Matrix öffnest du dasselbe Formular für eine bestehende Rolle vorausgefüllt. Hier kannst du Name, Hierarchie-Level und alle Einzelrechte jederzeit anpassen – Änderungen wirken sich sofort auf alle Mitglieder mit dieser Rolle aus.

![Formular zum Bearbeiten einer bestehenden Rolle](images/roles/03-edit-role-modal.png)

## 4. Rolle löschen

Der Button **"Löschen"** erscheint nur bei Rollen, denen aktuell kein einziges Mitglied zugewiesen ist – archivierte Mitglieder zählen dabei mit, damit nach einer Wiederherstellung niemand ohne Rolle dasteht. Ist die Rolle noch vergeben, entziehe sie zuerst in der Mitgliederverwaltung. Auch beim Löschen gilt die Level-Grenze: Rollen oberhalb des eigenen Hierarchie-Levels lassen sich nicht entfernen.

![Sicherheitsabfrage vor dem Löschen einer Rolle](images/roles/04-delete-role-modal.png)

## Die einzelnen Rechte im Detail

### Mitgliederverwaltung erlauben

Dieses Recht schaltet die Mitgliederverwaltung frei: Mitglieder anlegen, bearbeiten, einladen und archivieren sowie ihnen Rollen zuweisen. Zuweisbar sind dabei nur Rollen bis zum eigenen Hierarchie-Level, und Mitglieder mit einer höher eingestuften Rolle bleiben unantastbar. Es gilt bewusst nur für diesen Bereich – Rollen selbst zu bearbeiten, Projekte, Termine, Finanzen und alle anderen Module verlangen ihr jeweils eigenes Recht.

### Rollen verwalten

Mit diesem Recht darf die Rolle neue Rollen anlegen und bestehende Rollen bearbeiten. Wer Rollen bearbeiten darf, kann jedes Recht der Installation vergeben – auch an die eigene Rolle und damit an sich selbst. Es ist deshalb das mächtigste Recht überhaupt und sollte nur an wenige, ausdrücklich vertrauenswürdige Rollen gehen. Die einzige Grenze ist das Hierarchie-Level: Rollen oberhalb des eigenen Levels lassen sich weder anlegen noch bearbeiten.

Das Zuweisen von Rollen an Mitglieder gehört dagegen zur **"Mitgliederverwaltung erlauben"** – beide Rechte sind unabhängig voneinander vergebbar.

### Mitglieder editieren erlauben

Mit diesem Recht darf die Rolle die Zuweisungen anderer Mitglieder bearbeiten, also z. B. Stammdaten, Rollen- oder Stimmgruppenzuordnungen anderer Personen ändern. Es ist schwächer als die Mitgliederverwaltung, weil es keinen Zugriff auf globale Einstellungen wie Rollen oder Projekte selbst gibt, sondern nur auf die Bearbeitung einzelner Mitgliederdaten.

### Projektmitglieder verwalten

Ist dieses Recht aktiv, darf die Rolle Mitglieder einzelnen Projekten (z. B. einem Konzertprojekt) zuordnen oder aus ihnen entfernen. Damit steuert diese Rolle, wer als Teilnehmer in einem Projekt geführt wird, ohne dass sie zwangsläufig auch globale Mitgliederverwaltung besitzen muss.

### Eigene Stimmgruppe ins Projekt zuweisen

Dieses Recht ist die auf die eigene Stimmgruppe beschränkte Variante der Projektmitglieder-Verwaltung. Ist es aktiv, darf die Rolle ausschließlich Mitglieder ihrer eigenen Stimmgruppe den Projekten zuordnen oder daraus entfernen, in denen sie selbst mitwirkt. Die Auswahlliste zeigt dabei nur Personen der eigenen Stimmgruppe – fremde Stimmgruppen bleiben unsichtbar und können weder hinzugefügt noch entfernt werden. Es eignet sich für Personen, die die Besetzung ihrer eigenen Stimmgruppe im Projekt pflegen sollen, ohne Zugriff auf den gesamten Chor zu erhalten. Wer die volle „Projektmitglieder verwalten"-Berechtigung besitzt, braucht dieses Recht nicht zusätzlich.

### Termine verwalten

Mit diesem Recht darf die Rolle Termine anlegen, bearbeiten und löschen, öffentliche Bemerkungen zu Terminen moderieren und die verfügbaren Termin-Typen pflegen. Das macht diese Rolle zur zentralen Ansprechperson für die Terminplanung des Chors.

### Anwesenheit/Anmeldung verwalten (eigene Stimmgruppe)

Dieses Recht erlaubt der Rolle, Anwesenheiten und Anmeldungen für die eigene Stimmgruppe zu erfassen und nachträglich zu aktualisieren. Es ist unabhängig von der Terminverwaltung nutzbar, sodass z. B. eine Stimmgruppenleitung nur Anwesenheiten der eigenen Gruppe pflegen kann, ohne Termine selbst anlegen zu dürfen oder Zugriff auf andere Stimmgruppen zu erhalten.

Sichtbar sind dabei nur Termine, zu deren Zielgruppe man selbst gehört oder in denen mindestens ein Mitglied der eigenen Stimmgruppe eingeladen ist – Termine ganz anderer Gruppen tauchen weder in der Anwesenheits- noch in der Anmeldeliste auf.

### Anwesenheit/Anmeldung verwalten (alle Mitglieder)

Dieses Recht erweitert das vorige: Die Rolle sieht und pflegt Anwesenheiten und Anmeldungen für alle Mitglieder und für jeden Termin, nicht nur für die eigene Stimmgruppe. Es eignet sich für Personen mit Überblick über den gesamten Chor, z. B. für die Terminkoordination.

### Eigene Stimmgruppe verwalten

Ist dieses Recht aktiv, darf die Rolle die Mitglieder der eigenen Stimmgruppe pflegen (anlegen, bearbeiten, einladen, archivieren) und für sie Anmeldungen als Vertretung eintragen – unabhängig vom Hierarchie-Level der Rolle. Damit können auch Rollen mit niedrigem Level Verantwortung für ihre eigene Gruppe übernehmen, ohne Zugriff auf andere Stimmgruppen zu erhalten. Für die Anwesenheitsliste selbst braucht es zusätzlich eines der beiden Anwesenheitsrechte.

### Finanzen nur lesen

Mit diesem Recht darf die Rolle das Finanzmodul einsehen, Auswertungen ansehen und Anhänge zu Finanzeinträgen öffnen – jedoch nichts verändern. Es eignet sich für Personen, die einen Überblick über die Finanzlage benötigen, ohne selbst buchen zu dürfen.

### Finanzen lesen und schreiben

Dieses Recht erweitert das reine Leserecht: Die Rolle darf Finanzeinträge und die zugehörigen Konfigurationen anlegen, bearbeiten und löschen. Es ist typischerweise der Kassierin, dem Kassier oder vergleichbaren Positionen vorbehalten, die die Finanzbuchhaltung des Chors führen.

### Budget verwalten

Ist dieses Recht aktiv, darf die Rolle den Budget-Bereich einsehen und bearbeiten, also Budgetkategorien und -posten pflegen. Es ist unabhängig von den Finanzrechten und erscheint nur, wenn das Budget-Modul in den Einstellungen aktiviert ist.

### Sponsoring verwalten

Mit diesem Recht darf die Rolle das Sponsoring-Modul sehen sowie Sponsoren, Vereinbarungen und Kontakthistorien verwalten. Es erscheint nur, wenn das Sponsoring-Modul für die Installation aktiviert ist, und richtet sich an Personen, die Sponsorenbeziehungen pflegen.

### Repertoire verwalten

Dieses Recht erlaubt der Rolle, Lieder und die zugehörigen Dateien pro Projekt zu verwalten – also z. B. Noten oder Audiodateien hochzuladen und Liedlisten zu pflegen. Es betrifft das Kernrepertoire, unabhängig vom separaten Notenarchiv.

### Notenarchiv verwalten

Ist dieses Recht aktiv, darf die Rolle den Notenarchiv-Bereich innerhalb des Repertoires einsehen und bearbeiten. Es ist ein eigenständiges Recht neben der Repertoireverwaltung und erscheint nur, wenn das Notenarchiv-Modul aktiviert ist – so kann z. B. eine Notenwartin das Archiv pflegen, ohne automatisch auch die Liedlisten der Projekte verwalten zu dürfen.

### Newsletter verwalten

Mit diesem Recht darf die Rolle Newsletter erstellen, bearbeiten und verschicken. Es erscheint nur, wenn das Newsletter-Modul aktiviert ist, und eignet sich für Personen, die die Kommunikation nach außen bzw. an die Mitglieder verantworten.

### Mailversand verwalten

Dieses Recht gibt der Rolle Einblick in die Mail-Queue des Systems und erlaubt es, fehlgeschlagene Zustellungen (Dead-Letter) erneut zu versenden. Es richtet sich an technisch verantwortliche Personen, die den E-Mail-Versand der Installation überwachen.

### Projektplanung (Aufgaben)

Ist dieses Recht aktiv, darf die Rolle Aufgaben verwalten – auch projektübergreifend. Es erscheint nur, wenn das Aufgaben-Modul aktiviert ist, und eignet sich für Personen, die die Projektplanung koordinieren.

### Stammdaten verwalten

Mit diesem Recht darf die Rolle grundlegende Stammdaten der Installation ansehen und speichern: Projekte, Stimmgruppen und App-Einstellungen. Mehr nicht – es schaltet keine Mitglieder-, Rollen- oder Modulrechte zusätzlich frei. Da hierüber auch App-Einstellungen verändert werden können, sollte es trotzdem sparsam vergeben werden.

### Backup-Verwaltung

Dieses Recht erlaubt der Rolle, Datenbank-Backups zu erstellen, herunterzuladen, zu löschen und wiederherzustellen. Da eine Wiederherstellung den gesamten Datenbestand ersetzen kann, ist dieses Recht besonders sensibel und sollte nur technisch verantwortlichen Personen zugewiesen werden.

## Häufige Stolperfallen

- **Level bestimmt Hierarchie, nicht Rechte** – ein hohes Hierarchie-Level allein schaltet nichts frei. Jedes Recht muss für jede Rolle einzeln aktiviert werden; das Level schützt nur höher eingestufte Mitglieder und Rollen vor Änderungen von unten.
- **"Rollen verwalten" ist das mächtigste Recht** – wer es besitzt, kann sich über die eigene Rolle jedes andere Recht selbst geben. Sparsam vergeben und regelmäßig in der Matrix prüfen, welche Rollen es haben.
- **Kein Recht schaltet ein anderes mit frei** – Mitgliederverwaltung öffnet keine Finanzen, Stammdaten öffnen keine Module. Fehlt einer Rolle etwas, muss genau dieses Recht angehakt werden.
- **Modulabhängige Rechte** – Rechte wie Budget, Sponsoring, Notenarchiv, Newsletter oder Aufgaben erscheinen nur, wenn das jeweilige Modul in den App-Einstellungen aktiviert ist.
