# Ordner freigeben

Freigaben legen fest, wer einen Ordner sieht und was er darin tun darf.

> **Berechtigung:** Freigaben ändern darf, wer im Ordner die Stufe **Verwalten** hat oder das Recht **"Dateiverwaltung verwalten"**. Neue Teamordner legt nur an, wer **"Dateiverwaltung verwalten"** hat.

## 1. Teamordner anlegen

Klickpfad: **Bereiche → Dateien → Teamordner anlegen**. Die Schaltfläche erscheint nur mit dem Recht **"Dateiverwaltung verwalten"**.

Gib einen Namen und optional ein **Kontingent** in MB an. Ohne Kontingent ist der Ordner nur durch den Gesamtspeicher begrenzt. Nach dem Anlegen landest du direkt im neuen Ordner – dort legst du als Nächstes die Freigaben fest. Bis dahin sieht ihn niemand außer der Verwaltung.

![Teamordner anlegen](images/files/04-create-root.png)

## 2. Freigaben setzen

Klickpfad: **Ordner → Freigaben** auf der Ordnerseite.

Jede Zeile besteht aus einem **Ziel** und einer **Stufe**. Als Ziel stehen zur Wahl:

- **Alle Mitglieder**
- eine **Rolle**
- eine **Stimmgruppe**
- die **Mitglieder eines Projekts**
- ein **einzelnes Mitglied**

Mit **Freigabe hinzufügen** kommt eine Zeile dazu, das **×** entfernt sie. **Speichern** ersetzt alle bisherigen Freigaben des Ordners.

Oben im Fenster siehst du unter **Geerbt**, welche Freigaben aus übergeordneten Ordnern ohnehin gelten.

![Freigaben eines Ordners](images/files/05-shares.png)

## 3. Beispiele

| Ordner | Freigabe |
| --- | --- |
| Noten & Übe-Material | Alle Mitglieder: Lesen – Rolle der Chorleitung: Bearbeiten |
| Noten & Übe-Material / Sopran | Stimmgruppe Sopran: Hochladen |
| Vorstand | Rolle des Vorstands: Verwalten |
| Projekt Frühjahrskonzert | Mitglieder des Projekts: Hochladen |

## 4. Kontingent ändern

Klickpfad: **Ordner → Kontingent** auf der Seite eines Teamordners (nur mit **"Dateiverwaltung verwalten"**). Ein leeres Feld hebt das Kontingent auf.

## Häufige Stolperfallen

- **Du kannst dich nicht selbst aussperren.** Würden die neuen Freigaben dir die Stufe Verwalten nehmen, lehnt die Anwendung das Speichern ab.
- **Entziehen geht nur oben.** Soll eine Gruppe einen Unterordner nicht sehen, darf sie auch den übergeordneten Ordner nicht sehen. Lege solche Unterlagen in einen eigenen Teamordner.
- **Projektfreigaben wandern mit.** Wer neu ins Projekt kommt, sieht den Ordner sofort; wer das Projekt verlässt, verliert den Zugriff.
