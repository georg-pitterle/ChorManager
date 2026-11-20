# Rollen und Rechte

Über **Rollen** legst du fest, was Mitglieder in deiner Installation sehen und tun dürfen. Jede Rolle hat einen Namen, ein Hierarchie-Level (0–100) und eine Reihe von Einzelrechten, die du unabhängig voneinander ein- oder ausschalten kannst. Ein Mitglied kann mehrere Rollen haben – es erhält dann die Vereinigung aller zugehörigen Rechte.

> **Berechtigung:** Dieses Modul ist nur sichtbar, wenn deine Rolle das Recht **"Rollen verwalten"** hat.
> Siehst du den Menüpunkt nicht, frag den Administrator unter **Administration → Rollen & Rechte**.

## 1. Rollenübersicht

Unter **Administration → Rollen & Rechte** stehen links alle Rollen deiner Installation, jeweils mit Hierarchie-Level, Zahl der aktiven Mitglieder und wie viele der Rechte sie hat (etwa „16/24 Rechte"). Auf dem Handy wählst du die Rolle stattdessen aus einer Auswahlliste.

Rechts siehst du die Rechte der gewählten Rolle, nach Bereichen gruppiert. Ein grüner Haken heißt: Die Rolle hat dieses Recht, ein grauer Punkt: Sie hat es nicht. Neben jedem Recht steht, wie viele Rollen es insgesamt haben. Über **Bearbeiten** im Kopf der Rolle änderst du sie. Mit dem Feld **Recht suchen** blendest du alle Rechte aus, die nicht zum Suchbegriff passen.

![Rollenübersicht: links die Rollen, rechts die Rechte der gewählten Rolle](images/roles/01-role-overview.png)

### Wer hat ein bestimmtes Recht?

Klickst du auf ein Recht, erscheinen darunter die Rollen, die es haben. Links steht dann **„Wer hat … ?"**, und jede dieser Rollen trägt einen Haken. Ein Klick auf einen Rollennamen wechselt zu dieser Rolle.

![Klick auf "Budget verwalten" zeigt die Rollen mit diesem Recht](images/roles/02-permission-holders.png)

## 2. Neue Rolle anlegen

Über den Button **"Neue Rolle"** öffnest du ein Formular, in dem du einen Rollennamen, ein Hierarchie-Level sowie die gewünschten Rechte per Schalter aktivierst. Höhere Hierarchie-Level stehen typischerweise für Leitungsfunktionen, während ein einfaches Mitglied oft Level 0 hat.

Das Hierarchie-Level vergibt **keine** Rechte. Seine Hauptaufgabe: Wer ein Mitglied bearbeiten oder ihm Rollen zuweisen will, kommt an niemanden heran, der eine höher eingestufte Rolle besitzt – und kann auch keine Rolle oberhalb des eigenen Levels anlegen, bearbeiten oder vergeben.

Das höchste Level **100** hat zwei Besonderheiten: Wer es hält, darf jedes Recht vergeben, auch eines, das die eigene Rolle nicht trägt. Und er kann die Bearbeitungssperre eines Newsletter-Entwurfs aufheben, den jemand anderes offen gelassen hat (siehe [Newsletter schreiben und versenden](newsletter-compose)).

![Formular zum Anlegen einer neuen Rolle](images/roles/03-new-role-modal.png)

## 3. Rolle bearbeiten

Wähle links die Rolle und klicke rechts im Kopf auf **"Bearbeiten"**. Es öffnet sich dasselbe Formular, vorausgefüllt mit der bestehenden Rolle. Hier kannst du Name, Hierarchie-Level und alle Einzelrechte jederzeit anpassen – Änderungen wirken sich sofort auf alle Mitglieder mit dieser Rolle aus.

![Formular zum Bearbeiten einer bestehenden Rolle](images/roles/04-edit-role-modal.png)

## 4. Rolle löschen

Der Button **"Löschen"** steht neben **"Bearbeiten"** im Kopf der Rolle. Er erscheint nur bei Rollen, denen aktuell kein einziges Mitglied zugewiesen ist – archivierte Mitglieder zählen dabei mit, damit nach einer Wiederherstellung niemand ohne Rolle dasteht. Ist die Rolle noch vergeben, entziehe sie zuerst in der Mitgliederverwaltung. Auch beim Löschen gilt die Level-Grenze: Rollen oberhalb des eigenen Hierarchie-Levels lassen sich nicht entfernen.

![Sicherheitsabfrage vor dem Löschen einer Rolle](images/roles/05-delete-role-modal.png)

## Die einzelnen Rechte im Detail

### Mitgliederverwaltung erlauben

Dieses Recht schaltet die Mitgliederverwaltung frei: Mitglieder anlegen, bearbeiten, einladen und archivieren sowie ihnen Rollen zuweisen. Zuweisbar sind dabei nur Rollen bis zum eigenen Hierarchie-Level, und Mitglieder mit einer höher eingestuften Rolle bleiben unantastbar. Es gilt bewusst nur für diesen Bereich – Rollen selbst zu bearbeiten, Projekte, Termine, Finanzen und alle anderen Module verlangen ihr jeweils eigenes Recht.

### Rollen verwalten

Mit diesem Recht darf die Rolle neue Rollen anlegen und bestehende Rollen bearbeiten. Wer Rollen bearbeiten darf, kann jedes Recht der Installation vergeben – auch an die eigene Rolle und damit an sich selbst. Es ist deshalb das mächtigste Recht überhaupt und sollte nur an wenige, ausdrücklich vertrauenswürdige Rollen gehen. Die einzige Grenze ist das Hierarchie-Level: Rollen oberhalb des eigenen Levels lassen sich weder anlegen noch bearbeiten.

Das Zuweisen von Rollen an Mitglieder gehört dagegen zur **"Mitgliederverwaltung erlauben"** – beide Rechte sind unabhängig voneinander vergebbar.

### Mitglieder editieren erlauben

Mit diesem Recht darf die Rolle die Zuweisungen anderer Mitglieder bearbeiten, also z. B. Stammdaten, Rollen- oder Stimmgruppenzuordnungen anderer Personen ändern. Es ist schwächer als die Mitgliederverwaltung, weil es keinen Zugriff auf globale Einstellungen wie Rollen oder Projekte selbst gibt, sondern nur auf die Bearbeitung einzelner Mitgliederdaten.

### Projektmitglieder verwalten

Ist dieses Recht aktiv, darf die Rolle Mitglieder einzelnen Projekten (z. B. einem Konzertprojekt) zuordnen oder aus ihnen entfernen. Damit steuert diese Rolle, wer als Teilnehmer in einem Projekt geführt wird, ohne dass sie zwangsläufig auch globale Mitgliederverwaltung besitzen muss. Das Recht gilt für alle Projekte – auch für solche, in denen die Rolle selbst nicht mitwirkt. Das ist nötig, damit ein frisch angelegtes Projekt, das noch gar keine Mitglieder hat, überhaupt besetzt werden kann.

Das Recht endet aber bei der Projektzuordnung: Öffnet die Rolle ein Mitglied aus der Mitgliederliste, zeigt das Formular ausschließlich die Projekte. Name, E-Mail-Adresse, Rollen und Stimmgruppen bleiben unangetastet – dafür braucht es **„Mitglieder editieren erlauben"** oder die auf die eigene Stimmgruppe beschränkte Mitgliederpflege.

### Eigene Stimmgruppe ins Projekt zuweisen

Dieses Recht ist die auf die eigene Stimmgruppe beschränkte Variante der Projektmitglieder-Verwaltung. Ist es aktiv, darf die Rolle ausschließlich Mitglieder ihrer eigenen Stimmgruppe den Projekten zuordnen oder daraus entfernen, in denen sie selbst mitwirkt. Die Auswahlliste zeigt dabei nur Personen der eigenen Stimmgruppe – fremde Stimmgruppen bleiben unsichtbar und können weder hinzugefügt noch entfernt werden. Es eignet sich für Personen, die die Besetzung ihrer eigenen Stimmgruppe im Projekt pflegen sollen, ohne Zugriff auf den gesamten Chor zu erhalten. Wer die volle „Projektmitglieder verwalten"-Berechtigung besitzt, braucht dieses Recht nicht zusätzlich.

### Termine verwalten

Mit diesem Recht darf die Rolle Termine anlegen, bearbeiten und löschen, fremde öffentliche Bemerkungen zu Terminen moderieren und die verfügbaren Termin-Typen pflegen. Die eigene Bemerkung darf jedes Mitglied ohne dieses Recht selbst bearbeiten und löschen. Das macht diese Rolle zur zentralen Ansprechperson für die Terminplanung des Chors.

### Anwesenheit/Anmeldung verwalten (alle Mitglieder)

Dieses Recht erweitert **„Eigene Stimmgruppe verwalten"**: Die Rolle sieht und pflegt Anwesenheiten und Anmeldungen für alle Mitglieder und für jeden Termin, nicht nur für die eigene Stimmgruppe. Es eignet sich für Personen mit Überblick über den gesamten Chor, z. B. für die Terminkoordination.

### Eigene Stimmgruppe verwalten

Ist dieses Recht aktiv, darf die Rolle die Mitglieder der eigenen Stimmgruppe pflegen (anlegen, bearbeiten, archivieren und wieder herstellen), für sie Anmeldungen als Vertretung eintragen und ihre Anwesenheitsliste führen – unabhängig vom Hierarchie-Level der Rolle. Damit können auch Rollen mit niedrigem Level Verantwortung für ihre eigene Gruppe übernehmen, ohne Zugriff auf andere Stimmgruppen zu erhalten.

Sichtbar sind dabei nur Termine, zu deren Zielgruppe man selbst gehört oder in denen mindestens ein Mitglied der eigenen Stimmgruppe eingeladen ist – Termine ganz anderer Gruppen tauchen weder in der Anwesenheits- noch in der Anmeldeliste auf.

Zwei Dinge bleiben bewusst ausgenommen: Die **E-Mail-Adresse** eines fremden Kontos lässt sich damit nicht ändern (das Feld wird nur lesend angezeigt), und die **Einladungs-E-Mail** lässt sich nicht auslösen. Beides zusammen ergäbe einen Weg, ein fremdes Konto zu übernehmen: Adresse umbiegen, Einladung darauf schicken, Passwort neu setzen. Wer das braucht, benötigt **„Mitgliederverwaltung erlauben"** bzw. **„Mitglieder editieren erlauben"**. Dieselbe Grenze gilt schon für **„Projektmitglieder verwalten"**.

### Stimmgruppen-Listen führen

Mit diesem Recht darf die Rolle für ihre eigene Stimmgruppe Listen mit eigenen Fragen führen, etwa „Mitgliedsbeitrag bezahlt" oder „Noten zurückgegeben". Der Menüpunkt **Stimmgruppen-Listen** erscheint nur mit diesem Recht. Die Mitglieder selbst sehen diese Listen nicht. Siehe [Stimmgruppen-Listen](voice-group-lists).

### Finanzen nur lesen

Mit diesem Recht darf die Rolle das Finanzmodul einsehen, Auswertungen ansehen und Anhänge zu Finanzeinträgen öffnen – jedoch nichts verändern. Es eignet sich für Personen, die einen Überblick über die Finanzlage benötigen, ohne selbst buchen zu dürfen.

### Finanzen lesen und schreiben

Dieses Recht erweitert das reine Leserecht und schließt es ein: Die Rolle darf Finanzeinträge und die zugehörigen Konfigurationen anlegen, bearbeiten und löschen. **„Finanzen nur lesen"** gilt damit automatisch mit, die Übersicht zeigt es auch so an. Das Recht ist für Personen gedacht, die die Finanzbuchhaltung des Chors führen.

Ist das Sponsoring-Modul aktiv und hat die Rolle kein Sponsoring-Recht, erscheint zusätzlich der Menüpunkt **Sponsoring-Zahlungen** mit den Zusagen, bei denen das Geld noch nicht eingegangen ist.

### Budget verwalten

Ist dieses Recht aktiv, darf die Rolle den Budget-Bereich einsehen und bearbeiten, also Budgetkategorien und -posten pflegen. Es ist unabhängig von den Finanzrechten und erscheint nur, wenn das Budget-Modul in den Einstellungen aktiviert ist.

### Sponsoring verwalten

Mit diesem Recht darf die Rolle das Sponsoring-Modul sehen sowie Sponsoren, Anfragen und Kontakte verwalten. Es schließt **„Eigene Sponsoring-Anfragen erfassen"** ein. Es erscheint nur, wenn das Sponsoring-Modul für die Installation aktiviert ist, und richtet sich an Personen, die Sponsorenbeziehungen pflegen.

### Eigene Sponsoring-Anfragen erfassen

Die schwächere Variante für Mitglieder, die selbst Firmen ansprechen: Die Rolle sieht das Sponsoring-Modul, erkennt, welche Firmen bereits angefragt sind und wie es dort steht, und darf eigene Anfragen anlegen und bearbeiten. Beträge, Vertragsdateien und Gesprächsnotizen fremder Anfragen bleiben verborgen, ebenso das Löschen von Sponsoren und die Paketverwaltung. Auf der Übersicht erscheinen nur die eigenen Erinnerungen und Kontakte. Auch dieses Recht erscheint nur bei aktivem Sponsoring-Modul. Siehe [Sponsoring](sponsoring).

### Repertoire verwalten

Dieses Recht erlaubt der Rolle, Lieder und die zugehörigen Dateien pro Projekt zu verwalten – also z. B. Noten oder Audiodateien hochzuladen und Liedlisten zu pflegen. Es betrifft das Kernrepertoire, unabhängig vom separaten Notenarchiv.

### Notenarchiv verwalten

Ist dieses Recht aktiv, darf die Rolle den Notenarchiv-Bereich innerhalb des Repertoires einsehen und bearbeiten. Es ist ein eigenständiges Recht neben der Repertoireverwaltung und erscheint nur, wenn das Notenarchiv-Modul aktiviert ist – so kann z. B. eine Notenwartin das Archiv pflegen, ohne automatisch auch die Liedlisten der Projekte verwalten zu dürfen.

### Newsletter verwalten

Mit diesem Recht darf die Rolle Newsletter erstellen, bearbeiten und verschicken. Es erscheint nur, wenn das Newsletter-Modul aktiviert ist, und eignet sich für Personen, die die Kommunikation nach außen bzw. an die Mitglieder verantworten.

### Umfragen verwalten

Mit diesem Recht darf die Rolle Umfragen anlegen, bearbeiten, beenden und löschen und sieht alle Umfragen, auch solche, zu deren Zielgruppe sie nicht gehört. Bei namentlichen Umfragen sieht sie jederzeit den Zwischenstand, bei anonymen erst nach dem Fristende. Abstimmen darf dagegen jedes Mitglied der Zielgruppe ohne besonderes Recht. Es erscheint nur, wenn das Umfragen-Modul aktiviert ist. Siehe [Umfragen verwalten](polls-manage).

### Dateiverwaltung verwalten

Mit diesem Recht darf die Rolle in der Dateiablage Teamordner anlegen, Kontingente festlegen und alle Freigaben ändern. Es erscheint nur, wenn das Datei-Modul aktiviert ist. Siehe [Dateien](files).

### Mailversand verwalten

Dieses Recht gibt der Rolle Einblick in die Mail-Queue des Systems und erlaubt es, fehlgeschlagene Zustellungen (Dead-Letter) erneut zu versenden. Es richtet sich an technisch verantwortliche Personen, die den E-Mail-Versand der Installation überwachen.

### Projektplanung (Aufgaben)

Ist dieses Recht aktiv, darf die Rolle Aufgaben verwalten – auch projektübergreifend. Es erscheint nur, wenn das Aufgaben-Modul aktiviert ist, und eignet sich für Personen, die die Projektplanung koordinieren.

### Stammdaten verwalten

Mit diesem Recht darf die Rolle grundlegende Stammdaten der Installation ansehen und speichern: Projekte, Stimmgruppen und App-Einstellungen. Mehr nicht – es schaltet keine Mitglieder-, Rollen- oder Modulrechte zusätzlich frei. Da hierüber auch App-Einstellungen verändert werden können, sollte es trotzdem sparsam vergeben werden.

### Backup-Verwaltung

Dieses Recht erlaubt der Rolle, Datenbank-Backups zu erstellen, herunterzuladen, zu löschen und wiederherzustellen. Da eine Wiederherstellung den gesamten Datenbestand ersetzen kann, ist dieses Recht besonders sensibel und sollte nur technisch verantwortlichen Personen zugewiesen werden.

### Speicherplatz-Verwaltung

Mit diesem Recht sieht die Rolle unter **Administration → Speicherplatz** die Übersicht über den belegten Speicherplatz der Installation. Der Menüpunkt erscheint nur mit diesem Recht.

## Häufige Stolperfallen

- **Level bestimmt Hierarchie, nicht Rechte** – ein hohes Hierarchie-Level allein schaltet nichts frei. Jedes Recht muss für jede Rolle einzeln aktiviert werden; das Level schützt nur höher eingestufte Mitglieder und Rollen vor Änderungen von unten.
- **"Rollen verwalten" ist das mächtigste Recht** – wer es besitzt, kann sich über die eigene Rolle jedes andere Recht selbst geben. Sparsam vergeben und regelmäßig in der Übersicht prüfen, welche Rollen es haben (Klick auf das Recht).
- **Fast kein Recht schaltet ein anderes mit frei** – Mitgliederverwaltung öffnet keine Finanzen, Stammdaten öffnen keine Module. Fehlt einer Rolle etwas, muss genau dieses Recht angehakt werden. Die zwei Ausnahmen: **„Finanzen lesen und schreiben"** schließt **„Finanzen nur lesen"** ein, und **„Sponsoring verwalten"** schließt **„Eigene Sponsoring-Anfragen erfassen"** ein.
- **Modulabhängige Rechte** – Rechte wie Budget, Sponsoring, Notenarchiv, Newsletter, Umfragen, Dateiverwaltung oder Aufgaben erscheinen nur, wenn das jeweilige Modul in den App-Einstellungen aktiviert ist.
