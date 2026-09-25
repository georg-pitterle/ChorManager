# Noten aufs Tablet

Wer vom Tablet singt, will die Noten in seiner Noten-App haben und nicht als Stapel
einzelner Downloads. Der Chor-Manager stellt deine Projekte deshalb zusätzlich als
**Ordner im Netz** bereit: Noten-Apps wie forScore oder MobileSheets binden ihn ein und
laden die Noten selbst — ohne Umweg über Browser, Dateimanager und "Teilen".

Die Technik dahinter heißt **WebDAV**. Das ist kein Zusatzprogramm, sondern eine Sprache,
die Betriebssysteme und Noten-Apps von Haus aus sprechen. Du brauchst nur drei Angaben.

> **Berechtigung:** Der Menüpunkt **Bereiche → Downloads** braucht kein besonderes Recht,
> jedes angemeldete Mitglied sieht ihn — und damit auch diesen Ordner. Er zeigt genau die
> Projekte, in denen du selbst Mitglied bist. Fehlt dir ein Projekt, bist du ihm noch
> nicht zugeordnet; das erledigt die Projektleitung.

## Was dich erwartet

Der Ordner ist nach dem gleichen Aufbau gegliedert, den du von der Downloads-Seite kennst:

```
Noten (Wurzel)
├── Frühlingskonzert 2026
│   ├── Ave Verum
│   │   ├── Ave-Verum-Sopran.pdf
│   │   └── Ave-Verum-Uebe-Track.mp3
│   └── Locus Iste
│       └── Locus-Iste.pdf
└── Adventsingen
    └── Maria durch ein Dornwald ging
        └── Dornwald-Satz.pdf
```

Drei Dinge sind wichtig zu wissen:

- **Er ist schreibgeschützt.** Du kannst lesen und kopieren, aber nichts hineinlegen,
  umbenennen oder löschen. Deine Anmerkungen und Fingersätze macht die Noten-App in ihrer
  eigenen Kopie — am Original im Chor-Manager ändert sich nichts.
- **Er bleibt aktuell.** Lädt die Chorleitung eine neue Fassung hoch, taucht sie beim
  nächsten Öffnen von selbst auf. Du musst nichts nachladen.
- **Er ist nicht öffentlich.** Ohne deine Zugangsdaten kommt niemand hinein, auch nicht
  mit der Adresse allein.

## 1. Zugangsdaten holen

Klickpfad: **Bereiche → Downloads**. Ganz oben steht der Abschnitt **Noten aufs Tablet**
mit drei Angaben:

| Angabe | Was dort steht |
|---|---|
| **Adresse** | die Ordner-Adresse, etwa `https://chor.example.org/webdav/` |
| **Benutzername** | deine E-Mail-Adresse, mit der du dich auch anmeldest |
| **Zugangswort** | ein eigenes Wort nur für diesen Ordner — **nicht** dein Passwort |

Das Zugangswort erzeugst du mit **Zugangswort erzeugen**. Es erscheint einmal, lang und
kryptisch (64 Zeichen). Markiere es und kopiere es in die Zwischenablage, bevor du die
Seite verlässt.

> **Es wird nur einmal angezeigt.** Danach lässt es sich nicht mehr hervorholen, nur neu
> erzeugen. Das ist kein Versehen: Der Chor-Manager speichert davon nur eine Prüfsumme, so
> wie er es mit Passwörtern tut. Niemand kann es später auslesen — auch die Verwaltung
> nicht.

Warum überhaupt ein eigenes Wort und nicht das Kontopasswort? Weil es dauerhaft in einer
App auf einem Gerät stehen bleibt, das du verlieren oder weitergeben kannst. Ein eigenes
Wort lässt sich einzeln zurückziehen, ohne dass du dein Passwort ändern und dich überall
neu anmelden musst.

## 2. In der Noten-App einrichten

Der Ablauf ist überall derselbe: In der App eine neue WebDAV-Verbindung anlegen und
Adresse, Benutzername und Zugangswort eintragen.

### forScore (iPad)

1. **Werkzeuge → Dienste** (Zahnrad-Menü) öffnen.
2. Unter **Weitere Dienste** den Eintrag **WebDAV** wählen.
3. **Adresse**, **Benutzername** und **Passwort** eintragen — als Passwort das
   Zugangswort aus Schritt 1.
4. **Fertig**. Die Projekte erscheinen als Ordner; ein Tippen auf eine PDF-Datei lädt sie
   in deine forScore-Bibliothek.

Die Noten liegen danach dauerhaft in forScore und sind auch ohne Netz da.

### MobileSheets (Android, iPad, Windows)

MobileSheets bietet WebDAV nicht unter diesem Namen an, sondern als
**Nextcloud** — der Dialog heißt „Verbinden mit Nextcloud Server". Das ist der
richtige Eintrag; unser Ordner spricht dieselbe Sprache.

1. **Einstellungen → Speicher → Nextcloud** öffnen.
2. Bei **Server-Basis-URL** die Adresse von der Downloads-Seite eintragen, mit
   Schrägstrich am Ende.
3. **Benutzername** ist deine E-Mail-Adresse, **Passwort** das Zugangswort.
4. Speichern, dann im Ordnerbaum das Projekt öffnen und die Dateien importieren.

### Dateien-App (iPhone, iPad)

1. **Durchsuchen** öffnen, oben rechts auf **⋯**, dann **Mit Server verbinden**.
2. Die Adresse eintragen und **Verbinden**.
3. **Registrierter Benutzer** wählen, Benutzername und Zugangswort eintragen.

Der Ordner steht danach in der Seitenleiste und lässt sich aus jeder App heraus öffnen,
die auf die Dateien-App zugreift.

### Windows-Explorer

1. **Dieser PC** öffnen, **Netzlaufwerk verbinden**.
2. Als Ordner die Adresse eintragen und **Verbindung mit anderen Anmeldeinformationen
   herstellen** ankreuzen.
3. Benutzername und Zugangswort eintragen.

Windows verlangt dafür eine verschlüsselte Verbindung (`https://`) — die hat der
Chor-Manager im Normalbetrieb.

### Android, allgemein

Dateimanager mit WebDAV-Unterstützung (etwa der **Solid Explorer** oder **CX Datei
Explorer**) legen eine neue Verbindung vom Typ **WebDAV** an; danach steht der Ordner wie
ein lokaler Speicher zur Verfügung.

## 3. Zugang zurückziehen

Gerät verloren, verkauft oder weitergegeben? Dann auf der Downloads-Seite **Neues
Zugangswort erzeugen**. Das bisherige verliert damit sofort seine Gültigkeit — jedes
Gerät, auf dem es noch steht, kommt nicht mehr an die Noten.

Du kannst immer nur **ein** Zugangswort zugleich haben. Nutzt du mehrere Geräte, trägst du
nach dem Neuerzeugen auf allen das neue ein.

## Häufige Stolperfallen

- **"Anmeldung fehlgeschlagen" oder ständige Passwortabfrage.** In der App steht das
  Kontopasswort statt des Zugangsworts, oder noch ein altes Zugangswort. Neu erzeugen und
  überall eintragen.
- **MobileSheets meldet „WebdavError 6".** Die Zahl sagt nichts Bestimmtes; in aller Regel
  stimmt die Basis-URL nicht. Sie muss genau so lauten wie auf der Downloads-Seite, samt
  `/webdav/` am Ende — hänge nichts an, den Rest des Pfades ergänzt MobileSheets selbst.
- **Die App will etwas speichern und meldet einen Fehler.** Der Ordner ist absichtlich nur
  zum Lesen. Importiere die Noten in die App, statt in den Ordner hineinzuspeichern.
- **Ein Projekt fehlt.** Du bist ihm nicht zugeordnet — es erscheint auch auf der
  Downloads-Seite nicht.
- **Der Ordner ist ganz leer.** Dann bist du in keinem Projekt, dem Lieder zugeordnet
  sind. Siehe [Downloads](repertoire-downloads).
- **Die Datei heißt anders als im Chor-Manager.** Tragen zwei Lieder oder zwei Dateien im
  selben Ordner denselben Namen, hängt der Ordner an den zweiten eine Nummer an
  (`Kyrie (2).pdf`). Anders ließen sie sich nicht auseinanderhalten.
- **Nach dem Ausscheiden aus dem Chor geht nichts mehr.** Sobald ein Mitglied archiviert
  ist, endet auch dieser Zugang — wie jeder andere.
