# Anmeldung an Nextcloud mit dem ChorManager-Konto

ChorManager kann als Anmeldedienst für andere Anwendungen auftreten. Wer in ChorManager
angemeldet ist, kommt damit per Klick in die Nextcloud des Chores — ohne zweites Passwort
und ohne dort ein eigenes Konto pflegen zu müssen. Name, E-Mail-Adresse und die Gruppen
kommen aus ChorManager und bleiben dort gepflegt.

Technisch spricht ChorManager dafür OpenID Connect. In Nextcloud wird dazu einmalig die
offizielle App **user_oidc** installiert; etwas anderes ist dort nicht nötig.

> **Berechtigung:** Dieses Thema richtet sich an die Person, die den Server betreut.
> Die Einrichtung läuft ausschließlich über die Kommandozeile (`ddev php bin/oidc_admin.php`)
> und über die Nextcloud-Verwaltung. In ChorManager selbst gibt es dafür keine Oberfläche
> und kein eigenes Recht — wer keinen Zugriff auf den Server hat, wendet sich an den
> Administrator.

Für Mitglieder ändert sich nach der Einrichtung nur eines: Sie melden sich in Nextcloud
nicht mehr mit einem eigenen Passwort an, sondern über die Schaltfläche
**„Anmelden mit ChorManager"**.

## 1. Modul einschalten

Der Anmeldedienst ist standardmäßig aus. Eingeschaltet wird er über zwei Einträge in der
`.env` des Servers:

```
FEATURE_OIDC=true
OIDC_SIGNING_KEY_SECRET=<base64-Wert, 32 Bytes>
```

Den Wert für `OIDC_SIGNING_KEY_SECRET` erzeugt man einmalig:

```bash
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

Er schützt den privaten Signierschlüssel in der Datenbank. Ohne ihn stellt ChorManager
keine Anmeldung aus — das ist Absicht. Geht der Wert verloren, lässt sich der hinterlegte
Schlüssel nicht mehr öffnen; dann hilft nur ein neuer Schlüssel (siehe Schritt 2) und ein
erneutes Einlesen in Nextcloud.

Dieser Wert ist ein eigenständiges Geheimnis. Er ist **nicht** derselbe wie
`MAIL_CREDENTIAL_KEY` oder `WEBMAIL_SSO_SECRET`.

## 2. Signierschlüssel anlegen

```bash
ddev php bin/oidc_admin.php key:generate
ddev php bin/oidc_admin.php key:list
```

ChorManager unterschreibt jede Anmeldung mit diesem Schlüssel, Nextcloud prüft die
Unterschrift mit dem öffentlichen Gegenstück. Dieses steht unter
`https://<chormanager>/oidc/jwks.json` und wird von Nextcloud selbst abgeholt.

Ein späterer Wechsel läuft über denselben Befehl: `key:generate` legt einen neuen Schlüssel
an und macht ihn zum aktiven. Der bisherige bleibt sichtbar, bis er entfernt wird — sonst
bräche im Augenblick des Wechsels jede Anmeldung, die Nextcloud gerade noch gegen den alten
Schlüssel prüft. Nach ein paar Tagen aufräumen:

```bash
ddev php bin/oidc_admin.php key:prune --grace-days=30
```

## 3. Nextcloud als Anwendung eintragen

Nextcloud muss sich gegenüber ChorManager ausweisen. Dafür wird einmal ein Eintrag
angelegt:

```bash
ddev php bin/oidc_admin.php client:create "Nextcloud" \
  --redirect-uri="https://cloud.beispiel.org/apps/user_oidc/code" \
  --post-logout-uri="https://cloud.beispiel.org/"
```

Die Rücksprungadresse (`--redirect-uri`) muss **auf das Zeichen genau** so lauten wie die,
die Nextcloud später verwendet. ChorManager vergleicht sie vollständig und nicht nur den
Anfang — das verhindert, dass eine Anmeldung an eine fremde Adresse umgeleitet wird.

Der Befehl gibt **Client-ID** und **Client-Secret** aus. Das Secret erscheint genau dieses
eine Mal: Gespeichert ist nur seine Prüfsumme. Wer es verliert, stellt mit
`client:create` ein neues aus, nachschlagen lässt es sich nicht.

Was eingetragen ist, zeigt:

```bash
ddev php bin/oidc_admin.php client:list
```

## 4. Rollen auf Nextcloud-Gruppen abbilden

In Nextcloud steuern Gruppen die Freigaben. Welche ChorManager-Rolle dort als welche Gruppe
ankommt, wird ausdrücklich festgelegt:

```bash
ddev php bin/oidc_admin.php group:list
ddev php bin/oidc_admin.php group:set "Vorstand" vorstand
ddev php bin/oidc_admin.php group:unset "Vorstand"
```

`group:list` zeigt **alle** Rollen, auch die ohne Zuordnung. Das ist der wichtige Teil:

- Nur Rollen **mit** Zuordnung gehen nach Nextcloud.
- Eine Rolle ohne Zuordnung geht gar nicht hinaus. Es gibt bewusst keinen Rückfall auf den
  Rollennamen — sonst zerlegte ein Umbenennen einer Rolle in ChorManager die Freigaben in
  Nextcloud.

Stimmgruppen und die einzelnen Rechte einer Rolle gehen nicht mit hinaus. Nextcloud kennt
keine ChorManager-Rechte, es braucht Gruppen.

Erlaubt sind für den Gruppennamen Buchstaben, Ziffern, Bindestrich, Unterstrich und Punkt.

## 5. Bestehende Nextcloud-Konten zuordnen

Mitglieder, die in Nextcloud schon ein Konto mit Dateien haben, müssen dieses behalten.
Nextcloud erkennt sie an ihrer Benutzer-Kennung, und die trägt man einmal nach:

```bash
ddev php bin/oidc_admin.php user:list-uid
ddev php bin/oidc_admin.php user:set-uid maria@beispiel.org mmusterfrau
ddev php bin/oidc_admin.php user:unset-uid maria@beispiel.org
```

Wer keine eingetragene Kennung hat, bekommt automatisch die feste Form `cm-<Nummer>`. Für
neue Mitglieder ist das der Normalfall und völlig ausreichend — Nextcloud legt das Konto bei
der ersten Anmeldung selbst an.

Eine Kennung kann nur einem Mitglied gehören; ein zweiter Versuch wird abgewiesen.

**Vor der ersten echten Anmeldung eintragen.** Meldet sich jemand an, bevor seine Kennung
hinterlegt ist, entsteht in Nextcloud ein zweites, leeres Konto neben dem alten.

## 6. Provider in Nextcloud anlegen

In Nextcloud unter **Verwaltung → OpenID Connect** die App `user_oidc` einrichten:

| Feld | Wert |
|---|---|
| Identifier | z. B. `ChorManager` |
| Discovery endpoint | `https://<chormanager>/.well-known/openid-configuration` |
| Client ID | aus Schritt 3 |
| Client secret | aus Schritt 3 |
| Scope | `openid profile email groups` |

Zwei Einstellungen dort sind wichtig:

- **„Use unique user ID" ausschalten.** Sonst bildet Nextcloud aus der Kennung noch einen
  eigenen Wert, und die Zuordnung aus Schritt 5 greift nicht.
- **Gruppen-Provisionierung einschalten**, damit die Gruppen aus Schritt 4 ankommen.

## 7. Ausprobieren

1. In Nextcloud abmelden.
2. **„Anmelden mit ChorManager"** wählen.
3. In ChorManager anmelden — der Rücksprung nach Nextcloud passiert von selbst.

Erwartetes Ergebnis: Das Konto existiert mit richtigem Namen, richtiger E-Mail-Adresse und
genau den Gruppen aus `group:list`. Bei einem zugeordneten Bestandskonto darf **kein** neues
Konto entstehen und die vorhandenen Dateien müssen da sein.

Ob ChorManager überhaupt antwortet, lässt sich vorab von jedem Rechner aus prüfen:

```bash
curl https://<chormanager>/.well-known/openid-configuration
curl https://<chormanager>/oidc/jwks.json
```

## Häufige Stolperfallen

- **Zweites, leeres Konto in Nextcloud.** Die Kennung wurde nicht vor der ersten Anmeldung
  eingetragen (Schritt 5), oder „Use unique user ID" ist in Nextcloud noch an.
- **Niemand landet in einer Gruppe.** Entweder ist in Nextcloud die Gruppen-Provisionierung
  aus, oder die Rollen haben keine Zuordnung — `group:list` zeigt das sofort.
- **Client-Secret verlegt.** Es lässt sich nicht nachschlagen, nur neu ausstellen. Danach
  muss es auch in Nextcloud neu eingetragen werden.
- **Nextcloud meldet `temporarily_unavailable`.** Dann fehlt der Signierschlüssel: Entweder
  ist `OIDC_SIGNING_KEY_SECRET` nicht gesetzt, oder `key:generate` wurde nie ausgeführt.
  ChorManager stellt in dem Fall bewusst gar keinen Code aus, statt die Anmeldung
  durchlaufen zu lassen und erst beim Tausch abzubrechen. Im Server-Log steht
  `oidc.signing_key.unavailable`.
- **Anmeldung schlägt mit einer unscharfen Meldung fehl.** Nach außen gibt ChorManager nur
  die knappen Standardfehler aus; woran es lag, steht im Server-Log unter den Einträgen
  `oidc.authorize.denied` und `oidc.token.rejected`.
- **Rücksprungadresse stimmt nicht.** Sie muss auf das Zeichen genau mit der übereinstimmen,
  die in `client:list` steht — ein fehlender oder zusätzlicher Schrägstrich am Ende genügt
  für eine Abweisung.
- **Gesperrtes Mitglied ist noch in Nextcloud unterwegs.** Wer archiviert oder global
  abgemeldet wird, kommt sofort nicht mehr neu hinein. Eine bereits offene
  Nextcloud-Sitzung endet aber erst nach deren eigener Sitzungsdauer. Soll jemand sofort
  draußen sein, das Konto zusätzlich in Nextcloud deaktivieren.
