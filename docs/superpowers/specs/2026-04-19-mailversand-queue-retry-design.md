# Design Spec: Mailversand Queue, Retry und Monitoring

Datum: 2026-04-19
Status: Freigegeben

## 1. Zielbild

Der Mailversand wird fuer alle ausgehenden Mailtypen vereinheitlicht und robust gemacht.
Abgedeckt werden:
- zuverlaessige Zustellverfolgung,
- intelligente Retry-Logik,
- Verwaltungsansicht Mailversand mit Fehlertransparenz,
- neues, separates Berechtigungsrecht,
- Dashboard-Uebersicht fuer nicht zugestellte Mails.

Scope in dieser Ausbaustufe:
- Newsletter-Mails,
- Einladungs-Mails,
- Passwort-Reset-Mails.

Nicht im Scope:
- externe Message-Broker (z.B. RabbitMQ),
- provider-spezifische Webhook-Integrationen,
- Massen-Retry-Optimierung fuer sehr grosse Volumen jenseits normaler Vereinsnutzung.

## 2. Architekturentscheidung

Gewaehlter Ansatz: zentrale mail_queue.

Begruendung:
- Ein einheitliches Daten- und Fehlerbild fuer alle Mailtypen.
- Saubere Trennung von fachlicher Aktion und technischem Versand.
- Direkte Grundlage fuer Monitoring, manuelle Retry-Aktionen und Dashboard-Kennzahlen.
- Bessere Wartbarkeit als mehrere spezialisierte Queue-Loesungen.

## 3. Datenmodell

Es wird eine neue Tabelle mail_queue eingefuehrt.

### 3.1 Geplante Felder

- id (PK)
- mail_type (enum/string): newsletter, invitation, password_reset
- recipient_email (varchar)
- subject (varchar)
- body_html (text/longtext)
- payload_json (json/text): typ-spezifische Referenzen und Metadaten
- status (enum/string): queued, sending, sent, failed, dead
- attempts (int)
- max_attempts (int)
- next_attempt_at (datetime, nullable)
- last_attempt_at (datetime, nullable)
- sent_at (datetime, nullable)
- error_code (varchar, nullable)
- error_message (text, nullable)
- is_retryable (tinyint/bool)
- created_at (datetime)
- updated_at (datetime)

### 3.2 Status-Semantik

- queued: wartet auf Verarbeitung
- sending: aktuell in Verarbeitung (Schutz gegen Doppelversand)
- sent: erfolgreich zugestellt
- failed: letzter Versuch fehlgeschlagen, weiterer Auto-Retry moeglich
- dead: final fehlgeschlagen, kein Auto-Retry mehr

### 3.3 Beziehung zu bestehenden Strukturen

- NewsletterRecipient.status bleibt als fachlicher Status erhalten.
- Queue-Ergebnisse werden bei Newslettern auf NewsletterRecipient synchronisiert, damit vorhandene UI-Flows konsistent bleiben.

## 4. Komponenten und Verantwortlichkeiten

### 4.1 MailQueueService

Verantwortlich fuer das Erzeugen von Queue-Eintraegen aus fachlichen Use-Cases.

- enqueueNewsletterMail(...)
- enqueueInvitationMail(...)
- enqueuePasswordResetMail(...)

### 4.2 MailDeliveryService

Verarbeitet faellige Queue-Eintraege.

- pickFaelligeEintraege(now)
- sendEntry(entry)
- classifyFailure(error)
- applyRetryPolicy(entry, classification)

### 4.3 MailQueueAdminService

Liefert Verwaltungsdaten und Retry-Funktionen.

- listEntries(filter)
- retrySingle(entryId)
- retryAllDead()
- getStats()

### 4.4 Mail Queue Verarbeiter

Der Verarbeiter wird hybrid betrieben:
- periodisch per Cron im DDEV-Container,
- opportunistisch bei Requests (mit Schutzmechanismen).

Die Trigger-Strategie ist ueber App-Einstellungen steuerbar.

## 5. Ablauf / Data Flow

### 5.1 Enqueue

1. Fachlicher Controller stoest Mail an (Newsletter senden, Einladung, Passwort-Reset).
2. Statt Direktversand wird ein Queue-Eintrag mit status queued erstellt.
3. Rueckmeldung im UI ist technisch korrekt (queued/angestossen statt unmittelbar gesendet).

### 5.2 Verarbeitung

1. Verarbeiter liest faellige Eintraege anhand next_attempt_at.
2. Eintrag wird auf sending gesetzt.
3. Bestehender Mailer sendet.
4. Bei Erfolg: sent, sent_at setzen.
5. Bei Fehler: attempts erhoehen, Fehlerdaten speichern, retry-faehig bewerten.
6. Retry-faehig: failed + next_attempt_at mit Backoff.
7. Nicht retry-faehig oder max_attempts erreicht: dead.

### 5.3 Triggering des Verarbeiters

Der Trigger ist hybrid und in App-Einstellungen konfigurierbar:
- Primaermodus: cron, opportunistisch oder hybrid.
- Request-Rate-Limit: begrenzt opportunistische Trigger pro Zeiteinheit.
- Batch-Groesse: maximale Anzahl Queue-Eintraege pro Lauf.

Verarbeitungsschutz:
- Opportunistische Trigger laufen nur, wenn das Rate-Limit es erlaubt.
- Jede Verarbeitung arbeitet mit begrenzter Batch-Groesse, um Request-Latenzen kontrolliert zu halten.

### 5.4 Synchronisation NewsletterRecipient

Nach erfolgreichem oder final fehlgeschlagenem Versand wird der entsprechende NewsletterRecipient-Eintrag aktualisiert.

## 6. Retry-Strategie

Vereinbartes Zielbild: kombiniert.

- Automatisch genau 2 Retries mit Backoff fuer retry-faehige Fehler.
- Danach nur manuell.
- Damit gilt: max_attempts = 3 (1 initialer Versuch + 2 Auto-Retries).

### 6.1 Klassifikation

Permanent (kein Auto-Retry):
- ungueltige Empfaengeradresse,
- strukturell unbrauchbare Konfigurationen,
- sonstige Fehler, die offensichtlich nicht durch Warten verschwinden.

Retry-faehig:
- temporaere SMTP-/Netzwerkfehler,
- zeitweilige Erreichbarkeitsprobleme,
- sonstige transient erwartbare Fehlerbilder.

### 6.2 Manueller Retry

- Einzel-Retry pro Eintrag.
- Global-Retry fuer alle final fehlgeschlagenen Eintraege.

## 7. Berechtigungen

Neues separates Rollenrecht:
- can_manage_mail_queue

Anforderungen:
- Recht ist unabhaengig von can_manage_newsletters.
- Admin erhaelt Recht standardmaessig.
- SessionAuthService setzt Session-Flag.
- RoleMiddleware unterstuetzt Zugriffsschutz fuer neue Mailversand-Verwaltungsrouten.
- Rollenverwaltung (UI + Persistenz) erhaelt Schalter fuer das neue Recht.

## 8. UI und Navigation

### 8.1 App-Einstellungen fuer Queue-Trigger

Neue steuerbare Parameter in App-Einstellungen:
- Mailqueue Trigger-Modus (cron, opportunistisch, hybrid)
- Mailqueue Opportunistic Rate Limit
- Mailqueue Batch-Groesse

### 8.2 Neuer Bereich in Verwaltung

Neuer Verwaltungseintrag:
- Mailversand

Neue Seite mit:
- Filter: Status, Mailtyp, Zeitraum, ggf. Empfaenger/Suche.
- Tabelle mit Queue-Eintraegen und Spalten fuer Status, Versuche, letzter Fehler, naechster Versuch.
- Detailansicht oder erweiterte Zeile fuer Fehlertext.
- Aktionen:
  - Einzel-Retry,
  - Global-Retry fuer alle dead-Eintraege.

### 8.3 Dashboard

Neue Uebersichtskachel/Kennzahl:
- Nicht zugestellte Mails.

Definition (vereinbart):
- Es werden nur final fehlgeschlagene Eintraege (dead) gezaehlt.

Sichtbarkeit:
- nur mit neuem Mailqueue-Recht (bzw. globalen Admin-Rechten).

## 9. Fehlerbehandlung und Sicherheit

- Keine Speicherung von Secrets oder SMTP-Credentials in Queue-Daten.
- Fehlertexte fuer UI auf noetiges Mass begrenzen; technische Detailtiefe in Logs.
- Eingaben fuer Filter/Aktionen serverseitig validieren.
- Retry-Endpunkte gegen unberechtigten Zugriff absichern.
- Schutz gegen Doppelverarbeitung (Statuswechsel queued -> sending atomar/robust).

## 10. Migration und Seed

### 10.1 Migrationen

- Neue Tabelle mail_queue.
- Neue Rollen-Spalte can_manage_mail_queue.
- Rechte-Default fuer Admin-Rolle auf 1 setzen.

### 10.2 Dev Seed

- Rolle Admin mit can_manage_mail_queue = 1.
- Realistische Beispieldaten in mail_queue fuer Development (mindestens je ein sent, failed und dead Eintrag).

## 11. Teststrategie

Pflicht: Feature-Tests fuer neues Verhalten.

### 11.1 Rechte und Routing

- Zugriff auf Mailversand-Seite nur mit neuem Recht.
- Retry-Endpunkte nur mit neuem Recht.

### 11.2 Trigger-Konfiguration

- Verarbeitung laeuft gemaess eingestelltem Trigger-Modus.
- Opportunistische Trigger beachten das konfigurierte Rate-Limit.
- Pro Lauf wird die konfigurierte Batch-Groesse eingehalten.

### 11.3 Queue-Verhalten

- queued -> sent bei erfolgreichem Versand.
- queued -> failed mit naechstem Versuch bei retry-faehigem Fehler.
- queued/failed -> dead bei permanentem Fehler oder Ausschopfen von max_attempts.

### 11.4 Retry-Aktionen

- Einzel-Retry setzt Eintrag korrekt zurueck.
- Global-Retry betrifft nur final fehlgeschlagene Eintraege.

### 11.5 Dashboard

- Zaehler nutzt ausschliesslich dead-Eintraege.
- Sichtbarkeit gemaess Recht.

### 11.6 Newsletter-Konsistenz

- Queue-Ergebnis wird korrekt auf NewsletterRecipient.status gespiegelt.

## 12. Risiken und Gegenmassnahmen

- Risiko: semantische Unterschiede zwischen Mailtypen.
  - Gegenmassnahme: payload_json mit klaren type-spezifischen Feldern und Service-Grenzen.

- Risiko: inkonsistente Status bei Abbruch waehrend sending.
  - Gegenmassnahme: robuste Statusuebergaenge und defensive Wiederaufsetzlogik.

- Risiko: zu viele manuelle Retries ohne Nutzen.
  - Gegenmassnahme: Fehlerklassifikation sichtbar machen und nur sinnvolle Retrys anbieten.

## 13. Akzeptanzkriterien

- Alle drei Mailtypen nutzen denselben Queue-Weg.
- Versandstatus ist fuer jeden Eintrag nachvollziehbar.
- Auto-Retry greift nur bei retry-faehigen Fehlern.
- Verwaltung zeigt Queue inkl. Fehlern und erlaubt Einzel-/Global-Retry.
- Neues Recht schuetzt Zugriff, Admin besitzt es standardmaessig.
- Dashboard zeigt Anzahl final nicht zugestellter Mails.
- Relevante Feature-Tests decken Erfolgspfad und Fehlerpfade ab.
