# Rollen und Rechte

Über **Rollen** legst du fest, was Mitglieder in deiner Installation sehen und tun dürfen. Jede Rolle hat einen Namen, ein Hierarchie-Level (0–100) und eine Reihe von Einzelrechten, die du unabhängig voneinander ein- oder ausschalten kannst. Ein Mitglied kann mehrere Rollen haben – es erhält dann die Vereinigung aller zugehörigen Rechte.

> **Berechtigung:** Dieses Modul ist nur sichtbar, wenn deine Rolle das Recht **"Mitgliederverwaltung erlauben"** hat.
> Siehst du den Menüpunkt nicht, frag den Administrator unter **Verwaltung → Rollen**.

## 1. Berechtigungsmatrix

Unter **Verwaltung → Rollen** siehst du alle Rollen deiner Installation nebeneinander in einer Matrix: Jede Spalte ist eine Rolle, jede Zeile ein Recht. Ein grüner Haken zeigt, dass die Rolle dieses Recht besitzt, ein rotes Kreuz, dass sie es nicht hat. Zusätzlich zeigt die Matrix pro Rolle die Anzahl aktiver Mitglieder und bietet einen direkten Zugang zum Bearbeiten.

![Berechtigungsmatrix mit allen Rollen und Rechten](images/roles/01-permission-matrix.png)

## 2. Neue Rolle anlegen

Über den Button **"Neue Rolle"** öffnest du ein Formular, in dem du einen Rollennamen, ein Hierarchie-Level sowie die gewünschten Rechte per Schalter aktivierst. Höhere Hierarchie-Level (z. B. 80–100) stehen typischerweise für Vorstand und Chorleitung, während ein einfaches Mitglied oft Level 0 hat. Das Hierarchie-Level wird u. a. verwendet, um zu bestimmen, wer wen bearbeiten oder in Auswertungen sehen darf.

![Formular zum Anlegen einer neuen Rolle](images/roles/02-new-role-modal.png)

## 3. Rolle bearbeiten

Über den Button **"Bearbeiten"** in der Matrix öffnest du dasselbe Formular für eine bestehende Rolle vorausgefüllt. Hier kannst du Name, Hierarchie-Level und alle Einzelrechte jederzeit anpassen – Änderungen wirken sich sofort auf alle Mitglieder mit dieser Rolle aus.

![Formular zum Bearbeiten einer bestehenden Rolle](images/roles/03-edit-role-modal.png)

## Die einzelnen Rechte im Detail

### Mitgliederverwaltung erlauben

Dieses Recht ist das mächtigste Einzelrecht im System. Ist es aktiv, erhält die Rolle Zugriff auf die komplette globale Verwaltung: Rollen anlegen und bearbeiten, Projekte verwalten und Termine anlegen. Da dieses Recht selbst wieder Rechte vergeben kann, sollte es nur an wenige, vertrauenswürdige Rollen (z. B. Vorstand oder Administration) vergeben werden.

### Mitglieder editieren erlauben

Mit diesem Recht darf die Rolle die Zuweisungen anderer Mitglieder bearbeiten, also z. B. Stammdaten, Rollen- oder Stimmgruppenzuordnungen anderer Personen ändern. Es ist schwächer als die Mitgliederverwaltung, weil es keinen Zugriff auf globale Einstellungen wie Rollen oder Projekte selbst gibt, sondern nur auf die Bearbeitung einzelner Mitgliederdaten.

### Projektmitglieder verwalten

Ist dieses Recht aktiv, darf die Rolle Mitglieder einzelnen Projekten (z. B. einem Konzertprojekt) zuordnen oder aus ihnen entfernen. Damit steuert diese Rolle, wer als Teilnehmer in einem Projekt geführt wird, ohne dass sie zwangsläufig auch globale Mitgliederverwaltung besitzen muss.

### Termine verwalten

Mit diesem Recht darf die Rolle Termine anlegen, bearbeiten und löschen, öffentliche Bemerkungen zu Terminen moderieren und die verfügbaren Termin-Typen pflegen. Das macht diese Rolle zur zentralen Ansprechperson für die Terminplanung des Chors.

### Anwesenheit eintragen

Dieses Recht erlaubt der Rolle, Anwesenheiten bei Terminen zu erfassen und nachträglich zu aktualisieren. Es ist unabhängig von der Terminverwaltung nutzbar, sodass z. B. eine Stimmgruppenleitung nur Anwesenheiten pflegen kann, ohne Termine selbst anlegen zu dürfen.

### Eigene Stimmgruppe verwalten

Ist dieses Recht aktiv, darf die Rolle die Anwesenheit und Anmeldungen der eigenen Stimmgruppe verwalten (Stimmvertretung) – unabhängig vom Hierarchie-Level der Rolle. Damit können auch Rollen mit niedrigem Level eingeschränkt Verantwortung für ihre eigene Gruppe übernehmen, ohne Zugriff auf andere Stimmgruppen zu erhalten.

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

### Aufgaben/Planung

Ist dieses Recht aktiv, darf die Rolle Aufgaben verwalten – auch projektübergreifend. Es erscheint nur, wenn das Aufgaben-Modul aktiviert ist, und eignet sich für Personen, die die Projektplanung koordinieren.

### Stammdaten verwalten

Mit diesem Recht darf die Rolle grundlegende Stammdaten der Installation bearbeiten: Projekte, Rollen, Stimmgruppen und App-Einstellungen. Da hierüber auch Rollen selbst verändert werden können, sollte dieses Recht ähnlich sparsam vergeben werden wie die Mitgliederverwaltung.

### Backup-Verwaltung

Dieses Recht erlaubt der Rolle, Datenbank-Backups zu erstellen, herunterzuladen, zu löschen und wiederherzustellen. Da eine Wiederherstellung den gesamten Datenbestand ersetzen kann, ist dieses Recht besonders sensibel und sollte nur technisch verantwortlichen Personen zugewiesen werden.

## Häufige Stolperfallen

- **Level bestimmt Hierarchie, nicht automatisch Rechte** – ein hohes Hierarchie-Level allein schaltet keine Rechte frei. Rechte müssen für jede Rolle einzeln aktiviert werden.
- **Mitgliederverwaltung und Stammdaten sind privilegiert** – wer eines dieser beiden Rechte vergibt, ermöglicht der Rolle indirekt, weitere Rechte an sich selbst oder andere zu vergeben. Vorsicht bei der Vergabe.
- **Modulabhängige Rechte** – Rechte wie Budget, Sponsoring, Notenarchiv, Newsletter oder Aufgaben erscheinen nur, wenn das jeweilige Modul in den App-Einstellungen aktiviert ist.
