# Backup als ein Paket: mitnehmen und in einer anderen Installation einspielen

## Absicht

Ein Backup einer Installation soll sich in einer anderen Installation wiederherstellen
lassen (Produktion → Dev, Umzug auf einen neuen Server). Heute geht das nicht:

- Die Dateien der Dateiverwaltung liegen nicht im Backup, sondern im gemeinsamen Pool
  `var/backups/files/`. Kopiert man `.sql.gz`, `.json` und `.files.json` in eine andere
  Installation, scheitert `FileBackupService::verify()` am fehlenden Pool. Kopiert man den
  Pool mit, schleppt man die Dateien aller anderen Backups mit.
- Herunterladen geht nur getrennt (Dump über „Download“, Dateien über „Dateien“), und für
  den Rückweg gibt es nichts.
- `mail_key_id` steht in den Metadaten, wird beim Wiederherstellen aber nicht verglichen.
  Ein Backup aus einer Installation mit anderem `MAIL_CREDENTIAL_KEY` macht die
  verschlüsselten Mail-Zugangsdaten still unbrauchbar.

## Entscheidungen des Entwicklers

- **Intern bleibt der Pool.** Jede Datei liegt im Backup-Ordner genau einmal; es kommen
  künftig deutlich mehr Dateien dazu, Mehrfachablage pro Backup ist nicht gewollt.
- **Zum Mitnehmen gibt es ein Paket:** eine einzige Datei mit Datenbank-Dump, genau den
  Dateien dieses Backups und der Beschreibung.
- **Transport von Hand:** Das Paket wird in den Backup-Ordner der Zielinstallation
  kopiert. Ein Hochladen über die Oberfläche ist nicht Teil dieser Arbeit.
- **Ein Knopf:** „Download“ liefert das Paket. Der Knopf „Dateien“ und die Route
  `/backups/{id}/download-files` fallen weg. Einen Download nur des Dumps gibt es nicht mehr.
- Die Spalte „Dateien“ soll nicht mehr so aussehen, als lägen die Dateien in jedem
  Backup extra.

## Ziele

1. `Download` erzeugt ein Paket `<backupId>.zip` und liefert es aus.
2. Liegt ein solches Paket im Backup-Ordner, zeigt die Backup-Seite das an, und der
   Konsolenbefehl `backup:import` übernimmt es: Die Dateien kommen in
   den Pool, Dump, Metadaten und Manifest liegen danach wie bei einem selbst erstellten
   Backup. Das Backup erscheint in der Liste und lässt sich normal wiederherstellen und
   löschen.
3. Passt `mail_key_id` eines Backups nicht zum aktuellen Schlüssel, sieht man das in der
   Liste, und der Wiederherstellen-Dialog warnt ausdrücklich. Das gilt für jedes Backup,
   nicht nur für übernommene (auch nach einem Schlüsselwechsel in derselben Installation).
4. Die Spalte „Dateien“ macht deutlich, dass die Dateien geteilt sind.

## Nicht-Ziele

- Hochladen eines Pakets über die Oberfläche.
- Die Ablage intern auf Pakete umstellen.
- Liedanhänge aus der Datenbank ins Dateisystem verlagern (der Entwickler geht das
  vielleicht später an; der Dump ist heute ~190 MB, fast nur Lieder).
- Versionsabgleich von Schema/App-Version beim Wiederherstellen. Das Verhalten bleibt wie
  heute.
- Alte Backups umschreiben. Bestehende Backups bleiben wie sie sind; ihr Paket entsteht
  beim Download.

## Anti-Ziele (wäre ein Fehlschlag, auch wenn es technisch läuft)

- Ein Paket wird halb übernommen: Dump liegt da, Dateien fehlen, und das Backup steht
  trotzdem in der Liste.
- Ein Paket mit manipulierten Pfaden schreibt außerhalb des Backup-Ordners oder
  überschreibt fremde Pool-Dateien mit anderem Inhalt.
- Ein vorhandenes Backup wird durch ein Paket mit gleicher Kennung überschrieben.
- Die Liste wird spürbar langsam, weil bei jedem Aufruf Archive gelesen werden.

## Paketformat

ZIP (`ext-zip` ist in `Dockerfile` und ddev vorhanden). Grund gegenüber `.tar.gz`:
`ZipArchive` liest das Inhaltsverzeichnis am Dateiende und einzelne Einträge gezielt;
`PharData` entpackt bei `.tar.gz` beim Öffnen alles. Außerdem lässt sich ein ZIP unter
Windows ohne Werkzeug ansehen.

Inhalt:

```
<backupId>.zip
├── backup.json          # die Metadaten, wie heute <backupId>.json
├── manifest.json        # das Datei-Manifest, wie heute <backupId>.files.json (fehlt bei "nur Datenbank")
├── database.sql.gz      # bzw. database.sql, je nach metadata.gzip
└── files/<storage_path> # genau die Dateien aus manifest.json
```

Der Dump ist bereits gzip-komprimiert und wird im ZIP nur gespeichert (`CM_STORE`), nicht
noch einmal komprimiert. Bei den Dateien entscheidet der Builder.

Dateiname im Ordner: `<backupId>.zip`, wobei `<backupId>` dem bestehenden
`BackupService::ID_PATTERN` entspricht. Andere ZIPs im Ordner werden ignoriert.

## Übernahme eines Pakets

**Ausgelöst ausschließlich über einen Konsolenbefehl `backup:import`** (Entscheidung des
Entwicklers), nach dem Vorbild von `src/Commands/CreateBackupCommand.php` und
`bin/create_backup.php`, also ein eigenes `bin/import_backup.php`, aufgerufen mit
`ddev php bin/import_backup.php` bzw. `php bin/import_backup.php` im
Produktionscontainer. Gründe:

- `BackupService::list()` darf keine Seiteneffekte bekommen. `DevSeedService` (Zeile 364)
  listet beim Reset alle Backups und löscht sie; ein dort abgelegtes Produktions-Paket
  würde sonst übernommen und gleich gelöscht. Auch `create()` und damit der nächtliche
  Lauf rufen `list()` auf.
- Web-Requests laufen in Produktion mit `max_execution_time = 120`; eine Übernahme von
  ~190 MB Dump plus Dateien kann darin abbrechen, ohne aufzuräumen. Die Konsole hat kein
  solches Limit.

Der Befehl übernimmt alle passenden Pakete im Ordner, gibt je Paket Erfolg oder Fehler
aus und endet mit einem Fehler-Exit-Code, wenn mindestens eines scheiterte.

`list()` bzw. die Seite öffnet keine Pakete. Sie sieht nur per Dateinamen nach, ob
`<backupId>.zip` oder `<backupId>.zip.failed` im Ordner liegen, und zeigt dann einen
bleibenden Hinweis: wartende Pakete mit dem Befehl zur Übernahme, gescheiterte mit dem
Hinweis, das Protokoll zu prüfen und die Datei von Hand zu entfernen. Löschen über die
Oberfläche gibt es dafür nicht.

Ablauf je gefundenem `<backupId>.zip`:

1. Kennung aus dem Dateinamen gegen `ID_PATTERN` prüfen.
2. Existiert `<backupId>.json` schon: nichts überschreiben; das Paket gilt als
   gescheitert (siehe unten, wird zu `.zip.failed`, Grund „Kennung existiert bereits“).
3. `backup.json` lesen; `id` darin muss der Kennung aus dem Dateinamen entsprechen.
4. Jeden Eintrag unter `files/` gegen `LocalFileStorage::isValidPath()` prüfen; Einträge
   außerhalb der vier erwarteten Namen bzw. `files/` lassen die Übernahme scheitern.
5. Dump nach `<backupId>.sql.gz` (bzw. `.sql`) entpacken und gegen `sha256` aus
   `backup.json` prüfen.
6. Dateien in den Pool: Liegt der Pfad im Pool schon, muss der sha256 übereinstimmen
   (dann nichts tun), sonst scheitert die Übernahme. Fehlende Dateien werden geschrieben
   und gegen den sha256 aus `manifest.json` geprüft.
7. `<backupId>.files.json` und zuletzt `<backupId>.json` schreiben. Die Metadaten-Datei
   kommt zuletzt, weil `list()` ein Backup erst über sie kennt; bis dahin ist es nicht
   sichtbar.
8. Paket löschen. Log-Event `backup.import.completed`.

Scheitert ein Schritt: Alles bisher Geschriebene dieses Backups wieder entfernen (Dump,
Manifest, nur die in diesem Lauf neu in den Pool gelegten Dateien), Paket umbenennen in
`<backupId>.zip.failed`, damit es nicht bei jedem Seitenaufruf erneut versucht wird,
Log-Event `backup.import.failed` mit `exception`. Der Befehl gibt den Grund aus; die
Seite zeigt den bleibenden Hinweis, solange die `.zip.failed` liegt.

Gleichzeitige Aufrufe: Die Übernahme läuft unter einer exklusiven Sperre (`flock` auf
eine Sperrdatei im Backup-Ordner); ein zweiter Aufruf, der die Sperre nicht bekommt,
bricht mit Meldung ab.

Obergrenze: Ein übernommenes manuelles Backup wird auch dann übernommen, wenn
`maxManual` damit überschritten wird. „Backup erstellen“ ist dann gesperrt, bis eines
gelöscht wird (bestehendes Verhalten von `create()`).

Die übernommenen Metadaten bleiben wie im Paket, insbesondere `type`, `created_at`,
`created_by`, `mail_key_id`. Builder-Entscheidung (siehe unten): ob ein Merker
`imported_at` dazukommt.

Rotation: Ein übernommenes Backup zählt bei seinem `type` mit. Ein übernommenes
automatisches Backup kann also beim nächsten nächtlichen Lauf wegrotiert werden, wenn es
das älteste ist. Das ist so gewollt (Meine Entscheidung, siehe unten).

## Download

`GET /backups/{id}/download` baut das Paket in einer Temp-Datei (wie heute
`FileBackupService::archive()`), liefert es als `application/zip` aus und löscht die
Temp-Datei. Auch das läuft unter `max_execution_time = 120`; der Builder misst mit
realistischer Größe (~190 MB Dump) und entscheidet, ob das Paket gestreamt, das Limit für
diese Route angehoben (`set_time_limit`) oder beides gemacht wird. `getFilesArchive()`, `FileBackupService::archive()`, `downloadFiles()`, die
Route und der Knopf entfallen; ihre Tests werden entsprechend umgestellt.

## Mail-Schlüssel

`BackupService` kennt den aktuellen `mailKeyId` schon. `list()` ergänzt je Eintrag ein
abgeleitetes Feld (z. B. `mail_key_mismatch`), wenn beide Kennungen bekannt sind und sich
unterscheiden. Ist eine der beiden `null`, gilt es nicht als Abweichung.

- Liste: Badge an der Zeile, etwa „anderer Mail-Schlüssel“.
- Wiederherstellen-Dialog: zusätzlicher Warnabsatz, dass gespeicherte Mail-Zugangsdaten
  nach der Wiederherstellung neu eingegeben werden müssen. Der Dialog wird heute über
  `public/js/backups.js` befüllt; die Information kommt per `data-*`-Attribut.
- Kein Blockieren der Wiederherstellung.

## Spalte „Dateien“

Wortlaut ist Sache des Builders; die Aussage muss sein, dass das Backup auf N Dateien
verweist, die im gemeinsamen Pool liegen. Die Speicherübersicht
(`BackupUsageProvider`) zeigt den Pool schon getrennt und bleibt so. `.zip`- und
`.zip.failed`-Dateien im Ordner zählt sie unter dem jeweiligen Backup-Typ (die Kennung
steht im Namen, das bestehende Muster greift).

Der Hinweistext im Seitenkopf („Datenbank-Backups erstellen …“) und der Warnhinweis
werden um den Umzug ergänzt: Paket herunterladen, in den Backup-Ordner der anderen
Installation legen, Seite öffnen.

## Tests (TDD)

Feature-/Unit-Tests mindestens für:

- Download liefert ein ZIP mit `backup.json`, `manifest.json`, Dump und genau den Dateien
  des Manifests; bei „nur Datenbank“ ohne `manifest.json` und `files/`.
- Rundreise: Paket aus Installation A (eigenes Backup-Verzeichnis, eigener Pool) in das
  Verzeichnis von B legen → `list()` zeigt es noch nicht, nur den Hinweis; nach
  `backup:import` zeigt `list()` es, `restore()` spielt Dump und Dateien ein.
- `list()` übernimmt nichts; ein Dev-Reset lässt ein wartendes Paket liegen.
- Übernahme bei erreichter `maxManual`-Grenze gelingt.
- Exit-Code des Befehls bei Erfolg, bei einem gescheiterten Paket, bei belegter Sperre.
- Übernahme dedupliziert: Datei liegt in B schon im Pool mit gleichem Hash → nicht doppelt.
- Fehlerfälle: Kennung schon vorhanden (nichts überschrieben), `id` in `backup.json`
  passt nicht zum Dateinamen, Pfad mit `../` im ZIP, falscher Dump-Hash, falscher
  Datei-Hash, Pool-Konflikt mit anderem Inhalt → jeweils nichts übernommen, Paket wird zu
  `.zip.failed`, keine Reste.
- Fremdes ZIP mit nicht passendem Namen wird ignoriert.
- `mail_key_mismatch` wahr/falsch/`null`-Fälle; Badge und Dialog-Attribut im Template.
- Route `download-files` existiert nicht mehr.

Seed-Daten: Backups sind Dateien, keine Tabellen; `DevSeedBackupCoverageFeatureTest`
prüfen, ob er betroffen ist.

## Dem Builder überlassen

- Klassenschnitt (eigener `BackupPackageService` o. ä. neben `BackupService` und
  `FileBackupService`).
- Kompression der Dateien im ZIP.
- Exakter Wortlaut von Badge, Spalte und Hinweisen.
- Ob eine Übernahme als Erfolgsmeldung auf der Seite erscheint.

## Meine Entscheidungen (Claude), vom Entwickler noch zu prüfen

- Gescheitertes Paket wird zu `.zip.failed` umbenannt statt gelöscht; Aufräumen von
  Hand, bleibender Hinweis auf der Seite.
- Paket mit schon vorhandener Kennung gilt als gescheitert.
- Übernahme auch über die `maxManual`-Grenze hinaus.
- Übernommene Backups behalten ihren `type` und unterliegen damit der normalen Rotation
  und Obergrenze.
- Abweichender Mail-Schlüssel warnt, blockiert aber nicht.
