# Design: Stimmvertretung – Konsistente Projektmitgliederverwaltung

**Datum:** 2026-05-02  
**Status:** Freigegeben  

---

## 1. Zielbeschreibung

Stimmvertretungen sollen Mitglieder zu Projekten hinzufügen und daraus entfernen dürfen, sofern sie selbst an diesen Projekten teilnehmen. Beim Hinzufügen sollen alle Mitglieder als Kandidaten sichtbar sein. Der gesamte Workflow soll konsistent und ohne Rechte-Sackgassen nutzbar sein.

---

## 2. Aktuelle Inkonsistenzen

### 2.1 Keine projektbezogene Ownership-Prüfung
Die Projekt-Mitglieder-Routen sind per Rollenflag (`can_manage_project_members`) geschützt, aber die Controller prüfen nicht, ob die Stimmvertretung selbst im Zielprojekt ist. Ein Flag-Träger kann heute theoretisch jedes Projekt öffnen.

**Betroffene Stellen:**
- `src/Routes.php` – Route-Gruppe `/projects/{id}/members` mit `RoleMiddleware(false, 0, false, true)`
- `src/Controllers/ProjectController.php` – `showMembers`, `addMember`, `removeMember`

### 2.2 Kein dedizierter Einstieg
Heute führt der Weg zu den Mitgliederseiten über die allgemeine Projektliste (`/projects`), die an das Stammdaten-Recht `can_manage_master_data` gebunden ist. Stimmvertretungen haben dieses Recht nicht.

### 2.3 User-Edit-Projektzuordnung ohne Scope
Im Mitglied-Bearbeiten-Dialog kann ein Benutzer mit `can_manage_project_members` Projektzuordnungen global setzen, nicht nur für eigene Projekte.

**Betroffene Stelle:**
- `src/Controllers/UserController.php` – `update()`, Zeile `if ($canEditGlobal || $canManageProjectMembers)`

---

## 3. Designentscheidungen

### 3.1 Ansatz: Zentrale Projekt-Scope-Policy

Eine neue Klasse `ProjectMemberPolicy` kapselt alle Entscheidungen zur Projektmitgliederverwaltung. Controller rufen nur noch diese Policy auf, nicht das Session-Flag direkt.

**Policy-Entscheidungen:**
- `canViewMembers(int $projectId): bool`
- `canAddMember(int $projectId): bool`
- `canRemoveMember(int $projectId): bool`
- `canViewAllCandidates(int $projectId): bool`
- `getAccessibleProjectIds(): array`

**Regellogik:**
- Admin / `can_manage_users`: alle Projekte erlaubt.
- `can_manage_project_members` (ohne globales Admin): nur Projekte, in denen der User selbst eingetragen ist (`project_users`).
- Alle anderen: kein Zugriff.

### 3.2 Bestehende Rollen-Guard bleibt erste Schranke
`RoleMiddleware` mit `requiresProjectMemberManagement = true` filtert weiterhin auf Route-Ebene. Die Policy ist eine zweite, projektspezifische Schranke im Controller.

### 3.3 Dedizierter Workflow-Einstieg
Neue Route und neue Controller-Action für die Projektauswahl-Übersicht:
- Route: `GET /projects/members` (geschützt durch dasselbe Rollenflag)
- Stimmvertretung sieht ausschließlich eigene Projekte.
- Admin/global sieht alle Projekte.
- Jede Zeile verlinkt auf die bestehende Detailseite `/projects/{id}/members`.
- Neuer Navigations-Eintrag unter „Bereiche".

---

## 4. Verhalten im Detail

### 4.1 Seite `/projects/members` (Projektauswahl)
- Policy ermittelt `getAccessibleProjectIds()` und liefert dem Template nur diese Projekte.
- Keine Projekt-Stammdatenverwaltung auf dieser Seite (reiner Einstieg).

### 4.2 Seite `/projects/{id}/members` (Detailverwaltung)
- `showMembers`: Policy prüft `canViewMembers($id)`, sonst 403.
- `addMember`: Policy prüft `canAddMember($id)`, sonst 403.
  - Kandidatenliste: `canViewAllCandidates($id)` → alle aktiven User; sonst nur eigene Stimmgruppe (Fallback, theoretisch nicht erreichbar für Rollenflag-Träger).
- `removeMember`: Policy prüft `canRemoveMember($id)`, sonst 403.
- Der Link „Zurück zu Projekten" wird aus der Template-Seite entfernt; Stimmvertretungen haben keinen Zugriff auf `/projects` (Stammdaten-Recht erforderlich). Der Rückweg erfolgt über die Browser-Navigation oder den neuen Einstieg `/projects/members`.

### 4.3 User-Edit-Projektzuordnung (`/users`, Bearbeiten-Modal)
- Nur noch Projekte aus `getAccessibleProjectIds()` sind für Stimmvertretungen als Zuordnungsziel zulässig.
- Fremde Projekte werden bei der Speicherung ignoriert (kein Fehler, kein 403, stille Filterung ist hier akzeptabel).

---

## 5. Sicherheits- und Fehlerverhalten

- Projektzugriff außerhalb Scope: immer **HTTP 403**, keine stillen Fallbacks.
- Kandidatenlisten-Erweiterung gilt ausschließlich innerhalb eines autorisierten Projekts.
- Downloads bleiben unverändert auf projektzuordnungsbasierter Logik (`project_users`); kein Eingriff.

---

## 6. Navigation

Neuer Eintrag in `templates/partials/navigation/areas.twig`:
- Sichtbar wenn `session.can_manage_project_members` (und nicht `session.can_manage_master_data`, da letztere bereits die volle Projektliste haben).
- Alternativ: Eintrag immer zeigen wenn Flag gesetzt; Admin kommt über die volle Projektliste und braucht den neuen Eintrag ebenfalls nicht zu sehen.

**Entscheidung:** Eintrag zeigen für alle Nutzer mit `can_manage_project_members` die *kein* `can_manage_master_data` haben (reine Stimmvertretungen).

---

## 7. Teststrategie

### 7.1 Feature-Tests: Projektmitglieder-Endpunkte
| Szenario                                           | Erwartetes Ergebnis |
| -------------------------------------------------- | ------------------- |
| Stimmvertretung + eigenes Projekt, showMembers     | 200                 |
| Stimmvertretung + eigenes Projekt, addMember       | Erfolg              |
| Stimmvertretung + eigenes Projekt, removeMember    | Erfolg              |
| Stimmvertretung + eigenes Projekt, Kandidatenliste | alle aktiven User   |
| Stimmvertretung + fremdes Projekt, showMembers     | 403                 |
| Stimmvertretung + fremdes Projekt, addMember       | 403                 |
| Stimmvertretung + fremdes Projekt, removeMember    | 403                 |
| Admin, beliebiges Projekt                          | 200 / Erfolg        |

### 7.2 Feature-Tests: User-Edit-Projektzuordnung
| Szenario                                             | Erwartetes Ergebnis      |
| ---------------------------------------------------- | ------------------------ |
| Stimmvertretung versucht, fremdes Projekt zuzuordnen | Zuweisung wird ignoriert |
| Admin ordnet beliebiges Projekt zu                   | Zuweisung gespeichert    |

### 7.3 Regressionstests
- Downloads unverändert projektzuordnungsbasiert.
- Bestehende Rollen mit globalem `can_manage_users` behalten Vollzugriff.
- Bestehende Feature-Tests für Finanz- und Rollenlogik bleiben grün.

---

## 8. Nicht-Ziele

- Keine Änderung der Download-Zugriffslogik.
- Keine Erweiterung der Auswertungsseiten um Bearbeitungsfunktionen.
- Keine allgemeine Freischaltung der Stammdaten-Projektverwaltung für Stimmvertretungen.
- Kein neues Datenbankschema; `project_users` und die vorhandenen Rollenspalten bleiben unverändert.
